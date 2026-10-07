package agent

import (
	"context"
	"testing"

	"rivetit-agent/internal/collect"
	"rivetit-agent/internal/store"
)

// TestPayloadSizes documents (and bounds) the check-in body sizes used in
// docs/ENDPOINT_AGENT_BUILD.md. Run with -v to see the numbers.
func TestPayloadSizes(t *testing.T) {
	r := newRig(t)
	r.enroll()
	ctx := context.Background()
	r.a.sample(ctx)
	in, err := r.a.buildInflight(ctx)
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("first check-in (with inventory, 0 buffered): %d bytes", len(in.Body))
	r.st.ClearInflight()
	r.a.ring.RemoveThrough(^uint64(0))
	r.a.invAt = r.a.o.Now() // inventory just sent
	r.a.invPending = nil
	_ = r.st.Update(func(s *store.State) error {
		s.InventoryHash = collect.InventoryHash(r.a.col.Inventory(ctx))
		s.InventorySentAt = r.a.o.Now()
		return nil
	})
	r.a.sample(ctx)
	in, _ = r.a.buildInflight(ctx)
	t.Logf("steady-state check-in (no inventory, 1 sample): %d bytes", len(in.Body))
	r.st.ClearInflight()
	for i := 0; i < 150; i++ {
		r.a.sample(ctx)
	}
	in, _ = r.a.buildInflight(ctx)
	t.Logf("replay check-in (99 buffered samples): %d bytes", len(in.Body))
	if len(in.Body) > 200<<10 {
		t.Fatalf("replay payload unexpectedly large: %d", len(in.Body))
	}
}
