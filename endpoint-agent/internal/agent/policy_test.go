package agent

import (
	"net/http"
	"runtime"
	"slices"
	"testing"
	"time"

	"rivetit-agent/internal/store"
)

// policyServer answers check-ins with a configurable features list and config.
type policyServer struct {
	features []string
	config   map[string]any
	next     int
}

func (p *policyServer) serve(r *rig) {
	r.s.mu.Lock()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		resp := map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "update": nil, "server_time": time.Now().UTC().Format(time.RFC3339)}
		if p.next > 0 {
			resp["next_check_in_s"] = p.next
		}
		if p.features != nil {
			resp["features"] = p.features
		}
		if p.config != nil {
			resp["config"] = p.config
		}
		return 200, resp, nil
	}
	r.s.mu.Unlock()
}

func TestCapabilitiesAnnouncePolicies(t *testing.T) {
	caps := Capabilities()
	if !slices.Contains(caps, "policies") || !slices.IsSorted(caps) {
		t.Fatalf("%v", caps)
	}
}

func TestPolicyBlockIsStoredShownAndSurvivesRestart(t *testing.T) {
	r, _ := swRig(t)
	ps := &policyServer{features: []string{"policies"}, next: 120, config: map[string]any{
		"checks": []any{map[string]any{"key": "disk_c", "type": "disk", "params": map[string]any{"mount": "C:"}, "interval_s": 90}}, "collect_interval_s": 45,
		"policy": map[string]any{"version": "abcdef0123456789", "applied": true, "features": map[string]bool{"software_inventory": true, "script_checks": false, "checks": true}}}}
	ps.serve(r)
	r.cycle()
	st, _ := r.st.LoadState()
	if st.PolicyVersion != "abcdef0123456789" || !st.PolicyApplied || st.PolicyFeatures["script_checks"] || !st.PolicyFeatures["checks"] {
		t.Fatalf("%+v", st)
	}
	if st.CollectIntervalS != 45 || st.CheckInIntervalS != 120 || len(st.Checks) != 1 || st.Checks[0].IntervalS != 90 {
		t.Fatalf("the policy's intervals and checks are the ordinary config fields: %+v", st)
	}
	if got := Describe(st, store.Config{ServerURL: "https://x.example"}, true); !contains(got, "policy:      abcdef012345 (applied)") {
		t.Fatalf("%s", got)
	}
	// a restart keeps it
	a2 := r.newAgent()
	st2, _ := a2.o.Store.LoadState()
	if st2.PolicyVersion != "abcdef0123456789" {
		t.Fatalf("%+v", st2)
	}
}

func TestPolicyBlockWithoutTheFeatureIsIgnored(t *testing.T) {
	r, _ := swRig(t)
	// a server that sends config.policy but never offers the feature: not applied
	ps := &policyServer{config: map[string]any{"checks": []any{}, "policy": map[string]any{"version": "v1", "applied": true, "features": map[string]bool{"checks": false}}}}
	ps.serve(r)
	r.cycle()
	st, _ := r.st.LoadState()
	if st.PolicyVersion != "" || st.PolicyFeatures != nil {
		t.Fatalf("%+v", st)
	}
}

func TestPolicyIsClearedWhenTheServerStopsSendingIt(t *testing.T) {
	r, _ := swRig(t)
	ps := &policyServer{features: []string{"policies"}, config: map[string]any{"checks": []any{}, "policy": map[string]any{"version": "v1", "applied": true, "features": map[string]bool{"checks": false}}}}
	ps.serve(r)
	r.cycle()
	if st, _ := r.st.LoadState(); st.PolicyVersion != "v1" {
		t.Fatalf("%+v", st)
	}
	ps.config = map[string]any{"checks": []any{}}
	ps.features = nil
	r.cycle()
	if st, _ := r.st.LoadState(); st.PolicyVersion != "" || st.PolicyApplied || st.PolicyFeatures != nil {
		t.Fatalf("%+v", st)
	}
}

func TestApplyPolicyToChecks(t *testing.T) {
	specs := []store.CheckSpec{{Key: "d", Type: "disk"}, {Key: "s", Type: "script"}, {Key: "p", Type: "pending_reboot"}}
	if got := applyPolicyToChecks(specs, nil); len(got) != 3 {
		t.Fatalf("no policy must change nothing: %v", got)
	}
	if got := applyPolicyToChecks(specs, map[string]bool{"script_checks": true, "checks": true}); len(got) != 3 {
		t.Fatalf("%v", got)
	}
	if got := applyPolicyToChecks(specs, map[string]bool{"script_checks": false}); len(got) != 2 || got[0].Key != "d" || got[1].Key != "p" {
		t.Fatalf("%v", got)
	}
	if got := applyPolicyToChecks(specs, map[string]bool{"checks": false, "script_checks": true}); len(got) != 0 {
		t.Fatalf("%v", got)
	}
}

func contains(s, sub string) bool {
	return len(sub) == 0 || (len(s) >= len(sub) && indexOf(s, sub) >= 0)
}

func indexOf(s, sub string) int {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return i
		}
	}
	return -1
}

// A check limited to another OS is never run or reported, whatever the server
// delivered; "any", empty and unknown targets run everywhere.
func TestChecksForOS(t *testing.T) {
	specs := []store.CheckSpec{
		{Key: "w", Type: "service", OS: "windows"}, {Key: "l", Type: "service", OS: "linux"},
		{Key: "a", Type: "service", OS: "any"}, {Key: "e", Type: "disk"}, {Key: "x", Type: "disk", OS: "plan9"},
	}
	keys := func(in []store.CheckSpec) []string {
		var k []string
		for _, c := range in {
			k = append(k, c.Key)
		}
		return k
	}
	if got := keys(checksForOS(specs, "windows")); !slices.Equal(got, []string{"w", "a", "e", "x"}) {
		t.Fatalf("windows: %v", got)
	}
	if got := keys(checksForOS(specs, "linux")); !slices.Equal(got, []string{"l", "a", "e", "x"}) {
		t.Fatalf("linux: %v", got)
	}
	plain := []store.CheckSpec{{Key: "d", Type: "disk"}}
	if got := checksForOS(plain, "linux"); len(got) != 1 || &got[0] != &plain[0] {
		t.Fatalf("an untargeted list must come back untouched: %v", got)
	}
	// The real run path filters for the GOOS the binary was built for.
	other := "windows"
	if runtime.GOOS == "windows" {
		other = "linux"
	}
	got := applyPolicyToChecks([]store.CheckSpec{{Key: "n", Type: "service", OS: other}, {Key: "y", Type: "service", OS: runtime.GOOS}}, nil)
	if len(got) != 1 || got[0].Key != "y" {
		t.Fatalf("applyPolicyToChecks must drop the other OS: %v", got)
	}
}
