package collect

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/store"
)

// CORE-DS-17: the server validates params.script exactly; the agent must not
// run a case-variant member the server never looked at.
func TestScriptCheckRefusesCaseVariantParams(t *testing.T) {
	c := newCol(newFake())
	c.VerifyCheck = func(store.CheckSpec) error { return nil }
	r := c.RunCheck(context.Background(), store.CheckSpec{Key: "k", Type: "script",
		Params: json.RawMessage(`{"script":"exit 0","Script":"exit 2"}`)})
	if r.Status != Unknown || !strings.Contains(r.Detail, "invalid script check parameters") {
		t.Fatalf("case-variant script member was honoured: %+v", r)
	}
}

func TestServiceAndDiskChecksRefuseCaseVariantParams(t *testing.T) {
	f := newFake()
	c := newCol(f)
	if r := c.RunCheck(context.Background(), xspec("service", `{"name":"a","Name":"b"}`)); r.Status != Unknown {
		t.Fatalf("service: %+v", r)
	}
	if r := c.RunCheck(context.Background(), xspec("disk", `{"mount":"/","Mount":"/x"}`)); r.Status != Unknown {
		t.Fatalf("disk: %+v", r)
	}
}

// CORE-DS-8 (agent half): keys the server owns (log.rule_*, change.rule_*,
// patch.*, hv.*) are never reported by the agent, in any letter case, even if
// a delivered check list names one.
func TestRunnerNeverReportsServerOwnedKeys(t *testing.T) {
	f := newFake()
	r := NewCheckRunner(newCol(f))
	specs := []store.CheckSpec{
		{Key: "LOG.RULE_7", Type: "pending_reboot"},
		{Key: "Change.Rule_3", Type: "pending_reboot"},
		{Key: "PATCH.COMPLIANCE", Type: "pending_reboot"},
		{Key: "hv.x", Type: "pending_reboot"},
		{Key: "reboot", Type: "pending_reboot"},
	}
	out := r.Run(context.Background(), specs, time.Unix(1000, 0))
	if len(out) != 1 || out[0].Key != "reboot" {
		t.Fatalf("got %+v", out)
	}
}
