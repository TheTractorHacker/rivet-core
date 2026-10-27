package store

import (
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
)

func open(t *testing.T) *Store {
	s, err := Open(filepath.Join(t.TempDir(), "state"))
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func TestDirPermissions(t *testing.T) {
	s := open(t)
	fi, _ := os.Stat(s.Dir)
	if fi.Mode().Perm() != 0o700 {
		t.Fatalf("dir mode %v", fi.Mode().Perm())
	}
}

func TestInstallIDStableAcrossReopen(t *testing.T) {
	s := open(t)
	a, err := s.InstallID()
	if err != nil || len(a) != 36 {
		t.Fatalf("%q %v", a, err)
	}
	s2, _ := Open(s.Dir)
	b, _ := s2.InstallID()
	if a != b {
		t.Fatal("install id changed")
	}
	// an Update closure cannot overwrite it
	s2.Update(func(st *State) error { st.InstallID = "evil"; return nil })
	if c, _ := s.InstallID(); c != a {
		t.Fatal("install id was overwritten")
	}
}

func TestConfigRoundTripAndDefaults(t *testing.T) {
	s := open(t)
	c, _ := s.LoadConfig()
	if c.MaxConcurrentJobs != 1 || c.BufferMaxSamples != 100 || c.BufferMaxBytes != 1<<20 {
		t.Fatalf("defaults %+v", c)
	}
	s.SaveConfig(Config{ServerURL: "https://x", DisableJobs: true})
	c, _ = s.LoadConfig()
	if c.ServerURL != "https://x" || !c.DisableJobs {
		t.Fatal("round trip")
	}
}

func TestTokenFilePermissionsAndFormat(t *testing.T) {
	s := open(t)
	if err := s.SaveToken("devtok_secret_123456"); err != nil {
		t.Fatal(err)
	}
	fi, _ := os.Stat(s.TokenPath())
	if fi.Mode().Perm() != 0o600 {
		t.Fatalf("token mode %v", fi.Mode().Perm())
	}
	if tok, _ := s.LoadToken(); tok != "devtok_secret_123456" {
		t.Fatal("round trip")
	}
	raw, _ := os.ReadFile(s.TokenPath())
	if !strings.HasPrefix(string(raw), "v1:plain:") {
		t.Fatalf("scheme must be explicit: %q", raw)
	}
	// a loosened file is tightened on read and reported
	os.Chmod(s.TokenPath(), 0o644)
	if len(s.CheckPerms()) == 0 {
		t.Fatal("loose permissions not detected")
	}
	s.LoadToken()
	if fi, _ := os.Stat(s.TokenPath()); fi.Mode().Perm() != 0o600 {
		t.Fatal("permissions not tightened")
	}
	// a DPAPI blob is refused on Linux rather than misread
	os.WriteFile(s.TokenPath(), []byte("v1:dpapi:AAAA\n"), 0o600)
	if _, err := s.LoadToken(); err == nil {
		t.Fatal("foreign scheme accepted")
	}
}

func TestWipeToken(t *testing.T) {
	s := open(t)
	s.SaveToken("devtok_secret_123456")
	if err := s.WipeToken(); err != nil {
		t.Fatal(err)
	}
	if tok, _ := s.LoadToken(); tok != "" {
		t.Fatal("token survived wipe")
	}
	if err := s.WipeToken(); err != nil {
		t.Fatal("wipe must be idempotent")
	}
}

func TestAtomicWriteLeavesNoTemp(t *testing.T) {
	s := open(t)
	for i := 0; i < 20; i++ {
		if err := WriteJSON(s.StatePath(), State{Seq: uint64(i)}); err != nil {
			t.Fatal(err)
		}
	}
	es, _ := os.ReadDir(s.Dir)
	for _, e := range es {
		if strings.HasPrefix(e.Name(), ".tmp-") {
			t.Fatalf("temp file left: %s", e.Name())
		}
	}
}

func TestConcurrentUpdatesNoLostWrites(t *testing.T) {
	s := open(t)
	var wg sync.WaitGroup
	// two Store handles = two processes (CLI + service)
	s2, _ := Open(s.Dir)
	for _, st := range []*Store{s, s2} {
		for i := 0; i < 25; i++ {
			wg.Add(1)
			go func(st *Store) {
				defer wg.Done()
				if err := st.Update(func(x *State) error { x.Seq++; return nil }); err != nil {
					t.Error(err)
				}
			}(st)
		}
	}
	wg.Wait()
	if got, _ := s.LoadState(); got.Seq != 50 {
		t.Fatalf("seq %d, lost updates", got.Seq)
	}
}

func TestStaleLockBroken(t *testing.T) {
	s := open(t)
	lock := filepath.Join(s.Dir, "state.lock")
	os.WriteFile(lock, []byte("999999\n"), 0o600)
	old := fileTimeAgo(t, lock)
	_ = old
	if err := s.Update(func(*State) error { return nil }); err != nil {
		t.Fatalf("stale lock not broken: %v", err)
	}
}

func TestInflightRoundTripExactBytes(t *testing.T) {
	s := open(t)
	body := []byte(`{"seq":7,"x":{ "a":1 }}`)
	s.SaveInflight(Inflight{Seq: 7, Body: body, ThroughID: 3})
	in, _ := s.LoadInflight()
	if in == nil || string(in.Body) != string(body) || in.Seq != 7 {
		t.Fatalf("%+v", in)
	}
	os.WriteFile(s.InflightPath(), []byte("{garbage"), 0o600)
	if in, _ := s.LoadInflight(); in != nil {
		t.Fatal("corrupt inflight must be discarded")
	}
}
