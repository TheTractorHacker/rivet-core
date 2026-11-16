package main

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"

	"rivetit-agent/internal/store"
)

func serverURLOf(t *testing.T, state string) string {
	t.Helper()
	st, err := store.Open(state)
	if err != nil {
		t.Fatal(err)
	}
	cfg, err := st.LoadConfig()
	if err != nil {
		t.Fatal(err)
	}
	return cfg.ServerURL
}

// CORE-DS-14: re-enrolling a device that already has a credential to a DIFFERENT server must not leave the new server_url behind when the exchange
// fails, or the old server's device token would be sent to the new host.
func TestFailedReenrollToAnotherServerKeepsTheOldServer(t *testing.T) {
	srvA := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srvA, nil)
	if code := e.setup(); code != 0 {
		t.Fatal(code)
	}
	oldCA, _ := os.ReadFile(filepath.Join(e.state, "ca.pem"))
	srvB := newEnrollSrv(t)
	srvB.mode.Store(1) // rejects the enrollment token
	e.stampWith(srvB, nil)
	if code := e.setup(); code == 0 {
		t.Fatal("setup against a rejecting server succeeded")
	}
	if got := serverURLOf(t, e.state); got != srvA.URL {
		t.Fatalf("server_url is %s after a failed re-enroll, want the old %s", got, srvA.URL)
	}
	if ca, _ := os.ReadFile(filepath.Join(e.state, "ca.pem")); string(ca) != string(oldCA) {
		t.Fatal("the old server's CA was overwritten")
	}
}

// A first enrollment (no credential yet) still keeps the new configuration when the server is only temporarily unavailable.
func TestFirstInstallKeepsItsConfigWhenEnrollmentRejected(t *testing.T) {
	srv := newEnrollSrv(t)
	srv.mode.Store(1)
	e := newEnv(t)
	e.stampWith(srv, nil)
	e.setup()
	if got := serverURLOf(t, e.state); got != srv.URL {
		t.Fatalf("first install lost its configuration: %q", got)
	}
}

// CORE-DS-48: setup pins the installer file (and refuses to continue when it cannot) before it parses it.
func TestSetupPinsTheInstallerForTheWholeRun(t *testing.T) {
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	var pinned []string
	released := 0
	ops := e.ops()
	ops.pinFile = func(p string) (func(), error) {
		pinned = append(pinned, p)
		return func() { released++ }, nil
	}
	args := []string{"--silent", "--no-service", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}
	if code := runSetup(args, ops, time.Now); code != 0 {
		t.Fatal(code)
	}
	if len(pinned) != 1 || pinned[0] != e.exe || released != 1 {
		t.Fatalf("pinned %v released %d", pinned, released)
	}
	ops.pinFile = func(string) (func(), error) { return nil, errors.New("sharing violation") }
	if code := runSetup(args, ops, time.Now); code != exitInstallFail {
		t.Fatalf("exit %d when the installer cannot be pinned", code)
	}
}

// CORE-DS-14 (review CR8): moving to another server WITHOUT a token (an MSI upgrade with a changed SERVER property) must not pair the old credential
// with the new server_url either.
func TestMovingToAnotherServerWithoutATokenIsRefused(t *testing.T) {
	srvA := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srvA, nil)
	if code := e.setup(); code != 0 {
		t.Fatal(code)
	}
	if code := e.setup("--from-msi", "--server", "https://other-server.example"); code == 0 {
		t.Fatal("setup moved an enrolled device to another server without a token")
	}
	if got := serverURLOf(t, e.state); got != srvA.URL {
		t.Fatalf("server_url is %s, want the old %s", got, srvA.URL)
	}
}

// CORE-DS-14 (review CRR-6): a PENDING one-shot enrollment token (offline install) counts as a credential too: moving to another server without a new
// token must not leave the old token next to the new server_url.
func TestPendingEnrollTokenIsNotSentToANewServer(t *testing.T) {
	e := newEnv(t)
	st, err := store.Open(e.state)
	if err != nil {
		t.Fatal(err)
	}
	if err := st.SaveConfig(store.Config{ServerURL: "https://old-server.example"}); err != nil {
		t.Fatal(err)
	}
	if err := st.SaveEnrollToken(setupToken); err != nil {
		t.Fatal(err)
	}
	srvB := newEnrollSrv(t)
	e.stampWith(srvB, nil)
	if code := e.setup("--from-msi", "--server", "https://new-server.example"); code == 0 {
		t.Fatal("setup moved a device holding a pending enrollment token to another server without a token")
	}
	if got := serverURLOf(t, e.state); got != "https://old-server.example" {
		t.Fatalf("server_url is %s", got)
	}
}
