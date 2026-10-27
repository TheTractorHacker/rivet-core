package buffer

import (
	"fmt"
	"path/filepath"
	"testing"

	"rivetit-agent/internal/api"
)

func f(v float64) *float64 { return &v }

func TestCountBoundDropsOldest(t *testing.T) {
	r := Open("", 5, 1<<20)
	for i := 0; i < 12; i++ {
		r.Append(fmt.Sprint(i), &api.Metrics{CPUPct: f(float64(i))}, nil)
	}
	s := r.Snapshot()
	if len(s) != 5 || s[0].CollectedAt != "7" || s[4].CollectedAt != "11" {
		t.Fatalf("unexpected ring %v", s)
	}
	if r.Dropped != 7 {
		t.Fatalf("dropped=%d", r.Dropped)
	}
}

func TestByteBoundDropsOldest(t *testing.T) {
	r := Open("", 1000, 2000)
	pad := make([]api.CheckResult, 1)
	for i := 0; i < 40; i++ {
		pad[0] = api.CheckResult{Key: "k", Status: "ok", Detail: fmt.Sprintf("%0200d", i)}
		r.Append(fmt.Sprint(i), nil, append([]api.CheckResult(nil), pad...))
	}
	s := r.Snapshot()
	total := 0
	for _, e := range s {
		total += e.size
	}
	if total > 2000 || len(s) == 0 || s[len(s)-1].CollectedAt != "39" {
		t.Fatalf("bytes=%d entries=%d", total, len(s))
	}
}

func TestReplayOrderAndPersistence(t *testing.T) {
	p := filepath.Join(t.TempDir(), "b.json")
	r := Open(p, 100, 1<<20)
	var ids []uint64
	for i := 0; i < 5; i++ {
		id, err := r.Append(fmt.Sprint(i), nil, nil)
		if err != nil {
			t.Fatal(err)
		}
		ids = append(ids, id)
	}
	r2 := Open(p, 100, 1<<20) // simulates restart
	s := r2.Snapshot()
	if len(s) != 5 {
		t.Fatalf("len=%d", len(s))
	}
	for i, e := range s {
		if e.CollectedAt != fmt.Sprint(i) {
			t.Fatalf("order broken at %d: %v", i, e)
		}
	}
	if err := r2.RemoveThrough(ids[2]); err != nil {
		t.Fatal(err)
	}
	s = Open(p, 100, 1<<20).Snapshot()
	if len(s) != 2 || s[0].CollectedAt != "3" {
		t.Fatalf("after ack: %v", s)
	}
	// ids never reused after restart
	id, _ := Open(p, 100, 1<<20).Append("x", nil, nil)
	if id <= ids[4] {
		t.Fatalf("id reused: %d", id)
	}
}

func TestCorruptFileIgnored(t *testing.T) {
	p := filepath.Join(t.TempDir(), "b.json")
	if err := writeBytes(p, "{not json"); err != nil {
		t.Fatal(err)
	}
	if n := Open(p, 10, 1000).Len(); n != 0 {
		t.Fatal("expected empty ring")
	}
}

func writeBytes(p, s string) error { return osWrite(p, []byte(s)) }
