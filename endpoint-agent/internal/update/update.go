package update

import (
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"crypto/subtle"
	"debug/elf"
	"debug/pe"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"runtime"
	"strings"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/store"
)

const (
	MaxBinaryBytes   = 128 << 20
	DefaultProbation = 10 * time.Minute
	MaxStartAttempts = 3
)

var (
	ErrNotNewer     = errors.New("offered version is not newer than the running version")
	ErrDowngrade    = errors.New("downgrade refused")
	ErrIncompatible = errors.New("running version is older than the update's min_version; intermediate update required")
)

// Validate performs every check that needs no download: sane fields, https
// and an allowed host, no downgrade, min_version compatibility, and the
// ed25519 signature over the sha256 hex string.
func Validate(cur string, m api.UpdateManifest, pub ed25519.PublicKey, allowedHosts []string) error {
	if m.Version == "" || m.URL == "" || m.SHA256 == "" || m.Signature == "" {
		return errors.New("incomplete update manifest")
	}
	c, err := CompareVersions(m.Version, cur)
	if err != nil {
		return fmt.Errorf("update version: %w", err)
	}
	if c == 0 {
		return ErrNotNewer
	}
	if c < 0 {
		return ErrDowngrade
	}
	if m.MinVersion != "" {
		mc, err := CompareVersions(cur, m.MinVersion)
		if err != nil {
			return fmt.Errorf("min_version: %w", err)
		}
		if mc < 0 {
			return ErrIncompatible
		}
	}
	u, err := url.Parse(m.URL)
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil {
		return errors.New("update URL must be an https URL without credentials")
	}
	ok := false
	for _, h := range allowedHosts {
		if strings.EqualFold(h, u.Host) || strings.EqualFold(h, u.Hostname()) {
			ok = true
		}
	}
	if !ok {
		return fmt.Errorf("update host %q is not an allowed update host", u.Host)
	}
	want := strings.ToLower(m.SHA256)
	if b, err := hex.DecodeString(want); err != nil || len(b) != sha256.Size {
		return errors.New("update sha256 must be 64 hex characters")
	}
	if len(pub) != ed25519.PublicKeySize {
		return errors.New("no pinned signing key")
	}
	sig, err := base64.StdEncoding.DecodeString(strings.TrimSpace(m.Signature))
	if err != nil || len(sig) != ed25519.SignatureSize {
		return errors.New("update signature undecodable")
	}
	// The signature covers the sha256 HEX STRING (lower case), per contract.
	if !ed25519.Verify(pub, []byte(want), sig) {
		return errors.New("update signature invalid")
	}
	return nil
}

// Download fetches the artifact to dest (fixed path), enforcing a size cap and
// the sha256. dest is removed on any failure. bearer is attached only when
// the URL host equals serverHost.
func Download(ctx context.Context, hc *http.Client, m api.UpdateManifest, dest, serverHost, bearer string) error {
	u, _ := url.Parse(m.URL)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, m.URL, nil)
	if err != nil {
		return err
	}
	if bearer != "" && strings.EqualFold(u.Host, serverHost) {
		req.Header.Set("Authorization", "Bearer "+bearer)
	}
	c := *hc
	c.Timeout = 15 * time.Minute
	resp, err := c.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("download: HTTP %d", resp.StatusCode)
	}
	if resp.ContentLength > MaxBinaryBytes {
		return errors.New("download exceeds size cap")
	}
	_ = os.Remove(dest)
	f, err := os.OpenFile(dest, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o700)
	if err != nil {
		return err
	}
	fail := func(e error) error { f.Close(); _ = os.Remove(dest); return e }
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, MaxBinaryBytes+1))
	if err != nil {
		return fail(err)
	}
	if n > MaxBinaryBytes {
		return fail(errors.New("download exceeds size cap"))
	}
	got := hex.EncodeToString(h.Sum(nil))
	if subtle.ConstantTimeCompare([]byte(got), []byte(strings.ToLower(m.SHA256))) != 1 {
		return fail(errors.New("sha256 mismatch"))
	}
	if err := f.Sync(); err != nil {
		return fail(err)
	}
	return f.Close()
}

// VerifyBinary is a sanity check that the file is an executable for this
// OS/arch (PE machine type on Windows, ELF machine on Linux). It is NOT a
// trust decision: trust comes from the sha256 + ed25519 checks.
func VerifyBinary(path string) error {
	switch runtime.GOOS {
	case "windows":
		f, err := pe.Open(path)
		if err != nil {
			return fmt.Errorf("not a valid PE executable: %w", err)
		}
		defer f.Close()
		want := map[string]uint16{"amd64": pe.IMAGE_FILE_MACHINE_AMD64, "arm64": pe.IMAGE_FILE_MACHINE_ARM64}[runtime.GOARCH]
		if want == 0 || f.Machine != want {
			return fmt.Errorf("PE machine 0x%x does not match %s", f.Machine, runtime.GOARCH)
		}
	case "linux":
		f, err := elf.Open(path)
		if err != nil {
			return fmt.Errorf("not a valid ELF executable: %w", err)
		}
		defer f.Close()
		want := map[string]elf.Machine{"amd64": elf.EM_X86_64, "arm64": elf.EM_AARCH64}[runtime.GOARCH]
		if want == 0 || f.Machine != want {
			return fmt.Errorf("ELF machine %v does not match %s", f.Machine, runtime.GOARCH)
		}
	}
	return nil
}

// SelfTest runs `<binary> selftest` and requires it to report want.
func SelfTest(ctx context.Context, path, want string) error {
	r := jobs.RunBounded(ctx, jobs.ExecSpec{Name: path, Args: []string{"selftest"}, Timeout: 20 * time.Second, MaxOutput: 1024})
	if !r.HaveExit || r.ExitCode != 0 {
		return fmt.Errorf("staged binary selftest failed (exit=%d start=%v)", r.ExitCode, r.StartErr)
	}
	if !strings.Contains(r.Output, "version="+want) {
		return fmt.Errorf("staged binary reports a different version: %q", strings.TrimSpace(r.Output))
	}
	return nil
}

// ProbationState (update.json) tracks an update between swap and health
// confirmation.
type ProbationState struct {
	Phase       string    `json:"phase"` // probation | rolled_back
	FromVersion string    `json:"from_version"`
	ToVersion   string    `json:"to_version"`
	Deadline    time.Time `json:"deadline"`
	Attempts    int       `json:"attempts"`
	Reason      string    `json:"reason,omitempty"`
}

// Manager drives the update lifecycle.
type Manager struct {
	Paths     Paths
	Version   string
	StatePath string
	Now       func() time.Time
	Probation time.Duration
	// Injectable for tests; defaults are the real implementations.
	VerifyFn   func(path string) error
	SelfTestFn func(ctx context.Context, path, want string) error
}

func (m *Manager) now() time.Time {
	if m.Now != nil {
		return m.Now()
	}
	return time.Now()
}

func (m *Manager) load() (*ProbationState, error) {
	var s ProbationState
	ok, err := store.ReadJSON(m.StatePath, &s)
	if err != nil {
		_ = os.Remove(m.StatePath)
		return nil, nil
	}
	if !ok {
		return nil, nil
	}
	return &s, nil
}

// InProbation reports whether an update is awaiting health confirmation.
func (m *Manager) InProbation() bool {
	s, _ := m.load()
	return s != nil && s.Phase == "probation"
}

// Apply validates, downloads, verifies, stages and swaps. restart==true means
// the caller must exit so the supervisor starts the new binary.
func (m *Manager) Apply(ctx context.Context, man api.UpdateManifest, pub ed25519.PublicKey, hc *http.Client, serverHost, bearer string, allowedHosts []string) (restart bool, err error) {
	if m.InProbation() {
		return false, nil
	}
	if err := Validate(m.Version, man, pub, allowedHosts); err != nil {
		if errors.Is(err, ErrNotNewer) {
			return false, nil
		}
		return false, err
	}
	if err := ensureParent(m.Paths.Staged); err != nil {
		return false, err
	}
	if err := Download(ctx, hc, man, m.Paths.Staged, serverHost, bearer); err != nil {
		return false, err
	}
	fail := func(e error) (bool, error) { _ = os.Remove(m.Paths.Staged); return false, e }
	vf, st := m.VerifyFn, m.SelfTestFn
	if vf == nil {
		vf = VerifyBinary
	}
	if st == nil {
		st = SelfTest
	}
	if err := vf(m.Paths.Staged); err != nil {
		return fail(err)
	}
	if err := st(ctx, m.Paths.Staged, man.Version); err != nil {
		return fail(err)
	}
	// keep the installed binary's permission bits (the download is created 0700; an
	// installed agent is usually 0755 root-owned) and make sure it stays executable
	if fi, err := os.Stat(m.Paths.Current); err == nil {
		_ = os.Chmod(m.Paths.Staged, fi.Mode().Perm()|0o100)
	}
	prob := m.Probation
	if prob == 0 {
		prob = DefaultProbation
	}
	ps := ProbationState{Phase: "probation", FromVersion: m.Version, ToVersion: man.Version, Deadline: m.now().Add(prob)}
	if err := store.WriteJSON(m.StatePath, ps); err != nil {
		return fail(err)
	}
	if err := Swap(m.Paths); err != nil {
		_ = os.Remove(m.StatePath)
		return fail(err)
	}
	return true, nil
}

// Failure describes a failed update to be reported as the agent_update check.
type Failure = store.UpdateFailure

// OnStart must run first thing at process start. It enforces the probation
// rules for a freshly swapped binary (crash-loop counter and deadline) and,
// when the previous process rolled back, reports the failure exactly once.
func (m *Manager) OnStart() (rolledBack bool, fail *Failure, err error) {
	s, _ := m.load()
	if s == nil {
		Cleanup(Paths{Staged: m.Paths.Staged, Failed: m.Paths.Failed})
		return false, nil, nil
	}
	switch {
	case s.Phase == "rolled_back":
		_ = os.Remove(m.StatePath)
		return false, &Failure{Version: s.ToVersion, Reason: s.Reason, At: m.now()}, nil
	case s.Phase == "probation" && m.Version == s.ToVersion:
		s.Attempts++
		if s.Attempts > MaxStartAttempts {
			return m.rollback(s, fmt.Sprintf("new version restarted %d times without confirming health", s.Attempts-1))
		}
		if !m.now().Before(s.Deadline) {
			return m.rollback(s, "new version did not check in before the health deadline")
		}
		return false, nil, store.WriteJSON(m.StatePath, s)
	default:
		// We are not the version the state describes (e.g. old binary after a
		// manual rollback): drop it.
		_ = os.Remove(m.StatePath)
		return false, nil, nil
	}
}

func (m *Manager) rollback(s *ProbationState, reason string) (bool, *Failure, error) {
	if err := Rollback(m.Paths); err != nil {
		// Nothing to roll back to; report and keep running.
		_ = os.Remove(m.StatePath)
		return false, &Failure{Version: s.ToVersion, Reason: reason + "; rollback impossible: " + err.Error(), At: m.now()}, err
	}
	s.Phase, s.Reason = "rolled_back", reason
	if err := store.WriteJSON(m.StatePath, s); err != nil {
		return true, &Failure{Version: s.ToVersion, Reason: reason, At: m.now()}, err
	}
	return true, nil, nil
}

// Watchdog is called periodically while in probation: if the deadline passed
// without Confirm, roll back and tell the caller to exit.
func (m *Manager) Watchdog() (rolledBack bool, err error) {
	s, _ := m.load()
	if s == nil || s.Phase != "probation" || m.Version != s.ToVersion || m.now().Before(s.Deadline) {
		return false, nil
	}
	rb, _, err := m.rollback(s, "new version did not check in before the health deadline")
	return rb, err
}

// Confirm marks the new version healthy (first successful check-in). The
// previous binary stays as the last good.
func (m *Manager) Confirm() error {
	s, _ := m.load()
	if s == nil || s.Phase != "probation" {
		return nil
	}
	return os.Remove(m.StatePath)
}
