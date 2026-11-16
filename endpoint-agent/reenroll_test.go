package main

import (
	"os"
	"path/filepath"
	"testing"

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
