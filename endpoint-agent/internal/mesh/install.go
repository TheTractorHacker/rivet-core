package mesh

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/sha512"
	"crypto/subtle"
	"crypto/tls"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

const (
	// MaxDownload caps the downloaded installer (a MeshAgent is a few MB).
	MaxDownload = 64 << 20
	// logTail is how much of the installer's output is kept in the result.
	logTail = 4096
)

// RunFunc runs a program and returns its exit code and the tail of its output.
type RunFunc func(ctx context.Context, dir, path string, args []string) (exit int, out string, err error)

// InstallOptions are the platform and test hooks of Install.
type InstallOptions struct {
	GOOS, GOARCH string
	WorkDir      string // parent of the private download directory (the agent's state directory)
	Log          *slog.Logger
	Now          func() time.Time
	// HTTPClient replaces the client built from the spec (tests); nil builds it.
	HTTPClient *http.Client
	// Run replaces the program runner (tests of the Windows flow on Linux).
	Run RunFunc
	// DownloadTimeout / RunTimeout default to 5 and 5 minutes.
	DownloadTimeout, RunTimeout time.Duration
	// MaxBytes overrides MaxDownload (tests).
	MaxBytes int64
	// Linux flow and its verification (tests replace these; zero values are the real system).
	LinuxInstallDir string                                                           // default /usr/local/mesh_services/meshagent
	StartupType     func() int                                                       // default DetectStartupType
	SystemdRunDir   string                                                           // default /run/systemd/system
	Systemctl       func(ctx context.Context, args ...string) (out string, exit int) // default runs systemctl
	AgentRunning    func(exe string) bool                                            // non-systemd: is the installed binary running (default /proc scan)
	InitScriptPaths []string                                                         // non-systemd registration files (default /etc/init.d/meshagent, /etc/init/meshagent.conf)
	VerifyTimeout   time.Duration                                                    // default 30s
	PollInterval    time.Duration                                                    // default 1s
}

// Result is what the installer learned. It is saved (mesh_agent.json) and later
// reported to the server in the check-in. It never holds the group id or a token.
type Result struct {
	AttemptedAt string `json:"attempted_at"`
	Installed   bool   `json:"installed"`
	ExitCode    int    `json:"exit_code,omitempty"`
	Error       string `json:"error,omitempty"`
	Log         string `json:"log,omitempty"`
	SHA256      string `json:"sha256,omitempty"`
	AgentID     int    `json:"agent_id,omitempty"`
	Host        string `json:"host,omitempty"`
}

// Install downloads and runs the Mesh agent installer. It NEVER panics and never
// returns an error: the outcome is the Result. The caller treats a failed Result
// as a warning only.
func Install(ctx context.Context, spec *Spec, o InstallOptions) (res Result) {
	if o.Log == nil {
		o.Log = slog.Default()
	}
	now := time.Now
	if o.Now != nil {
		now = o.Now
	}
	res.AttemptedAt = now().UTC().Format(time.RFC3339)
	res.Host = spec.Host()
	defer func() {
		if r := recover(); r != nil {
			res.Installed, res.Error = false, "internal error while installing the Mesh agent"
		}
		res.Error = clip(spec.redact(res.Error), 300)
		res.Log = clip(spec.redact(res.Log), logTail)
	}()
	fail := func(format string, a ...any) Result {
		res.Installed = false
		res.Error = fmt.Sprintf(format, a...)
		o.Log.Warn("the Mesh agent was not installed (the RivetIT agent install is not affected)", "reason", spec.redact(res.Error), "mesh_host", res.Host)
		return res
	}
	id, ok := spec.AgentID(o.GOOS, o.GOARCH)
	if !ok {
		return fail("no Mesh agent id is known for %s/%s", o.GOOS, o.GOARCH)
	}
	res.AgentID = id
	if o.GOOS == "linux" {
		return installLinux(ctx, spec, o, id, &res, fail)
	}
	args := spec.Args(o.GOOS)
	if len(args) == 0 {
		return fail("no install arguments are known for %s", o.GOOS)
	}
	max := o.MaxBytes
	if max <= 0 {
		max = MaxDownload
	}
	dctx, cancel := context.WithTimeout(ctx, orDur(o.DownloadTimeout, 5*time.Minute))
	defer cancel()
	body, err := download(dctx, spec, id, o, max)
	if err != nil {
		return fail("%s", err.Error())
	}
	if err := checkMagic(o.GOOS, body); err != nil {
		return fail("%s", err.Error())
	}
	sum := sha256.Sum256(body)
	res.SHA256 = hex.EncodeToString(sum[:])

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
	name := "meshagent"
	if o.GOOS == "windows" {
		name = "MeshAgent.exe"
	}
	exe := filepath.Join(dir, name)
	if err := os.WriteFile(exe, body, 0o700); err != nil {
		return fail("cannot save the downloaded installer: %v", err)
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
	res.Installed = true
	o.Log.Info("the Mesh agent installer finished", "mesh_host", res.Host, "agent_id", id, "sha256", res.SHA256)
	return res
}

func orDur(d, def time.Duration) time.Duration {
	if d > 0 {
		return d
	}
	return def
}

func clip(s string, n int) string {
	s = strings.ToValidUTF8(s, "?")
	if len(s) > n {
		s = s[len(s)-n:]
		s = strings.ToValidUTF8(s, "")
	}
	return strings.TrimSpace(s)
}

// checkMagic refuses something that cannot be an installer for this OS (an HTML
// error page served with status 200, for instance).
func checkMagic(goos string, b []byte) error {
	switch goos {
	case "windows":
		if len(b) < 2 || b[0] != 'M' || b[1] != 'Z' {
			return errors.New("the download is not a Windows program")
		}
	default:
		if !(len(b) >= 4 && string(b[:4]) == "\x7fELF") && !(len(b) >= 2 && string(b[:2]) == "#!") {
			return errors.New("the download is not a Linux program or script")
		}
	}
	return nil
}

// PinMatches reports whether the DER certificate hashes to the pin (SHA-256 for
// 64 hex characters, SHA-384 for 96), compared in constant time.
func PinMatches(pin string, der []byte) bool {
	var sum []byte
	switch len(pin) {
	case 64:
		s := sha256.Sum256(der)
		sum = s[:]
	case 96:
		s := sha512.Sum384(der)
		sum = s[:]
	default:
		return false
	}
	want, err := hex.DecodeString(pin)
	if err != nil {
		return false
	}
	return subtle.ConstantTimeCompare(sum, want) == 1
}

func newClient(spec *Spec) *http.Client {
	cfg := &tls.Config{MinVersion: tls.VersionTLS12}
	if spec.Pin != "" {
		// A pinned server is trusted by its certificate hash alone (a Mesh server
		// usually has a self-signed certificate), so chain verification is replaced
		// by the pin check. Both run on every handshake, resumed or not.
		cfg.InsecureSkipVerify = true //nolint:gosec // replaced by the pin check below
		cfg.VerifyConnection = func(cs tls.ConnectionState) error {
			if len(cs.PeerCertificates) == 0 || !PinMatches(spec.Pin, cs.PeerCertificates[0].Raw) {
				return errors.New("the Mesh server certificate does not match the pinned hash")
			}
			return nil
		}
	}
	tr := &http.Transport{Proxy: http.ProxyFromEnvironment, TLSClientConfig: cfg, TLSHandshakeTimeout: 20 * time.Second, DisableKeepAlives: true}
	return &http.Client{Transport: tr, CheckRedirect: func(req *http.Request, via []*http.Request) error {
		if len(via) >= 3 {
			return errors.New("too many redirects")
		}
		if req.URL.Scheme != "https" || !strings.EqualFold(req.URL.Host, via[0].URL.Host) {
			return errors.New("the Mesh server redirected to another address; refused")
		}
		return nil
	}}
}

func download(ctx context.Context, spec *Spec, id int, o InstallOptions, max int64) ([]byte, error) {
	b, status, err := fetch(ctx, spec, spec.DownloadURL(id), o, max)
	if err != nil {
		return nil, err
	}
	if status != http.StatusOK {
		return nil, fmt.Errorf("the Mesh server answered HTTP %d", status)
	}
	return b, nil
}

// fetch GETs u over https (pinned or system roots, redirects to the same host
// only, size capped). A non-200 answer returns its status with no body and no error.
func fetch(ctx context.Context, spec *Spec, u string, o InstallOptions, max int64) ([]byte, int, error) {
	if !strings.HasPrefix(u, "https://") { // belt and braces: Validate already guarantees it
		return nil, 0, errors.New("refusing a non-https Mesh address")
	}
	cl := o.HTTPClient
	if cl == nil {
		cl = newClient(spec)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return nil, 0, errors.New("invalid Mesh download address")
	}
	req.Header.Set("User-Agent", "RivetIT-Agent")
	resp, err := cl.Do(req)
	if err != nil {
		return nil, 0, fmt.Errorf("download failed: %s", scrubErr(err, spec))
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, resp.StatusCode, nil
	}
	if ct := strings.ToLower(resp.Header.Get("Content-Type")); strings.HasPrefix(ct, "text/html") {
		return nil, 0, errors.New("the Mesh server sent a web page instead of the file (check the device group id)")
	}
	if resp.ContentLength > max {
		return nil, 0, fmt.Errorf("the download is larger than the %d KiB limit", max>>10)
	}
	b, err := io.ReadAll(io.LimitReader(resp.Body, max+1))
	if err != nil {
		return nil, 0, fmt.Errorf("download failed: %s", scrubErr(err, spec))
	}
	if int64(len(b)) > max {
		return nil, 0, fmt.Errorf("the download is larger than the %d KiB limit", max>>10)
	}
	if len(b) == 0 {
		return nil, 0, errors.New("the Mesh server sent an empty file")
	}
	return b, http.StatusOK, nil
}

// scrubErr turns a transport error into text without the request URL (which
// holds the group id).
func scrubErr(err error, spec *Spec) string {
	var ue *url.Error
	if errors.As(err, &ue) && ue.Err != nil {
		err = ue.Err // drop "Get <url>:" altogether
	}
	return spec.redact(err.Error())
}

type tailBuf struct{ b []byte }

func (t *tailBuf) Write(p []byte) (int, error) {
	t.b = append(t.b, p...)
	if len(t.b) > logTail {
		t.b = t.b[len(t.b)-logTail:]
	}
	return len(p), nil
}

// RunProgram is the real RunFunc: it runs path with args in dir and returns the exit code and the tail of the output.
func RunProgram(ctx context.Context, dir, path string, args []string) (int, string, error) {
	c := exec.CommandContext(ctx, path, args...)
	c.Dir = dir
	var out tailBuf
	c.Stdout, c.Stderr = &out, &out
	c.WaitDelay = 2 * time.Second // a grandchild holding the pipes open must not hold us past the timeout
	err := c.Run()
	var ee *exec.ExitError
	switch {
	case ctx.Err() != nil:
		return -1, string(bytes.TrimSpace(out.b)), errors.New("timed out")
	case err == nil:
		return 0, string(bytes.TrimSpace(out.b)), nil
	case errors.As(err, &ee):
		return ee.ExitCode(), string(bytes.TrimSpace(out.b)), nil
	}
	return -1, string(bytes.TrimSpace(out.b)), err
}
