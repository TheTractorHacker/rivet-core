package agent

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/collect"
	"rivetit-agent/internal/store"
)

// offer is the server side of the capability handshake: what the next response says.
type offer struct {
	mu       sync.Mutex
	features []string
	resync   []string
}

func (o *offer) set(features, resync []string) {
	o.mu.Lock()
	o.features, o.resync = features, resync
	o.mu.Unlock()
}

// serve installs a check-in handler that answers 200 with the current offer.
func (o *offer) serve(r *rig) {
	r.s.mu.Lock()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		o.mu.Lock()
		defer o.mu.Unlock()
		resp := map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "update": nil,
			"server_time": time.Now().UTC().Format(time.RFC3339)}
		if o.features != nil {
			resp["features"] = o.features
		}
		if o.resync != nil {
			resp["resync"] = o.resync
		}
		return 200, resp, nil
	}
	r.s.mu.Unlock()
}

func swRig(t *testing.T) (*rig, *offer) {
	t.Helper()
	r := newRig(t)
	r.clk = &fakeClock{t: time.Date(2026, 10, 10, 12, 0, 0, 0, time.UTC)}
	r.a = r.newAgent()
	r.enroll()
	o := &offer{}
	o.serve(r)
	return r, o
}

func pkg(name, version string) collect.SoftwareItem {
	return collect.SoftwareItem{Name: name, Version: version, Publisher: "Pub", Source: collect.SrcDpkg}
}

// cycle samples, checks in once and returns the decoded request the server saw.
func (r *rig) cycle() map[string]any {
	r.t.Helper()
	n := len(r.s.checkinBodies())
	r.a.sample(context.Background())
	if out := r.a.checkIn(context.Background(), 0); !out.ok {
		r.t.Fatalf("check-in failed: %+v", out)
	}
	bodies := r.s.checkinBodies()
	if len(bodies) != n+1 {
		r.t.Fatalf("expected one request, saw %d", len(bodies)-n)
	}
	return decodeCheckin(r.t, bodies[n])
}

func swBlock(t *testing.T, req map[string]any) map[string]any {
	t.Helper()
	b, ok := req["software"].(map[string]any)
	if !ok {
		t.Fatalf("no software block in %v", keysOf(req))
	}
	return b
}

func keysOf(m map[string]any) []string {
	var k []string
	for x := range m {
		k = append(k, x)
	}
	slices.Sort(k)
	return k
}

func names(items any) string {
	var out []string
	for _, i := range items.([]any) {
		m := i.(map[string]any)
		out = append(out, fmt.Sprintf("%s@%s", m["name"], m["version"]))
	}
	return strings.Join(out, ",")
}

func TestCapabilitiesAnnounceSoftware(t *testing.T) {
	caps := Capabilities()
	if !slices.Contains(caps, "software_inventory") {
		t.Fatalf("%v", caps)
	}
	if !slices.IsSorted(caps) {
		t.Fatalf("not sorted: %v", caps)
	}
}

func TestSoftwareServerThatNeverOffersGetsNoBlock(t *testing.T) {
	r, _ := swRig(t)
	r.fp.setSoftware(pkg("curl", "1"))
	for i := 0; i < 3; i++ {
		r.clk.Advance(2 * time.Hour)
		req := r.cycle()
		if _, has := req["software"]; has {
			t.Fatal("a software block went to a server that never offered it")
		}
		// the request format is exactly the pre-Phase-1 one
		want := []string{"agent_version", "arch", "buffered", "capabilities", "checks", "collected_at", "metrics", "platform", "seq"}
		got := keysOf(req)
		if i > 0 {
			want = append(want, "inventory")
			slices.Sort(want)
			got = slices.DeleteFunc(got, func(k string) bool { return k == "inventory" })
			want = slices.DeleteFunc(want, func(k string) bool { return k == "inventory" })
		} else {
			got = slices.DeleteFunc(got, func(k string) bool { return k == "inventory" })
		}
		if fmt.Sprint(got) != fmt.Sprint(want) {
			t.Fatalf("keys %v, want %v", got, want)
		}
	}
	if r.fp.softwareCalls() != 0 {
		t.Fatal("software must not even be collected before the server asks")
	}
	st, _ := r.st.LoadState()
	if st.SoftwareHash != "" || len(st.ServerFeatures) != 0 {
		t.Fatalf("%+v", st)
	}
	if _, err := os.Stat(r.st.SoftwarePath()); err == nil {
		t.Fatal("snapshot file without a report")
	}
	// the struct itself marshals without the key
	b, _ := json.Marshal(api.CheckinRequest{})
	if strings.Contains(string(b), "software") {
		t.Fatalf("%s", b)
	}
}

func TestSoftwareFullThenNothingThenDelta(t *testing.T) {
	r, o := swRig(t)
	r.fp.setSoftware(pkg("curl", "1.0"), pkg("bash", "5.0"), pkg("nano", "7.0"))

	req := r.cycle() // the first response carries no features yet
	if _, has := req["software"]; has {
		t.Fatal("software before any offer")
	}
	o.set([]string{"software_inventory"}, nil)
	req = r.cycle() // response 1 had no features; this request still has none...
	if _, has := req["software"]; has {
		t.Fatal("the offer arrives in the response to THIS request")
	}
	req = r.cycle() // ...so the third one is the first report
	b := swBlock(t, req)
	if b["mode"] != "full" || b["count"].(float64) != 3 || b["truncated"] != false || b["base_hash"] != nil {
		t.Fatalf("%v", b)
	}
	if names(b["items"]) != "bash@5.0,curl@1.0,nano@7.0" {
		t.Fatalf("items %v", names(b["items"]))
	}
	first := collect.SoftwareHash([]collect.SoftwareItem{pkg("curl", "1.0"), pkg("bash", "5.0"), pkg("nano", "7.0")})
	if b["hash"] != first {
		t.Fatalf("hash %v, want %s", b["hash"], first)
	}
	it := b["items"].([]any)[0].(map[string]any)
	if it["publisher"] != "Pub" || it["source"] != "dpkg" {
		t.Fatalf("%v", it)
	}
	st, _ := r.st.LoadState()
	if st.SoftwareHash != first || st.SoftwareFullAt.IsZero() || st.SoftwareSentAt.IsZero() || !slices.Contains(st.ServerFeatures, "software_inventory") {
		t.Fatalf("state %+v", st)
	}
	fi, err := os.Stat(r.st.SoftwarePath())
	if err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("snapshot file: %v %v", fi, err)
	}

	// unchanged, and within the hour: nothing
	r.clk.Advance(10 * time.Minute)
	if _, has := r.cycle()["software"]; has {
		t.Fatal("nothing changed")
	}
	calls := r.fp.softwareCalls()
	// an hour later it is collected again but, unchanged, still not sent
	r.clk.Advance(61 * time.Minute)
	if _, has := r.cycle()["software"]; has {
		t.Fatal("unchanged list must not be sent")
	}
	if r.fp.softwareCalls() != calls+1 {
		t.Fatalf("collected %d times, want %d", r.fp.softwareCalls(), calls+1)
	}
	// ...and not collected more often than hourly
	r.clk.Advance(5 * time.Minute)
	r.fp.setSoftware(pkg("curl", "1.1"), pkg("bash", "5.0"), pkg("git", "2.0")) // changed, but too early to notice
	if _, has := r.cycle()["software"]; has {
		t.Fatal("collected more than once an hour")
	}
	// the change is picked up at the next hourly collection: a delta
	r.clk.Advance(61 * time.Minute)
	b = swBlock(t, r.cycle())
	if b["mode"] != "delta" || b["base_hash"] != first {
		t.Fatalf("%v", b)
	}
	if names(b["items"]) != "curl@1.1,git@2.0" {
		t.Fatalf("delta items %v", names(b["items"]))
	}
	rm := b["removed"].([]any)
	if len(rm) != 1 || rm[0].(map[string]any)["name"] != "nano" || rm[0].(map[string]any)["source"] != "dpkg" {
		t.Fatalf("removed %v", rm)
	}
	second := collect.SoftwareHash([]collect.SoftwareItem{pkg("curl", "1.1"), pkg("bash", "5.0"), pkg("git", "2.0")})
	if b["hash"] != second || b["count"].(float64) != 3 {
		t.Fatalf("hash %v want %s count %v", b["hash"], second, b["count"])
	}
	// a delta after the delta chains on the new hash
	r.clk.Advance(61 * time.Minute)
	r.fp.setSoftware(pkg("curl", "1.1"), pkg("bash", "5.0"))
	b = swBlock(t, r.cycle())
	if b["mode"] != "delta" || b["base_hash"] != second || len(b["items"].([]any)) != 0 {
		t.Fatalf("%v", b)
	}
	if _, has := b["items"].([]any); !has {
		t.Fatal("items must be an array, never null")
	}
	// at least once a day a full list, even unchanged
	r.clk.Advance(25 * time.Hour)
	b = swBlock(t, r.cycle())
	if b["mode"] != "full" {
		t.Fatalf("%v", b)
	}
}

func TestSoftwareBigDeltaBecomesFull(t *testing.T) {
	r, o := swRig(t)
	var base []collect.SoftwareItem
	for i := 0; i < 400; i++ {
		base = append(base, pkg(fmt.Sprintf("p%04d", i), "1"))
	}
	r.fp.setSoftware(base...)
	o.set([]string{"software_inventory"}, nil)
	r.cycle() // this response carries the offer
	if swBlock(t, r.cycle())["mode"] != "full" {
		t.Fatal("first report is full")
	}
	small := append([]collect.SoftwareItem(nil), base...)
	for i := 0; i < 300; i++ {
		small[i].Version = "2"
	}
	r.fp.setSoftware(small...)
	r.clk.Advance(61 * time.Minute)
	if b := swBlock(t, r.cycle()); b["mode"] != "delta" || len(b["items"].([]any)) != 300 {
		t.Fatalf("300 entries still make a delta: %v", b["mode"])
	}
	big := append([]collect.SoftwareItem(nil), small...)
	for i := 0; i < 301; i++ {
		big[i].Version = "3"
	}
	r.fp.setSoftware(big...)
	r.clk.Advance(61 * time.Minute)
	if b := swBlock(t, r.cycle()); b["mode"] != "full" || len(b["items"].([]any)) != 400 {
		t.Fatalf("301 entries must be a full list: %v", b["mode"])
	}
}

func TestSoftwareResyncIsThrottled(t *testing.T) {
	r, o := swRig(t)
	r.fp.setSoftware(pkg("curl", "1"))
	o.set([]string{"software_inventory"}, nil)
	r.cycle()             // this response carries the offer
	swBlock(t, r.cycle()) // full, acked at T
	calls := r.fp.softwareCalls()

	o.set([]string{"software_inventory"}, []string{"software"})
	r.clk.Advance(time.Minute)
	if _, has := r.cycle()["software"]; has { // response of this request carries the resync
		t.Fatal("no block yet")
	}
	r.clk.Advance(time.Minute)
	if _, has := r.cycle()["software"]; has {
		t.Fatal("a resync is not honoured within 15 minutes of the previous report")
	}
	r.clk.Advance(5 * time.Minute)
	if _, has := r.cycle()["software"]; has {
		t.Fatal("still throttled")
	}
	r.clk.Advance(10 * time.Minute) // 17 minutes after the report
	b := swBlock(t, r.cycle())
	if b["mode"] != "full" || b["base_hash"] != nil {
		t.Fatalf("%v", b)
	}
	if r.fp.softwareCalls() != calls {
		t.Fatalf("the resync reuses the list collected within the hour (%d -> %d calls)", calls, r.fp.softwareCalls())
	}
	// the server still asks (it was shed again): throttled from THIS report on
	r.clk.Advance(time.Minute)
	if _, has := r.cycle()["software"]; has {
		t.Fatal("throttled again")
	}
	// the server stops asking: nothing is sent
	o.set([]string{"software_inventory"}, nil)
	r.clk.Advance(30 * time.Minute)
	r.cycle()
	r.clk.Advance(time.Minute)
	if _, has := r.cycle()["software"]; has {
		t.Fatal("no resync pending any more")
	}
}

func TestSoftwareRestartBetweenBuildAndAckForcesFull(t *testing.T) {
	r, o := swRig(t)
	r.fp.setSoftware(pkg("curl", "1"), pkg("bash", "5"))
	o.set([]string{"software_inventory"}, nil)
	r.cycle()             // this response carries the offer
	swBlock(t, r.cycle()) // acked full
	r.fp.setSoftware(pkg("curl", "2"), pkg("bash", "5"))
	r.clk.Advance(61 * time.Minute)

	// the delta is built, the response is lost
	r.s.mu.Lock()
	inner := r.s.checkinFn
	r.s.checkinFn = func(n int, b []byte) (int, any, http.Header) { return -1, nil, nil }
	r.s.mu.Unlock()
	r.a.sample(context.Background())
	if out := r.a.checkIn(context.Background(), 0); out.ok {
		t.Fatal("expected a failed attempt")
	}
	bodies := r.s.checkinBodies()
	lost := bodies[len(bodies)-1]
	if swBlock(t, decodeCheckin(t, lost))["mode"] != "delta" {
		t.Fatal("setup: a delta was expected")
	}
	// process restarts; the persisted body is replayed byte for byte
	r.s.mu.Lock()
	r.s.checkinFn = inner
	r.s.mu.Unlock()
	r.a = r.newAgent()
	if out := r.a.checkIn(context.Background(), 0); !out.ok {
		t.Fatal("replay failed")
	}
	bodies = r.s.checkinBodies()
	if string(bodies[len(bodies)-1]) != string(lost) {
		t.Fatal("the replayed body must be byte-identical")
	}
	st, _ := r.st.LoadState()
	if st.SoftwareHash != "" {
		t.Fatalf("the snapshot must be dropped: %q", st.SoftwareHash)
	}
	if _, err := os.Stat(r.st.SoftwarePath()); err == nil {
		t.Fatal("snapshot file must be removed")
	}
	// the next report is a full list even though nothing changed since
	r.clk.Advance(61 * time.Minute)
	if b := swBlock(t, r.cycle()); b["mode"] != "full" {
		t.Fatalf("%v", b["mode"])
	}
}

func TestSoftwareRetryWithoutRestartKeepsDeltaChain(t *testing.T) {
	r, o := swRig(t)
	r.fp.setSoftware(pkg("curl", "1"))
	o.set([]string{"software_inventory"}, nil)
	r.cycle() // this response carries the offer
	first := swBlock(t, r.cycle())
	r.fp.setSoftware(pkg("curl", "2"))
	r.clk.Advance(61 * time.Minute)
	r.s.mu.Lock()
	inner := r.s.checkinFn
	r.s.checkinFn = func(n int, b []byte) (int, any, http.Header) {
		return 503, map[string]any{"error": "x", "code": "unavailable"}, nil
	}
	r.s.mu.Unlock()
	r.a.sample(context.Background())
	if out := r.a.checkIn(context.Background(), 0); out.ok {
		t.Fatal("503 must fail")
	}
	r.s.mu.Lock()
	r.s.checkinFn = inner
	r.s.mu.Unlock()
	if out := r.a.checkIn(context.Background(), 1); !out.ok {
		t.Fatal("retry failed")
	}
	st, _ := r.st.LoadState()
	want := collect.SoftwareHash([]collect.SoftwareItem{pkg("curl", "2")})
	if st.SoftwareHash != want || st.SoftwareHash == first["hash"] {
		t.Fatalf("snapshot after the retry: %q want %q", st.SoftwareHash, want)
	}
	bodies := r.s.checkinBodies()
	if string(bodies[len(bodies)-1]) != string(bodies[len(bodies)-2]) {
		t.Fatal("retry must be byte-identical")
	}
}

func TestSoftwareStopsWhenFeatureWithdrawnAndSurvivesRestart(t *testing.T) {
	r, o := swRig(t)
	r.fp.setSoftware(pkg("curl", "1"))
	o.set([]string{"software_inventory"}, nil)
	r.cycle() // this response carries the offer
	swBlock(t, r.cycle())

	// a restart must not forget the offer: a changed list is reported as a delta right away
	r.fp.setSoftware(pkg("curl", "2"))
	r.clk.Advance(61 * time.Minute)
	r.a = r.newAgent()
	if b := swBlock(t, r.cycle()); b["mode"] != "delta" {
		t.Fatalf("%v", b["mode"])
	}

	// the server stops offering: this response clears the flag...
	o.set(nil, nil)
	r.fp.setSoftware(pkg("curl", "3"))
	r.clk.Advance(61 * time.Minute)
	if _, has := r.cycle()["software"]; !has {
		t.Fatal("the last offer was still valid for this request")
	}
	st, _ := r.st.LoadState()
	if len(st.ServerFeatures) != 0 {
		t.Fatalf("features %v", st.ServerFeatures)
	}
	// ...and nothing is sent any more, however much changes
	for i := 0; i < 3; i++ {
		r.fp.setSoftware(pkg("curl", fmt.Sprint(10+i)))
		r.clk.Advance(61 * time.Minute)
		if _, has := r.cycle()["software"]; has {
			t.Fatal("software sent after the feature was withdrawn")
		}
	}
	// and a restart keeps it off
	r.a = r.newAgent()
	r.clk.Advance(2 * time.Hour)
	if _, has := r.cycle()["software"]; has {
		t.Fatal("restart revived the feature")
	}
}

func TestSoftwareCollectionProblemsSendNothing(t *testing.T) {
	r, o := swRig(t)
	o.set([]string{"software_inventory"}, nil)
	r.fp.mu.Lock()
	r.fp.swErr = collect.ErrUnsupported
	r.fp.mu.Unlock()
	r.cycle()
	for i := 0; i < 3; i++ {
		if _, has := r.cycle()["software"]; has {
			t.Fatal("unsupported platform sent a block")
		}
	}
	if r.fp.softwareCalls() != 1 {
		t.Fatalf("retried within the hour: %d calls", r.fp.softwareCalls())
	}
	r.fp.mu.Lock()
	r.fp.swErr = fmt.Errorf("dpkg exploded")
	r.fp.mu.Unlock()
	r.clk.Advance(61 * time.Minute)
	if _, has := r.cycle()["software"]; has {
		t.Fatal("a failed collection sent a block")
	}
	// recovers at the next hourly try
	r.fp.mu.Lock()
	r.fp.swErr = nil
	r.sw()
	r.fp.mu.Unlock()
	r.clk.Advance(61 * time.Minute)
	if swBlock(t, r.cycle())["mode"] != "full" {
		t.Fatal("expected a full report after recovery")
	}
}

func (r *rig) sw() { r.fp.sw = []collect.SoftwareItem{pkg("curl", "1")} }

func TestSoftwareCorruptSnapshotMeansFull(t *testing.T) {
	r, o := swRig(t)
	r.fp.setSoftware(pkg("curl", "1"), pkg("bash", "5"))
	o.set([]string{"software_inventory"}, nil)
	r.cycle() // this response carries the offer
	swBlock(t, r.cycle())
	// tamper with the snapshot: the re-hash no longer matches State.SoftwareHash
	var sn swSnapshot
	if ok, err := store.ReadJSON(r.st.SoftwarePath(), &sn); !ok || err != nil {
		t.Fatal(err)
	}
	sn.Items = sn.Items[:1]
	if err := store.WriteJSON(r.st.SoftwarePath(), sn); err != nil {
		t.Fatal(err)
	}
	r.fp.setSoftware(pkg("curl", "2"), pkg("bash", "5"))
	r.clk.Advance(61 * time.Minute)
	if b := swBlock(t, r.cycle()); b["mode"] != "full" {
		t.Fatalf("%v", b["mode"])
	}
	// unreadable garbage as well
	if err := os.WriteFile(r.st.SoftwarePath(), []byte("{not json"), 0o600); err != nil {
		t.Fatal(err)
	}
	r.fp.setSoftware(pkg("curl", "3"), pkg("bash", "5"))
	r.clk.Advance(61 * time.Minute)
	if b := swBlock(t, r.cycle()); b["mode"] != "full" {
		t.Fatalf("%v", b["mode"])
	}
}

// The check-in body cap of the server is 1 MiB: the biggest software block the
// agent can build, with a full replay of buffered samples and the inventory,
// must stay below it.
func TestSoftwareBodyStaysUnderServerCap(t *testing.T) {
	for _, tc := range []struct {
		name         string
		count, nameN int
		publisher    string
	}{
		{"max-length text", 5000, 40, strings.Repeat("P&é", 70)},
		{"3000 long names", 3000, 14, "Some Publisher Inc."},
	} {
		t.Run(tc.name, func(t *testing.T) { sizeCase(t, tc.count, tc.nameN, tc.publisher) })
	}
}

func sizeCase(t *testing.T, count, nameN int, publisher string) {
	r, o := swRig(t)
	var items []collect.SoftwareItem
	for i := 0; i < count; i++ {
		items = append(items, collect.SoftwareItem{
			Name: fmt.Sprintf("%05d-", i) + strings.Repeat("N<&>é", nameN), Version: strings.Repeat("v", 100),
			Publisher: publisher, Source: collect.SrcRegistry, Installed: "2026-01-02"})
	}
	r.fp.setSoftware(items...)
	o.set([]string{"software_inventory"}, nil)
	r.cycle() // this response carries the offer
	r.st.ClearInflight()
	r.a.ring.RemoveThrough(^uint64(0))
	for i := 0; i < 150; i++ { // 99 buffered + the latest
		r.a.sample(context.Background())
	}
	r.a.invAt, r.a.invPending = time.Time{}, nil
	_ = r.st.Update(func(s *store.State) error { s.InventoryHash = ""; return nil }) // inventory in this body too
	in, err := r.a.buildInflight(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	var req api.CheckinRequest
	if err := json.Unmarshal(in.Body, &req); err != nil {
		t.Fatal(err)
	}
	if req.Software == nil || req.Software.Mode != "full" || !req.Software.Truncated {
		t.Fatalf("expected a truncated full report: %+v", req.Software)
	}
	if len(req.Software.Items) > collect.MaxSoftwareItems || req.Software.Count != len(req.Software.Items) {
		t.Fatalf("items %d count %d", len(req.Software.Items), req.Software.Count)
	}
	if req.Inventory == nil || len(req.Buffered) < 99 {
		t.Fatalf("setup: inventory=%v buffered=%d", req.Inventory != nil, len(req.Buffered))
	}
	t.Logf("worst case body: %d bytes (%d software items, %d buffered)", len(in.Body), len(req.Software.Items), len(req.Buffered))
	if len(in.Body) >= 1<<20 {
		t.Fatalf("check-in body %d bytes reaches the server cap", len(in.Body))
	}
	if got := collect.SoftwareHash(req.Software.Items); got != req.Software.Hash {
		t.Fatal("hash must describe the list that was sent")
	}
}
