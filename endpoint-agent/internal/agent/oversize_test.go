package agent

import (
	"context"
	"strings"
	"testing"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/buffer"
	"rivetit-agent/internal/store"
)

func fatChecks(n int) []api.CheckResult {
	out := make([]api.CheckResult, n)
	for i := range out {
		out[i] = api.CheckResult{Key: "k" + strings.Repeat("x", 90) + string(rune('a'+i%26)), Status: "ok", Detail: strings.Repeat("d", 300)}
	}
	return out
}

// CORE-DS-2: a full ring of fat samples must never produce a body over the request cap.
func TestBuildInflightStaysUnderTheRequestCap(t *testing.T) {
	r := newRig(t)
	r.enroll()
	// a ring whose own byte cap is larger than the request cap (the ring cap is configurable; software and inventory ride in the same body)
	r.a.ring = buffer.Open(r.st.BufferPath(), 100, 8<<20)
	for i := 0; i < 100; i++ {
		if _, err := r.a.ring.Append(r.a.stamp(), nil, fatChecks(50)); err != nil {
			t.Fatal(err)
		}
	}
	in, err := r.a.buildInflight(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(in.Body) >= api.MaxRequestBytes {
		t.Fatalf("body %d bytes is over the %d cap", len(in.Body), api.MaxRequestBytes)
	}
	if len(in.Body) < 1000 {
		t.Fatalf("body suspiciously small: %d", len(in.Body))
	}
}

// CORE-DS-2: an oversized inflight.json written by an older agent is dropped and rebuilt, not resent forever.
func TestOversizedPersistedInflightIsDroppedAndRebuilt(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.a.sample(context.Background())
	if err := r.st.SaveInflight(store.Inflight{Seq: 999, Body: []byte(`{"x":"` + strings.Repeat("a", api.MaxRequestBytes+10) + `"}`)}); err != nil {
		t.Fatal(err)
	}
	before := len(r.s.checkinBodies())
	r.a.checkIn(context.Background(), 0)
	if got := len(r.s.checkinBodies()); got != before+1 {
		t.Fatalf("no check-in reached the server (%d -> %d): the oversized body is wedging the agent", before, got)
	}
}
