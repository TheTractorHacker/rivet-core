package mesh

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// The Linux install follows MeshCentral's own script (GET <mesh>/meshagents?script=1):
// binary from meshagents?id=<machine id> (no meshid), settings from
// meshsettings?id=<meshid>, StartupType=<n> added to the .msh, then
// `./meshagent -fullinstall --copy-msh=1`. The exit code is NOT trusted: the
// install is reported only after the files, the service and (systemd) its
// active state are verified.

const (
	// MaxSettings caps the downloaded .msh (a few hundred bytes in practice).
	MaxSettings = 1 << 20
	// LinuxInstallDir is where `-fullinstall` puts the agent.
	LinuxInstallDir = "/usr/local/mesh_services/meshagent"
)

// LinuxInstallArgs are what MeshCentral's script runs (a payload's linux install_args are ignored:
// the server stamps the legacy `-install` into every payload and that never installs a service).
var LinuxInstallArgs = []string{"-fullinstall", "--copy-msh=1"}

// SettingsURL is `<mesh>/meshsettings?id=<meshid>` (the group id; never log it).
func (s *Spec) SettingsURL() string {
	return strings.TrimRight(s.URL, "/") + "/meshsettings?id=" + s.WireMeshID()
}

// BinaryURL is `<mesh>/meshagents?id=<N>` (the Linux binary; no group id).
func (s *Spec) BinaryURL(id int) string {
	return strings.TrimRight(s.URL, "/") + "/meshagents?id=" + strconv.Itoa(id)
}

// DetectStartupType ports CheckStartupType of MeshCentral's script (Linux part):
// 1 systemd, 2 upstart, 3 init.d, 0 unknown.
func DetectStartupType() int {
	name := ""
	if b, err := os.ReadFile("/proc/1/status"); err == nil {
		for _, l := range strings.Split(string(b), "\n") {
			if f := strings.Fields(l); len(f) >= 2 && f[0] == "Name:" {
				name = f[1]
				break
			}
		}
	}
	last := ""
	if b, err := os.ReadFile("/proc/1/cmdline"); err == nil {
		f := strings.Fields(strings.ReplaceAll(string(b), "\x00", " "))
		if len(f) > 0 {
			last = filepath.Base(f[len(f)-1])
		}
	}
	switch {
	case name == "systemd":
		return 1
	case name == "init" || last == "init":
		if st, err := os.Stat("/etc/init"); err == nil && st.IsDir() {
			return 2
		}
		return 3
	}
	if st, err := os.Stat("/run/systemd/system"); err == nil && st.IsDir() {
		return 1 // PID 1 is hidden (container/wrapper) but systemd is running
	}
	return 0
}

// withStartupType removes every StartupType= line and appends StartupType=<n>
// (the script's UpdateMshFile).
func withStartupType(msh []byte, n int) []byte {
	var out []string
	for _, l := range strings.Split(strings.ReplaceAll(string(msh), "\r\n", "\n"), "\n") {
		if strings.HasPrefix(l, "StartupType=") || l == "" {
			continue
		}
		out = append(out, l)
	}
	out = append(out, "StartupType="+strconv.Itoa(n))
	return []byte(strings.Join(out, "\n") + "\n")
}

func installLinux(ctx context.Context, spec *Spec, o InstallOptions, id int, res *Result, fail func(string, ...any) Result) Result {
	args := LinuxInstallArgs
	max := o.MaxBytes
	if max <= 0 {
		max = MaxDownload
	}
	dctx, cancel := context.WithTimeout(ctx, orDur(o.DownloadTimeout, 5*time.Minute))
	defer cancel()
	bin, status, err := fetch(dctx, spec, spec.BinaryURL(id), o, max)
	if err != nil {
		return fail("%s", err.Error())
	}
	if status != http.StatusOK {
		return fail("download of the Mesh agent (id %d) returned HTTP %d", id, status)
	}
	if err := checkMagic("linux", bin); err != nil {
		return fail("%s", err.Error())
	}
	msh, status, err := fetch(dctx, spec, spec.SettingsURL(), o, MaxSettings)
	if err != nil {
		return fail("download of meshsettings failed: %s", err.Error())
	}
	if status != http.StatusOK {
		return fail("download of meshsettings returned HTTP %d: check the device group id", status)
	}
	sum := sha256Hex(bin)
	res.SHA256 = sum

	st := DetectStartupType
	if o.StartupType != nil {
		st = o.StartupType
	}
	startup := st()
	parent := o.WorkDir
	if parent == "" {
		parent = os.TempDir()
	}
	dir, err := os.MkdirTemp(parent, "mesh-install-")
	if err != nil {
		return fail("cannot create a work directory: %v", err)
	}
	defer os.RemoveAll(dir)
	_ = os.Chmod(dir, 0o700)
	exe := filepath.Join(dir, "meshagent")
	if err := os.WriteFile(exe, bin, 0o700); err != nil {
		return fail("cannot save the downloaded Mesh agent: %v", err)
	}
	if err := os.WriteFile(filepath.Join(dir, "meshagent.msh"), withStartupType(msh, startup), 0o600); err != nil {
		return fail("cannot save the Mesh settings: %v", err)
	}
	run := o.Run
	if run == nil {
		run = RunProgram
	}
	rctx, rcancel := context.WithTimeout(ctx, orDur(o.RunTimeout, 5*time.Minute))
	defer rcancel()
	exit, out, err := run(rctx, dir, exe, args)
	res.ExitCode, res.Log = exit, out
	if err != nil {
		return fail("the Mesh installer could not run: %v", err)
	}
	if exit != 0 {
		return fail("the Mesh installer exited with code %d", exit)
	}
	if msg := verifyLinux(ctx, o, startup); msg != "" {
		return fail("%s", msg)
	}
	res.Installed = true
	o.Log.Info("the Mesh agent is installed and running", "mesh_host", res.Host, "agent_id", id, "sha256", res.SHA256, "startup_type", startup)
	return *res
}

func sha256Hex(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// verifyLinux returns "" when the agent is really installed, else the precise reason.
func verifyLinux(ctx context.Context, o InstallOptions, startup int) string {
	dir := orStr(o.LinuxInstallDir, LinuxInstallDir)
	for _, f := range []string{"meshagent", "meshagent.msh"} {
		if st, err := os.Stat(filepath.Join(dir, f)); err != nil || st.IsDir() {
			return fmt.Sprintf("installer exited 0 but %s is missing from %s: the Mesh agent was not installed", f, dir)
		}
	}
	timeout := orDur(o.VerifyTimeout, 30*time.Second)
	poll := orDur(o.PollInterval, time.Second)
	deadline := time.Now().Add(timeout)
	wait := func() bool {
		if time.Now().Add(poll).After(deadline) {
			return false
		}
		select {
		case <-ctx.Done():
			return false
		case <-time.After(poll):
			return true
		}
	}
	sysd := orStr(o.SystemdRunDir, "/run/systemd/system")
	if st, err := os.Stat(sysd); err == nil && st.IsDir() {
		ctl := o.Systemctl
		if ctl == nil {
			ctl = runSystemctl
		}
		out, _ := ctl(ctx, "list-unit-files", "--no-legend", "meshagent.service")
		if !strings.Contains(out, "meshagent.service") {
			return "installer exited 0 but no meshagent service was registered"
		}
		state := ""
		for {
			s, _ := ctl(ctx, "is-active", "meshagent")
			state = strings.TrimSpace(s)
			if state == "active" {
				return ""
			}
			if state == "failed" || state == "inactive" || !wait() {
				break
			}
		}
		if state == "" {
			state = "unknown"
		}
		return "service installed but not active: " + state
	}
	// no systemd: the init script/job must exist and the installed binary must be running
	paths := o.InitScriptPaths
	if paths == nil {
		paths = []string{"/etc/init.d/meshagent", "/etc/init/meshagent.conf"}
	}
	found := false
	for _, p := range paths {
		if _, err := os.Stat(p); err == nil {
			found = true
		}
	}
	if !found {
		return fmt.Sprintf("installer exited 0 but no meshagent service was registered (startup type %d)", startup)
	}
	running := o.AgentRunning
	if running == nil {
		running = processRunning
	}
	exe := filepath.Join(dir, "meshagent")
	for {
		if running(exe) {
			return ""
		}
		if !wait() {
			return "service installed but not active: the installed agent process is not running"
		}
	}
}

func runSystemctl(ctx context.Context, args ...string) (string, int) {
	c, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	b, err := exec.CommandContext(c, "systemctl", args...).Output()
	if err != nil {
		if ee, ok := err.(*exec.ExitError); ok {
			return string(b), ee.ExitCode()
		}
		return string(b), -1
	}
	return string(b), 0
}

// processRunning reports whether any process runs the executable at exe (/proc scan).
func processRunning(exe string) bool {
	ents, _ := os.ReadDir("/proc")
	for _, e := range ents {
		if _, err := strconv.Atoi(e.Name()); err != nil {
			continue
		}
		if p, err := os.Readlink("/proc/" + e.Name() + "/exe"); err == nil && strings.TrimSuffix(p, " (deleted)") == exe {
			return true
		}
		// a script agent (the e2e stand-in) shows up as its interpreter; match its argv instead
		if b, err := os.ReadFile("/proc/" + e.Name() + "/cmdline"); err == nil {
			for _, a := range strings.Split(string(b), "\x00") {
				if a == exe {
					return true
				}
			}
		}
	}
	return false
}

func orStr(s, def string) string {
	if s != "" {
		return s
	}
	return def
}
