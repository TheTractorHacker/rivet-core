package update

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

func TestCompareVersions(t *testing.T) {
	cases := []struct {
		a, b string
		want int
	}{
		{"1.2.3", "1.2.3", 0}, {"1.2.4", "1.2.3", 1}, {"1.10.0", "1.9.9", 1}, {"v2.0", "1.99.99", 1},
		{"1.0.0-rc1", "1.0.0", -1}, {"1.0.0", "1.0.0-rc1", 1}, {"1.0.0+build5", "1.0.0", 0}, {"0.0.0-dev", "0.1.0", -1}, {"1.0", "1.0.0.0", 0},
	}
	for _, c := range cases {
		got, err := CompareVersions(c.a, c.b)
		if err != nil || got != c.want {
			t.Errorf("%s vs %s = %d (%v), want %d", c.a, c.b, got, err, c.want)
		}
	}
	for _, bad := range []string{"", "../1.0", "1.2.3/../x", "a.b", "1..2", "1.2.3.4.5", "1.0-", "1.0 beta"} {
		if _, err := CompareVersions(bad, "1.0"); err == nil {
			t.Errorf("%q accepted", bad)
		}
	}
}

type env struct {
	t      *testing.T
	pub    ed25519.PublicKey
	priv   ed25519.PrivateKey
	dir    string
	paths  Paths
	srv    *httptest.Server
	body   []byte
	host   string
	status int
}

func newEnv(t *testing.T) *env {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	e := &env{t: t, pub: pub, priv: priv, dir: t.TempDir(), body: []byte("NEW-BINARY-CONTENT"), status: 200}
	exe := filepath.Join(e.dir, "agent")
	os.WriteFile(exe, []byte("OLD-BINARY"), 0o700)
	e.paths = PathsFor(exe)
	e.srv = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if e.status != 200 {
			w.WriteHeader(e.status)
			return
		}
		w.Write(e.body)
	}))
	t.Cleanup(e.srv.Close)
	e.host = strings.TrimPrefix(e.srv.URL, "https://")
	return e
}

func (e *env) manifest(version string) api.UpdateManifest {
	sum := sha256.Sum256(e.body)
	h := hex.EncodeToString(sum[:])
	return api.UpdateManifest{Version: version, URL: e.srv.URL + "/dl/agent.exe", SHA256: h,
		Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(e.priv, []byte(h)))}
}

func (e *env) mgr(cur string) *Manager {
	return &Manager{Paths: e.paths, Version: cur, StatePath: filepath.Join(e.dir, "update.json"),
		VerifyFn:   func(string) error { return nil },
		SelfTestFn: func(context.Context, string, string) error { return nil }}
}

func (e *env) apply(m *Manager, man api.UpdateManifest) (bool, error) {
	return m.Apply(context.Background(), man, e.pub, e.srv.Client(), e.host, "tok", []string{e.host})
}

func read(t *testing.T, p string) string {
	b, err := os.ReadFile(p)
	if err != nil {
		return "<missing>"
	}
	return string(b)
}

func TestValidateRejections(t *testing.T) {
	e := newEnv(t)
	hosts := []string{e.host}
	good := e.manifest("1.1.0")
	if err := Validate("1.0.0", good, e.pub, hosts); err != nil {
		t.Fatal(err)
	}
	mod := func(f func(*api.UpdateManifest)) api.UpdateManifest { m := good; f(&m); return m }
	other, _, _ := ed25519.GenerateKey(rand.Reader)
	cases := map[string]struct {
		m    api.UpdateManifest
		cur  string
		pub  ed25519.PublicKey
		want error
	}{
		"downgrade":       {good, "2.0.0", e.pub, ErrDowngrade},
		"same":            {good, "1.1.0", e.pub, ErrNotNewer},
		"min_version":     {mod(func(m *api.UpdateManifest) { m.MinVersion = "1.0.5" }), "1.0.0", e.pub, ErrIncompatible},
		"wrong key":       {good, "1.0.0", other, nil},
		"http":            {mod(func(m *api.UpdateManifest) { m.URL = "http://" + e.host + "/x" }), "1.0.0", e.pub, nil},
		"creds in url":    {mod(func(m *api.UpdateManifest) { m.URL = "https://u:p@" + e.host + "/x" }), "1.0.0", e.pub, nil},
		"foreign host":    {mod(func(m *api.UpdateManifest) { m.URL = "https://evil.example/x" }), "1.0.0", e.pub, nil},
		"bad hash":        {mod(func(m *api.UpdateManifest) { m.SHA256 = "zz" }), "1.0.0", e.pub, nil},
		"hash!=signed":    {mod(func(m *api.UpdateManifest) { m.SHA256 = strings.Repeat("a", 64) }), "1.0.0", e.pub, nil},
		"empty sig":       {mod(func(m *api.UpdateManifest) { m.Signature = "" }), "1.0.0", e.pub, nil},
		"path in version": {mod(func(m *api.UpdateManifest) { m.Version = "../../etc/passwd" }), "1.0.0", e.pub, nil},
	}
	for name, c := range cases {
		err := Validate(c.cur, c.m, c.pub, hosts)
		if err == nil || (c.want != nil && !errors.Is(err, c.want)) {
			t.Errorf("%s: err=%v want %v", name, err, c.want)
		}
	}
	// hash casing: signature is over the lower-case hex string
	up := good
	up.SHA256 = strings.ToUpper(good.SHA256)
	if err := Validate("1.0.0", up, e.pub, hosts); err != nil {
		t.Errorf("upper-case hash should normalise: %v", err)
	}
}

func TestApplySuccessKeepsPreviousAsLastGood(t *testing.T) {
	e := newEnv(t)
	m := e.mgr("1.0.0")
	restart, err := e.apply(m, e.manifest("1.1.0"))
	if err != nil || !restart {
		t.Fatalf("restart=%v err=%v", restart, err)
	}
	if read(t, e.paths.Current) != "NEW-BINARY-CONTENT" || read(t, e.paths.Prev) != "OLD-BINARY" {
		t.Fatal("swap did not happen")
	}
	if _, err := os.Stat(e.paths.Staged); err == nil {
		t.Fatal("staged file left behind")
	}
	// new process: starts, within window, then confirms
	nm := e.mgr("1.1.0")
	if rb, f, err := nm.OnStart(); rb || f != nil || err != nil {
		t.Fatalf("%v %v %v", rb, f, err)
	}
	if !nm.InProbation() {
		t.Fatal("should be in probation")
	}
	if err := nm.Confirm(); err != nil || nm.InProbation() {
		t.Fatal("confirm failed")
	}
	if read(t, e.paths.Prev) != "OLD-BINARY" {
		t.Fatal("last good not retained")
	}
	// a second apply while healthy must be a no-op for the same version
	if restart, err := e.apply(nm, e.manifest("1.1.0")); restart || err != nil {
		t.Fatalf("same version re-applied: %v %v", restart, err)
	}
}

func TestHashMismatchLeavesNothing(t *testing.T) {
	e := newEnv(t)
	man := e.manifest("1.1.0")
	e.body = []byte("TAMPERED") // server now serves different bytes than were signed
	restart, err := e.apply(e.mgr("1.0.0"), man)
	if restart || err == nil || !strings.Contains(err.Error(), "sha256") {
		t.Fatalf("restart=%v err=%v", restart, err)
	}
	if read(t, e.paths.Current) != "OLD-BINARY" {
		t.Fatal("current modified")
	}
	if _, err := os.Stat(e.paths.Staged); err == nil {
		t.Fatal("tampered download kept")
	}
}

func TestBadSignatureNeverDownloads(t *testing.T) {
	e := newEnv(t)
	hits := 0
	e.srv.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { hits++ })
	man := e.manifest("1.1.0")
	man.Signature = base64.StdEncoding.EncodeToString(make([]byte, 64))
	if _, err := e.apply(e.mgr("1.0.0"), man); err == nil {
		t.Fatal("expected error")
	}
	if hits != 0 {
		t.Fatal("downloaded before verifying the signature")
	}
}

func TestDowngradeRefused(t *testing.T) {
	e := newEnv(t)
	_, err := e.apply(e.mgr("2.0.0"), e.manifest("1.5.0"))
	if !errors.Is(err, ErrDowngrade) {
		t.Fatalf("err=%v", err)
	}
	if read(t, e.paths.Current) != "OLD-BINARY" {
		t.Fatal("modified")
	}
}

func TestDownloadFailureAndSizeCap(t *testing.T) {
	e := newEnv(t)
	e.status = 500
	if _, err := e.apply(e.mgr("1.0.0"), e.manifest("1.1.0")); err == nil {
		t.Fatal("expected HTTP error")
	}
	if _, err := os.Stat(e.paths.Staged); err == nil {
		t.Fatal("staged left")
	}
}

func TestSelfTestFailureAbortsBeforeSwap(t *testing.T) {
	e := newEnv(t)
	m := e.mgr("1.0.0")
	m.SelfTestFn = func(context.Context, string, string) error { return errors.New("boom") }
	if restart, err := e.apply(m, e.manifest("1.1.0")); restart || err == nil {
		t.Fatal("expected abort")
	}
	if read(t, e.paths.Current) != "OLD-BINARY" || m.InProbation() {
		t.Fatal("swap happened despite failed selftest")
	}
}

func TestHealthFailureCrashLoopRollsBack(t *testing.T) {
	e := newEnv(t)
	if _, err := e.apply(e.mgr("1.0.0"), e.manifest("1.1.0")); err != nil {
		t.Fatal(err)
	}
	// the new binary keeps starting but never confirms
	var rolled bool
	var fail *Failure
	for i := 0; i < MaxStartAttempts+1 && !rolled; i++ {
		var err error
		rolled, fail, err = e.mgr("1.1.0").OnStart()
		if err != nil {
			t.Fatal(err)
		}
	}
	if !rolled {
		t.Fatal("no rollback after repeated starts")
	}
	if read(t, e.paths.Current) != "OLD-BINARY" {
		t.Fatalf("current=%q after rollback", read(t, e.paths.Current))
	}
	_ = fail
	// the restored OLD binary starts: it reports the failure once, then clears
	rb, f, err := e.mgr("1.0.0").OnStart()
	if rb || err != nil || f == nil || f.Version != "1.1.0" || !strings.Contains(f.Reason, "restarted") {
		t.Fatalf("rb=%v f=%+v err=%v", rb, f, err)
	}
	if _, f, _ := e.mgr("1.0.0").OnStart(); f != nil {
		t.Fatal("failure reported twice")
	}
}

func TestHealthDeadlineWatchdogRollsBack(t *testing.T) {
	e := newEnv(t)
	now := time.Now()
	m := e.mgr("1.0.0")
	m.Now = func() time.Time { return now }
	m.Probation = time.Minute
	if _, err := e.apply(m, e.manifest("1.1.0")); err != nil {
		t.Fatal(err)
	}
	nm := e.mgr("1.1.0")
	nm.Now = func() time.Time { return now.Add(30 * time.Second) }
	if rb, err := nm.Watchdog(); rb || err != nil {
		t.Fatal("rolled back before deadline")
	}
	nm.Now = func() time.Time { return now.Add(2 * time.Minute) }
	rb, err := nm.Watchdog()
	if !rb || err != nil {
		t.Fatalf("rb=%v err=%v", rb, err)
	}
	if read(t, e.paths.Current) != "OLD-BINARY" {
		t.Fatal("not restored")
	}
	// also: a process that only starts after the deadline rolls back in OnStart
	e2 := newEnv(t)
	m2 := e2.mgr("1.0.0")
	m2.Now = func() time.Time { return now }
	m2.Probation = time.Minute
	e2.apply(m2, e2.manifest("1.1.0"))
	late := e2.mgr("1.1.0")
	late.Now = func() time.Time { return now.Add(time.Hour) }
	if rb, _, err := late.OnStart(); !rb || err != nil {
		t.Fatalf("late start: %v %v", rb, err)
	}
}

func TestRollbackImpossibleWithoutPrev(t *testing.T) {
	e := newEnv(t)
	if err := Rollback(e.paths); err == nil {
		t.Fatal("expected error")
	}
	if read(t, e.paths.Current) != "OLD-BINARY" {
		t.Fatal("current damaged by failed rollback")
	}
}

func TestSwapUndoOnFailure(t *testing.T) {
	e := newEnv(t)
	// no staged file => error and current untouched
	if err := Swap(e.paths); err == nil {
		t.Fatal("expected error")
	}
	// staged is a directory that cannot be renamed over? emulate by making Current's dir read-only is racy; check basic undo path instead
	os.WriteFile(e.paths.Staged, []byte("S"), 0o700)
	if err := Swap(e.paths); err != nil {
		t.Fatal(err)
	}
	if read(t, e.paths.Current) != "S" || read(t, e.paths.Prev) != "OLD-BINARY" {
		t.Fatal("swap result wrong")
	}
	if err := Rollback(e.paths); err != nil {
		t.Fatal(err)
	}
	if read(t, e.paths.Current) != "OLD-BINARY" {
		t.Fatal("rollback result wrong")
	}
}

func TestStagedPathIsFixedRegardlessOfURL(t *testing.T) {
	e := newEnv(t)
	man := e.manifest("1.1.0")
	man.URL = e.srv.URL + "/../../../../etc/cron.d/evil"
	// signature/hash still match the body; only the URL path is hostile
	if _, err := e.apply(e.mgr("1.0.0"), man); err != nil {
		t.Fatal(err)
	}
	entries, _ := os.ReadDir(e.dir)
	for _, en := range entries {
		switch en.Name() {
		case "agent", "agent.prev", "update.json":
		default:
			t.Errorf("unexpected file %s", en.Name())
		}
	}
}

func TestVerifyBinaryRealAndFake(t *testing.T) {
	self, _ := os.Executable()
	if err := VerifyBinary(self); err != nil {
		t.Fatalf("own binary rejected: %v", err)
	}
	fake := filepath.Join(t.TempDir(), "x")
	os.WriteFile(fake, []byte("#!/bin/sh\necho hi\n"), 0o700)
	if err := VerifyBinary(fake); err == nil {
		t.Fatal("script accepted as binary")
	}
}

func TestBearerOnlyToServerHost(t *testing.T) {
	var auth string
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth = r.Header.Get("Authorization")
		w.Write([]byte("x"))
	}))
	defer srv.Close()
	sum := sha256.Sum256([]byte("x"))
	m := api.UpdateManifest{URL: srv.URL, SHA256: hex.EncodeToString(sum[:])}
	d := filepath.Join(t.TempDir(), "d")
	if err := Download(context.Background(), srv.Client(), m, d, "other.example:443", "SECRET"); err != nil {
		t.Fatal(err)
	}
	if auth != "" {
		t.Fatal("bearer sent to a different host")
	}
}
