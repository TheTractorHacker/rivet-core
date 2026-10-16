package patch

import (
	"context"
	"encoding/json"
	"errors"
	"slices"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/remote"
)

func newEngine(goos string, fs FS, fn func(prog string, args []string) (string, string, int, error)) (Engine, *fakeRunner) {
	r := &fakeRunner{fn: fn}
	return Engine{GOOS: goos, R: r, FS: fs, Local: time.UTC, Now: func() time.Time { return time.Date(2024, 5, 4, 12, 0, 0, 0, time.UTC) }}, r
}

func scanOf(t *testing.T, res remote.Result) ScanOut {
	t.Helper()
	if !res.OK {
		t.Fatalf("scan failed: %+v", res)
	}
	var o ScanOut
	if err := json.Unmarshal([]byte(res.Output), &o); err != nil {
		t.Fatalf("%v: %s", err, res.Output)
	}
	if o.V != 1 || !o.OK || o.Updates == nil {
		t.Fatalf("%+v", o)
	}
	return o
}

func installOf(t *testing.T, res remote.Result) InstallOut {
	t.Helper()
	var o InstallOut
	if err := json.Unmarshal([]byte(res.Output), &o); err != nil {
		t.Fatalf("%v: %s", err, res.Output)
	}
	return o
}

func byID(us []Update, id string) (Update, bool) {
	for _, u := range us {
		if u.ID == id {
			return u, true
		}
	}
	return Update{}, false
}

func ids(us []Update) []string {
	var r []string
	for _, u := range us {
		r = append(r, u.ID)
	}
	return r
}

// aptRoute answers like an Ubuntu 22.04 machine.
func aptRoute(t *testing.T) func(prog string, args []string) (string, string, int, error) {
	return func(prog string, args []string) (string, string, int, error) {
		a := strings.Join(args, " ")
		switch {
		case strings.HasSuffix(prog, "/apt") && a == "list --upgradable":
			return fixture(t, "apt_ubuntu2204_list.txt"), "WARNING: apt does not have a stable CLI interface.\n", 0, nil
		case strings.HasSuffix(prog, "/apt-mark"):
			return fixture(t, "apt_ubuntu2204_showhold.txt"), "", 0, nil
		case strings.HasSuffix(prog, "/apt-cache"):
			return fixture(t, "apt_ubuntu2204_policy.txt"), "", 0, nil
		}
		t.Errorf("unexpected command %s %s", prog, a)
		return "", "", 127, nil
	}
}

func TestScanAptUbuntu2204(t *testing.T) {
	fs := aptFS(map[string]string{
		"/var/run/reboot-required": "",
		"/var/log/apt/history.log": fixture(t, "apt_history.log"),
	})
	e, r := newEngine("linux", fs, aptRoute(t))
	o := scanOf(t, e.Scan(context.Background(), nil, 1<<20))
	if o.Manager != "apt" || o.ScanState != "ok" || o.WUState != "n/a" || !o.RebootPending || o.Total != 5 || o.Truncated {
		t.Fatalf("%+v", o)
	}
	if o.LastInstallAt == nil || *o.LastInstallAt != "2024-05-02T06:25:12Z" || o.ScannedAt != "2024-05-04T12:00:00Z" {
		t.Errorf("times: %v %v", o.LastInstallAt, o.ScannedAt)
	}
	// security first (by title), then the rest; the held package is gone
	if got := strings.Join(ids(o.Updates), ","); got != "libssl3,linux-image-generic,openssl,tzdata,vim" {
		t.Errorf("order: %s", got)
	}
	for _, id := range []string{"libssl3", "linux-image-generic", "openssl", "tzdata"} {
		if u, _ := byID(o.Updates, id); u.Kind != "security" || u.Severity != "important" || u.Categories[0] != "Security Updates" {
			t.Errorf("%s: %+v", id, u)
		}
	}
	vim, _ := byID(o.Updates, "vim")
	if vim.Kind != "optional" || vim.Severity != "unspecified" || vim.Current != "2:8.2.3995-1ubuntu2.17" || vim.Version != "2:8.2.3995-1ubuntu2.19" || !vim.EulaAccepted || vim.ReleasedAt != nil {
		t.Errorf("vim: %+v", vim)
	}
	if k, _ := byID(o.Updates, "linux-image-generic"); !k.RebootRequired {
		t.Error("a kernel update needs a reboot")
	}
	if u, _ := byID(o.Updates, "openssl"); u.RebootRequired {
		t.Error("openssl does not")
	}
	// read-only: only list, showhold and policy ran, every package name after "--"
	for _, c := range r.calls {
		base := c[0][strings.LastIndex(c[0], "/")+1:]
		if base != "apt" && base != "apt-mark" && base != "apt-cache" {
			t.Errorf("a scan ran %v", c)
		}
		if base == "apt-cache" && c[2] != "--" {
			t.Errorf("-- must precede the names: %v", c)
		}
	}

	// category filter and caps
	o = scanOf(t, e.Scan(context.Background(), json.RawMessage(`{"categories":"security"}`), 1<<20))
	if o.Total != 4 || len(o.Updates) != 4 {
		t.Errorf("security only: %d", o.Total)
	}
	o = scanOf(t, e.Scan(context.Background(), json.RawMessage(`{"max_items":2}`), 1<<20))
	if len(o.Updates) != 2 || o.Total != 5 || !o.Truncated {
		t.Errorf("max_items: %d/%d %v", len(o.Updates), o.Total, o.Truncated)
	}
	res := e.Scan(context.Background(), nil, 900)
	o = scanOf(t, res)
	if len(res.Output) > 900 || !o.Truncated || o.Total != 5 || len(o.Updates) >= 5 {
		t.Errorf("output cap: len=%d %+v", len(res.Output), o)
	}
}

func TestScanAptNoPolicyIsPartial(t *testing.T) {
	route := aptRoute(t)
	e, _ := newEngine("linux", aptFS(nil), func(prog string, args []string) (string, string, int, error) {
		if strings.HasSuffix(prog, "/apt-cache") {
			return "", "E: boom", 100, nil
		}
		return route(prog, args)
	})
	o := scanOf(t, e.Scan(context.Background(), nil, 1<<20))
	if o.ScanState != "partial" {
		t.Errorf("state %s", o.ScanState)
	}
	// the suites on the list line still identify the security updates
	if u, _ := byID(o.Updates, "openssl"); u.Kind != "security" {
		t.Errorf("%+v", u)
	}
	if o.LastInstallAt != nil || o.RebootPending {
		t.Errorf("%+v", o)
	}
}

func TestScanAptDebianMultiarch(t *testing.T) {
	e, _ := newEngine("linux", aptFS(nil), func(prog string, args []string) (string, string, int, error) {
		switch {
		case strings.HasSuffix(prog, "/apt"):
			return fixture(t, "apt_debian12_list.txt"), "", 0, nil
		case strings.HasSuffix(prog, "/apt-mark"):
			return "", "", 0, nil
		}
		return fixture(t, "apt_debian12_policy.txt"), "", 0, nil
	})
	o := scanOf(t, e.Scan(context.Background(), nil, 1<<20))
	if got := strings.Join(ids(o.Updates), ","); got != "bash,libc6:amd64,libc6:i386,nano" {
		t.Fatalf("ids: %s", got)
	}
	if n, _ := byID(o.Updates, "nano"); n.Kind != "optional" {
		t.Errorf("%+v", n)
	}
	if o.ScanState != "ok" {
		t.Errorf("state %s", o.ScanState)
	}
}

func TestScanFailureCodes(t *testing.T) {
	fs := aptFS(nil)
	for _, c := range []struct {
		name   string
		fn     func(string, []string) (string, string, int, error)
		detail string
	}{
		{"lock", func(string, []string) (string, string, int, error) {
			return "", fixture(t, "apt_lock_error.txt"), 100, nil
		}, DetailLockHeld},
		{"timeout", func(string, []string) (string, string, int, error) { return "", "", 0, context.DeadlineExceeded }, DetailTimeout},
		{"lock then timeout", func(string, []string) (string, string, int, error) {
			return "", "Waiting for process with pid 1", 0, context.DeadlineExceeded
		}, DetailLockHeld},
		{"cannot start", func(string, []string) (string, string, int, error) {
			return "", "", 0, errors.New("fork/exec: no such file")
		}, DetailScanFailed},
		{"exit 1", func(string, []string) (string, string, int, error) { return "", "E: broken sources", 1, nil }, DetailScanFailed},
	} {
		e, _ := newEngine("linux", fs, c.fn)
		res := e.Scan(context.Background(), nil, 1<<20)
		var m map[string]any
		_ = json.Unmarshal([]byte(res.Output), &m)
		if res.OK || m["detail"] != c.detail || m["ok"] != false || m["v"] != float64(1) {
			t.Errorf("%s: %+v", c.name, res)
		}
	}
	e, r := newEngine("linux", fakeFS{}, nil)
	if res := e.Scan(context.Background(), nil, 1<<20); res.OK || !strings.Contains(res.Output, DetailNoManager) || len(r.calls) != 0 {
		t.Errorf("no manager: %+v", res)
	}
	if res := (Engine{GOOS: "plan9"}).Scan(context.Background(), nil, 1<<20); res.OK || !res.Cannot || !strings.Contains(res.Output, DetailUnsupported) {
		t.Errorf("unsupported: %+v", res)
	}
}

func rpmRoute(t *testing.T, mgr, check, info string, infoCode int) func(string, []string) (string, string, int, error) {
	return func(prog string, args []string) (string, string, int, error) {
		a := strings.Join(args, " ")
		switch {
		case strings.HasSuffix(prog, "/needs-restarting"):
			return "", "", 0, nil
		case strings.HasSuffix(prog, "/"+mgr) && a == "-q check-update":
			return fixture(t, check), "", 100, nil
		case strings.HasSuffix(prog, "/"+mgr) && strings.HasPrefix(a, "-q updateinfo list"):
			return fixture(t, info), "", infoCode, nil
		case strings.HasSuffix(prog, "/rpm"):
			return fixture(t, "rpm_versions.txt"), "", 0, nil
		}
		t.Errorf("unexpected command %s %s", prog, a)
		return "", "", 127, nil
	}
}

func TestScanDnfRocky9(t *testing.T) {
	fs := rpmFS("dnf", map[string]string{"/usr/bin/needs-restarting": "", "/var/log/dnf.rpm.log": fixture(t, "dnf.rpm.log")})
	route := rpmRoute(t, "dnf", "rpm_rocky9_checkupdate.txt", "rpm_rocky9_updateinfo.txt", 0)
	e, r := newEngine("linux", fs, func(prog string, args []string) (string, string, int, error) {
		if strings.HasSuffix(prog, "/needs-restarting") {
			return "Core libraries or services have been updated:\n", "", 1, nil
		}
		return route(prog, args)
	})
	o := scanOf(t, e.Scan(context.Background(), nil, 1<<20))
	if o.Manager != "dnf" || o.ScanState != "ok" || !o.RebootPending || o.Total != 6 {
		t.Fatalf("%+v", o)
	}
	if o.LastInstallAt == nil || *o.LastInstallAt != "2024-05-02T06:25:12Z" {
		t.Errorf("last install %v", o.LastInstallAt)
	}
	ssl, _ := byID(o.Updates, "openssl.x86_64")
	if ssl.Kind != "critical" || ssl.Severity != "critical" || ssl.Version != "1:3.0.7-28.el9_4" || ssl.Current != "1:3.0.7-27.el9" || !strings.Contains(ssl.Title, "RLSA-2024:2448") {
		t.Errorf("openssl: %+v", ssl)
	}
	if k, _ := byID(o.Updates, "kernel.x86_64"); k.Kind != "security" || k.Severity != "important" || !k.RebootRequired {
		t.Errorf("kernel: %+v", k)
	}
	if k, _ := byID(o.Updates, "tzdata.noarch"); k.Kind != "security" || k.Severity != "unspecified" || k.Current != "2024a-1.el9" {
		t.Errorf("tzdata: %+v", k)
	}
	if v, _ := byID(o.Updates, "vim-minimal.x86_64"); v.Kind != "optional" {
		t.Errorf("vim: %+v", v)
	}
	if got := ids(o.Updates); got[5] != "vim-minimal.x86_64" || o.Updates[4].Kind == "optional" {
		t.Errorf("security and critical first, then the rest: %v", got)
	}
	// the updateinfo query used dnf's --security form
	if !r.called("-q updateinfo list --security") {
		t.Error("dnf must be asked with --security")
	}

	// advisories unavailable: still a scan, but partial and nothing is called a security update
	e, _ = newEngine("linux", fs, rpmRoute(t, "dnf", "rpm_rocky9_checkupdate.txt", "rpm_rocky9_updateinfo.txt", 1))
	o = scanOf(t, e.Scan(context.Background(), nil, 1<<20))
	if o.ScanState != "partial" {
		t.Error("state")
	}
	for _, u := range o.Updates {
		if u.Kind != "optional" {
			t.Errorf("%+v", u)
		}
	}
}

func TestScanDnfOtherDistros(t *testing.T) {
	for _, c := range []struct {
		mgr, check, info string
		n                int
	}{
		{"dnf", "rpm_alma9_checkupdate.txt", "rpm_alma9_updateinfo.txt", 3},
		{"dnf", "rpm_fedora_dnf5_checkupdate.txt", "rpm_fedora_dnf5_updateinfo.txt", 3},
		{"yum", "rpm_yum7_checkupdate.txt", "rpm_yum7_updateinfo.txt", 3},
	} {
		e, r := newEngine("linux", rpmFS(c.mgr, nil), rpmRoute(t, c.mgr, c.check, c.info, 0))
		o := scanOf(t, e.Scan(context.Background(), nil, 1<<20))
		if o.Manager != c.mgr || o.Total != c.n {
			t.Errorf("%s: %+v", c.check, o)
		}
		if c.mgr == "yum" && !r.called("-q updateinfo list security") {
			t.Error("yum is asked with the bare word security")
		}
		if c.mgr == "yum" {
			nm, ok := byID(o.Updates, "networkmanager-config-server.noarch")
			if !ok || nm.Kind != "optional" {
				t.Errorf("a mixed-case rpm name travels as a lower-case id: %v", ids(o.Updates))
			}
		}
	}
	// nothing to update and a failure
	e, _ := newEngine("linux", rpmFS("dnf", nil), func(string, []string) (string, string, int, error) { return "", "", 0, nil })
	if o := scanOf(t, e.Scan(context.Background(), nil, 1<<20)); o.Total != 0 || len(o.Updates) != 0 {
		t.Errorf("%+v", o)
	}
	e, _ = newEngine("linux", rpmFS("dnf", nil), func(string, []string) (string, string, int, error) {
		return "", "Error: Failed to download metadata", 1, nil
	})
	if res := e.Scan(context.Background(), nil, 1<<20); res.OK || !strings.Contains(res.Output, DetailScanFailed) {
		t.Errorf("%+v", res)
	}
	e, _ = newEngine("linux", rpmFS("dnf", nil), func(string, []string) (string, string, int, error) {
		return "", fixture(t, "dnf_lock.txt"), 0, context.DeadlineExceeded
	})
	if res := e.Scan(context.Background(), nil, 1<<20); res.OK || !strings.Contains(res.Output, DetailLockHeld) {
		t.Errorf("%+v", res)
	}
}

func zypperRoute(t *testing.T, gone string) func(string, []string) (string, string, int, error) {
	return func(prog string, args []string) (string, string, int, error) {
		a := strings.Join(args, " ")
		if strings.HasSuffix(prog, "/zypper") && a == "--non-interactive --quiet list-patches" {
			out := fixture(t, "zypper_list_patches.txt")
			if gone != "" {
				var keep []string
				for _, l := range strings.Split(out, "\n") {
					if !strings.Contains(l, gone) {
						keep = append(keep, l)
					}
				}
				out = strings.Join(keep, "\n")
			}
			return out, "", 0, nil
		}
		return "", "", 0, nil
	}
}

func TestScanZypper(t *testing.T) {
	e, _ := newEngine("linux", rpmFS("zypper", map[string]string{"/var/log/zypp/history": fixture(t, "zypp_history"), "/run/reboot-needed": ""}), zypperRoute(t, ""))
	o := scanOf(t, e.Scan(context.Background(), nil, 1<<20))
	if o.Manager != "zypper" || o.ScanState != "partial" || o.Total != 3 || !o.RebootPending || o.LastInstallAt == nil || *o.LastInstallAt != "2024-05-03T07:30:09Z" {
		t.Fatalf("%+v", o)
	}
	k, ok := byID(o.Updates, "suse-sle-module-basesystem-15-sp5-2024-2222")
	if !ok || k.Kind != "critical" || k.Severity != "critical" || !k.RebootRequired || k.Title != "Security update for the Linux Kernel" {
		t.Errorf("%+v", k)
	}
	if v, _ := byID(o.Updates, "opensuse-sle-15.5-2024-3333"); v.Kind != "important" || v.Severity != "moderate" {
		t.Errorf("%+v", v)
	}
}

func TestCategoryFilterOnLinux(t *testing.T) {
	e, _ := newEngine("linux", rpmFS("dnf", nil), rpmRoute(t, "dnf", "rpm_rocky9_checkupdate.txt", "rpm_rocky9_updateinfo.txt", 0))
	o := scanOf(t, e.Scan(context.Background(), json.RawMessage(`{"categories":"critical,optional"}`), 1<<20))
	if got := strings.Join(ids(o.Updates), ","); got != "openssl.x86_64,vim-minimal.x86_64" {
		t.Errorf("%s", got)
	}
}

// ---- install

// argvAfterDashes returns what follows the first "--" of a call.
func argvAfterDashes(c []string) []string {
	i := slices.Index(c, "--")
	if i < 0 {
		return nil
	}
	return c[i+1:]
}

func realInstallCalls(r *fakeRunner, word string) [][]string {
	var out [][]string
	for _, c := range r.calls {
		if slices.Contains(c, word) && !slices.Contains(c, "-s") && !slices.Contains(c, "--assumeno") && !slices.Contains(c, "--dry-run") {
			out = append(out, c)
		}
	}
	return out
}

func aptInstallRoute(t *testing.T, st *aptState) func(string, []string) (string, string, int, error) {
	base := aptRoute(t)
	return func(prog string, args []string) (string, string, int, error) {
		a := strings.Join(args, " ")
		switch {
		case strings.HasSuffix(prog, "/apt-get") && strings.HasPrefix(a, "-s install"):
			if st.simOut != "" || st.simErr != "" {
				return st.simOut, st.simErr, st.simCode, nil
			}
			return fixture(t, "apt_sim_upgrade.txt"), "", 0, nil
		case strings.HasSuffix(prog, "/apt-get") && strings.HasPrefix(a, "install"):
			return st.instOut, st.instErr, st.instCode, nil
		case strings.HasSuffix(prog, "/dpkg-query") && strings.Contains(a, "Status-Abbrev"):
			if strings.HasSuffix(a, " nosuch") {
				return "", "dpkg-query: no packages found matching nosuch", 1, nil
			}
			return "ii \n", "", 0, nil
		case strings.HasSuffix(prog, "/dpkg-query"):
			return st.after, "", 0, nil
		}
		return base(prog, args)
	}
}

type aptState struct {
	simOut, simErr, instOut, instErr, after string
	simCode, instCode                       int
}

func TestInstallAptDryRun(t *testing.T) {
	e, _ := newEngine("linux", aptFS(nil), aptInstallRoute(t, &aptState{}))
	res := e.Install(context.Background(), json.RawMessage(`{"ids":"openssl,vim,nosuch,held-tool,libssl3"}`), 1<<20)
	o := installOf(t, res)
	want := map[string]string{"openssl": "would_install", "vim": "skipped", "nosuch": "not_found", "held-tool": "not_applicable", "libssl3": "would_install"}
	for _, it := range o.Results {
		if want[it.ID] != it.Result {
			t.Errorf("%s: %+v", it.ID, it)
		}
	}
	if o.Mode != "dry_run" || o.Manager != "apt" || len(o.Results) != 5 || o.Results[0].ID != "openssl" || o.Results[3].ID != "held-tool" {
		t.Errorf("%+v", o)
	}
	if res.OK || o.OK {
		t.Errorf("not_found must fail the job; res=%+v", res)
	}
}

func TestInstallAptDryRunOKWhenNothingFails(t *testing.T) {
	e, r := newEngine("linux", aptFS(nil), aptInstallRoute(t, &aptState{}))
	res := e.Install(context.Background(), json.RawMessage(`{"ids":"openssl,libssl3"}`), 1<<20)
	if !res.OK {
		t.Fatalf("%+v", res)
	}
	if o := installOf(t, res); !o.OK || o.Results[0].Result != "would_install" || o.Results[1].Title != "libssl3" || o.RebootRequired {
		t.Errorf("%+v", o)
	}
	if got := realInstallCalls(r, "install"); len(got) != 0 {
		t.Fatalf("a dry run must not install: %v", got)
	}
	var sim []string
	for _, c := range r.calls {
		if strings.HasSuffix(c[len(c)-1], "libssl3") && slices.Contains(c, "-s") {
			sim = c
		}
	}
	if sim == nil || sim[0] != "/usr/bin/env" || !slices.Contains(sim, "DEBIAN_FRONTEND=noninteractive") || !slices.Contains(sim, "--only-upgrade") {
		t.Fatalf("simulation call: %v", sim)
	}
	if got := argvAfterDashes(sim); !slices.Equal(got, []string{"openssl", "libssl3"}) {
		t.Errorf("only the ids follow --: %v", got)
	}
}

func TestInstallAptRealRun(t *testing.T) {
	st := &aptState{after: "openssl:amd64 3.0.2-0ubuntu1.18\nlibssl3:amd64 3.0.2-0ubuntu1.12\n", instCode: 100, instErr: "E: Sub-process /usr/bin/dpkg returned an error code (1)\n"}
	fs := aptFS(map[string]string{"/var/run/reboot-required": ""})
	e, r := newEngine("linux", fs, aptInstallRoute(t, st))
	res := e.Install(context.Background(), json.RawMessage(`{"ids":"openssl,libssl3","mode":"install","max_minutes":30}`), 1<<20)
	o := installOf(t, res)
	if res.OK || o.OK || o.Mode != "install" {
		t.Fatalf("a package that was not upgraded fails the job: %+v", res)
	}
	if o.Results[0].Result != "installed" || o.Results[0].Code != "0" || o.Results[1].Result != "failed" || o.Results[1].Code != "100" || !strings.Contains(o.Results[1].Detail, "dpkg returned") {
		t.Errorf("%+v", o.Results)
	}
	if !o.RebootRequired {
		t.Error("/var/run/reboot-required must be reported")
	}
	calls := realInstallCalls(r, "--only-upgrade")
	if len(calls) != 1 {
		t.Fatalf("one real install call: %v", calls)
	}
	c := calls[0]
	for _, need := range []string{"-y", "Dpkg::Options::=--force-confold", "Dpkg::Options::=--force-confdef", "DPkg::Lock::Timeout=0", "NEEDRESTART_MODE=l", "APT_LISTCHANGES_FRONTEND=none"} {
		if !slices.Contains(c, need) {
			t.Errorf("missing %s in %v", need, c)
		}
	}
	if !slices.Equal(argvAfterDashes(c), []string{"openssl", "libssl3"}) {
		t.Errorf("%v", c)
	}
	for _, a := range c {
		if strings.Contains(strings.ToLower(a), "reboot") && !strings.Contains(a, "reboot-required") {
			t.Errorf("a reboot flag in %v", c)
		}
	}
	if !r.called("dpkg-query -W -f=${Package}:${Architecture} ${Version}\\n -- openssl libssl3") {
		t.Errorf("versions are read back: %v", r.calls)
	}
}

func TestInstallAptRefusesRemovalsAndHonoursLocks(t *testing.T) {
	// removal in the simulation: nothing is installed, every id is skipped (a success for the job)
	e, r := newEngine("linux", aptFS(nil), aptInstallRoute(t, &aptState{simOut: fixture(t, "apt_sim_remove.txt")}))
	res := e.Install(context.Background(), json.RawMessage(`{"ids":"openssl","mode":"install"}`), 1<<20)
	o := installOf(t, res)
	if !res.OK || o.Results[0].Result != "skipped" || o.Results[0].Detail != "would_remove_packages" {
		t.Errorf("%+v", o)
	}
	if got := realInstallCalls(r, "--only-upgrade"); len(got) != 0 {
		t.Errorf("removal must prevent the real run: %v", got)
	}

	// lock held during the simulation, and during the real run
	lock := fixture(t, "apt_lock_error.txt")
	e, r = newEngine("linux", aptFS(nil), aptInstallRoute(t, &aptState{simErr: lock, simCode: 100}))
	res = e.Install(context.Background(), json.RawMessage(`{"ids":"openssl,libssl3","mode":"install"}`), 1<<20)
	o = installOf(t, res)
	if res.OK || o.Results[0].Result != "locked" || o.Results[1].Result != "locked" || len(realInstallCalls(r, "--only-upgrade")) != 0 {
		t.Errorf("%+v", o)
	}
	e, r = newEngine("linux", aptFS(nil), aptInstallRoute(t, &aptState{instErr: lock, instCode: 100, after: "openssl:amd64 3.0.2-0ubuntu1.12\n"}))
	o = installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"openssl","mode":"install"}`), 1<<20))
	if o.OK || o.Results[0].Result != "locked" {
		t.Errorf("%+v", o)
	}
	for _, c := range r.calls { // never touches lock files or processes
		for _, a := range c {
			if a == "kill" || a == "rm" || strings.Contains(a, "lock") && strings.HasPrefix(a, "/") {
				t.Errorf("forbidden: %v", c)
			}
		}
	}

	// the scan itself blocked by a lock: all ids locked
	e, _ = newEngine("linux", aptFS(nil), func(string, []string) (string, string, int, error) { return "", lock, 100, nil })
	o = installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"openssl"}`), 1<<20))
	if o.OK || o.Results[0].Result != "locked" {
		t.Errorf("%+v", o)
	}
}

func TestInstallHostileInputNeverReachesACommand(t *testing.T) {
	e, r := newEngine("linux", aptFS(nil), aptInstallRoute(t, &aptState{}))
	for _, ids := range []string{"-oAPT::Foo", "a b", "x;rm", "$(x)", "a\nb", strings.Repeat("a", 5000), "pâquet", "a,a", "", "openssl,--allow-unauthenticated"} {
		raw, _ := json.Marshal(map[string]any{"ids": ids, "mode": "install"})
		res := e.Install(context.Background(), raw, 1<<20)
		if res.OK || !res.Cannot || !strings.Contains(res.Output, DetailInvalidParams) {
			t.Errorf("%q: %+v", ids, res)
		}
	}
	var many []string
	for i := 0; i < 41; i++ {
		many = append(many, "pkg"+strings.Repeat("x", i+1))
	}
	raw, _ := json.Marshal(map[string]any{"ids": strings.Join(many, ","), "mode": "install"})
	if res := e.Install(context.Background(), raw, 1<<20); !res.Cannot {
		t.Error("41 ids")
	}
	for _, p := range []string{`{"ids":"a","mode":"install","reboot":true}`, `{"ids":"a","mode":"yes"}`, `{"mode":"install"}`, `[]`, `{"ids":"a","max_minutes":999}`} {
		if res := e.Install(context.Background(), json.RawMessage(p), 1<<20); !res.Cannot || !strings.Contains(res.Output, DetailInvalidParams) {
			t.Errorf("%s: %+v", p, res)
		}
	}
	if len(r.calls) != 0 {
		t.Fatalf("hostile input must be refused before any command: %v", r.calls)
	}
	if res := e.Scan(context.Background(), json.RawMessage(`{"categories":"all;rm"}`), 1<<20); !res.Cannot || len(r.calls) != 0 {
		t.Errorf("%+v", res)
	}
}

func TestInstallBusy(t *testing.T) {
	e, _ := newEngine("linux", aptFS(nil), aptInstallRoute(t, &aptState{}))
	installMu.Lock()
	defer installMu.Unlock()
	res := e.Install(context.Background(), json.RawMessage(`{"ids":"openssl"}`), 1<<20)
	if res.OK || !strings.Contains(res.Output, DetailBusy) {
		t.Errorf("%+v", res)
	}
}

func rpmInstallRoute(t *testing.T, mgr, after string, instCode int, instErr, sim string) func(string, []string) (string, string, int, error) {
	base := rpmRoute(t, mgr, "rpm_rocky9_checkupdate.txt", "rpm_rocky9_updateinfo.txt", 0)
	return func(prog string, args []string) (string, string, int, error) {
		a := strings.Join(args, " ")
		switch {
		case strings.HasSuffix(prog, "/"+mgr) && strings.Contains(a, "--assumeno"):
			return fixture(t, sim), "", 1, nil
		case strings.HasSuffix(prog, "/"+mgr) && strings.HasPrefix(a, "-y upgrade"):
			return "", instErr, instCode, nil
		case strings.HasSuffix(prog, "/rpm") && strings.Contains(a, "--qf") && after != "":
			return after, "", 0, nil
		}
		return base(prog, args)
	}
}

func TestInstallDnf(t *testing.T) {
	// dry run
	e, r := newEngine("linux", rpmFS("dnf", nil), rpmInstallRoute(t, "dnf", "", 0, "", "dnf_sim_ok.txt"))
	res := e.Install(context.Background(), json.RawMessage(`{"ids":"openssl.x86_64,kernel.x86_64"}`), 1<<20)
	o := installOf(t, res)
	if !res.OK || o.Results[0].Result != "would_install" || o.Results[1].Result != "would_install" || len(realInstallCalls(r, "upgrade")) != 0 {
		t.Fatalf("%+v", o)
	}
	// real run: the new version is there afterwards
	e, r = newEngine("linux", rpmFS("dnf", nil), rpmInstallRoute(t, "dnf", "openssl.x86_64 1:3.0.7-28.el9_4\nkernel.x86_64 5.14.0-362.8.1.el9_3\n", 1, "Error: Transaction failed", "dnf_sim_ok.txt"))
	res = e.Install(context.Background(), json.RawMessage(`{"ids":"openssl.x86_64,kernel.x86_64","mode":"install"}`), 1<<20)
	o = installOf(t, res)
	if res.OK || o.Results[0].Result != "installed" || o.Results[1].Result != "failed" || !strings.Contains(o.Results[1].Detail, "Transaction failed") {
		t.Fatalf("%+v", o)
	}
	calls := realInstallCalls(r, "upgrade")
	if len(calls) != 1 || !slices.Equal(argvAfterDashes(calls[0]), []string{"openssl.x86_64", "kernel.x86_64"}) || !slices.Contains(calls[0], "-y") {
		t.Fatalf("%v", calls)
	}
	// removals are refused
	e, r = newEngine("linux", rpmFS("dnf", nil), rpmInstallRoute(t, "dnf", "", 0, "", "dnf_sim_remove.txt"))
	o = installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"openssl.x86_64","mode":"install"}`), 1<<20))
	if !o.OK || o.Results[0].Result != "skipped" || o.Results[0].Detail != "would_remove_packages" || len(realInstallCalls(r, "upgrade")) != 0 {
		t.Errorf("%+v", o)
	}
	// a lock
	e, _ = newEngine("linux", rpmFS("dnf", nil), rpmInstallRoute(t, "dnf", "", 1, fixture(t, "dnf_lock.txt"), "dnf_sim_ok.txt"))
	o = installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"openssl.x86_64","mode":"install"}`), 1<<20))
	if o.OK || o.Results[0].Result != "locked" {
		t.Errorf("%+v", o)
	}
	// a package that is not in the scan: not_applicable when installed, not_found when rpm says it is not
	e, _ = newEngine("linux", rpmFS("dnf", nil), func(prog string, args []string) (string, string, int, error) {
		if strings.HasSuffix(prog, "/rpm") && slices.Contains(args, "--qf") && slices.Contains(args, "nosuch.x86_64") {
			return "package nosuch.x86_64 is not installed", "", 1, nil
		}
		return rpmInstallRoute(t, "dnf", "", 0, "", "dnf_sim_ok.txt")(prog, args)
	})
	o = installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"nosuch.x86_64,bash.x86_64"}`), 1<<20))
	if o.Results[0].Result != "not_found" || o.Results[1].Result != "not_applicable" {
		t.Errorf("%+v", o.Results)
	}
}

func TestInstallYumPassesTheRealMixedCaseName(t *testing.T) {
	route := rpmRoute(t, "yum", "rpm_yum7_checkupdate.txt", "rpm_yum7_updateinfo.txt", 0)
	e, r := newEngine("linux", rpmFS("yum", nil), func(prog string, args []string) (string, string, int, error) {
		a := strings.Join(args, " ")
		if strings.HasSuffix(prog, "/yum") && strings.Contains(a, "--assumeno") {
			return fixture(t, "dnf_sim_ok.txt"), "", 1, nil
		}
		return route(prog, args)
	})
	o := installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"networkmanager-config-server.noarch"}`), 1<<20))
	if !o.OK || o.Results[0].Result != "would_install" {
		t.Fatalf("%+v", o)
	}
	var got []string
	for _, c := range r.calls {
		if slices.Contains(c, "--assumeno") {
			got = argvAfterDashes(c)
		}
	}
	if !slices.Equal(got, []string{"NetworkManager-config-server.noarch"}) {
		t.Errorf("the name given to yum is the one yum printed: %v", got)
	}
}

func TestInstallZypper(t *testing.T) {
	name := "SUSE-SLE-Module-Basesystem-15-SP5-2024-2222"
	id := strings.ToLower(name)
	var installed bool
	e, r := newEngine("linux", rpmFS("zypper", nil), func(prog string, args []string) (string, string, int, error) {
		a := strings.Join(args, " ")
		switch {
		case strings.Contains(a, "install --dry-run"):
			return "The following patch is going to be installed:\n", "", 0, nil
		case strings.Contains(a, "install -t patch"):
			installed = true
			return "", "", 102, nil // ZYPPER_EXIT_INF_REBOOT_NEEDED
		}
		gone := ""
		if installed {
			gone = name
		}
		return zypperRoute(t, gone)(prog, args)
	})
	o := installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"`+id+`"}`), 1<<20))
	if !o.OK || o.Results[0].Result != "would_install" {
		t.Fatalf("%+v", o)
	}
	o = installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"`+id+`","mode":"install"}`), 1<<20))
	if !o.OK || o.Results[0].Result != "installed" || !o.Results[0].RebootRequired || !o.RebootRequired {
		t.Fatalf("%+v", o)
	}
	calls := realInstallCalls(r, "install")
	if len(calls) != 1 || !slices.Equal(argvAfterDashes(calls[0]), []string{name}) {
		t.Errorf("zypper gets the real patch name after --: %v", calls)
	}
	// removals refused
	e, _ = newEngine("linux", rpmFS("zypper", nil), func(prog string, args []string) (string, string, int, error) {
		if strings.Contains(strings.Join(args, " "), "--dry-run") {
			return "The following 1 package is going to be REMOVED:\n  foo\n", "", 0, nil
		}
		return zypperRoute(t, "")(prog, args)
	})
	o = installOf(t, e.Install(context.Background(), json.RawMessage(`{"ids":"`+id+`","mode":"install"}`), 1<<20))
	if o.Results[0].Result != "skipped" || o.Results[0].Detail != "would_remove_packages" {
		t.Errorf("%+v", o)
	}
}
