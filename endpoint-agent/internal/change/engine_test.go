package change

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

func TestARateBudgetDelaysRecordsInsteadOfDroppingThem(t *testing.T) {
	r := newRig(t, "windows")
	key := `HKLM\SOFTWARE\Acme\Many`
	r.reg.set(key, nil)
	cfg := baseConfig("windows", "1010101010101010", regWatch("custom.many", map[string]any{"key": key}))
	cfg["rate_per_min"] = 10
	cfg["batch_max"] = 4
	if err := r.m.Configure(r.sg.sign(t, cfg)); err != nil {
		t.Fatal(err)
	}
	r.tick()
	var vals []RegValue
	for i := 0; i < 25; i++ {
		vals = append(vals, szVal(fmt.Sprintf("v%02d", i), "1"))
	}
	r.reg.set(key, nil, vals...)
	r.next()
	sent := len(r.records())
	if sent != 10 {
		t.Fatalf("ten in the first minute, in batches of at most 4: %d", sent)
	}
	for _, b := range r.batches() {
		if len(b.Changes) > 4 {
			t.Errorf("batch of %d", len(b.Changes))
		}
	}
	r.clk.Advance(61 * time.Second)
	r.tick()
	r.clk.Advance(61 * time.Second)
	r.tick()
	if got := len(r.records()); got != 25 {
		t.Fatalf("everything arrives, a minute at a time: %d", got)
	}
	seen := map[string]bool{}
	for _, x := range r.records() {
		if seen[x.Item] {
			t.Errorf("duplicate %s", x.Item)
		}
		seen[x.Item] = true
	}
	if r.m.state.Dropped != 0 {
		t.Errorf("dropped %d", r.m.state.Dropped)
	}
}

func TestAFailedSendKeepsTheRecordsAndTheyArePersistedAcrossARestart(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.reg.set(runKey, nil, szVal("Evil", "e.exe"))
	r.sendFn = func([]byte) (*api.ChangeAck, error) {
		return nil, &api.APIError{Status: 503, Code: "unavailable", RetryAfter: 30 * time.Second}
	}
	d := r.next()
	if len(r.m.state.Pending) != 3 {
		t.Fatalf("pending: %d", len(r.m.state.Pending))
	}
	if d < 20*time.Second || d > 40*time.Second {
		t.Errorf("the server's Retry-After is honoured: %s", d)
	}
	r.build() // the agent restarts: the unsent records are still there
	if len(r.m.state.Pending) != 3 {
		t.Fatalf("pending after a restart: %d", len(r.m.state.Pending))
	}
	r.sendFn = nil
	r.m.set(r.m.want)
	cfg := baseConfig("windows", "a1a1a1a1a1a1a1a1", regWatch("win.startup", map[string]any{"key": runKey}))
	if err := r.m.Configure(r.sg.sign(t, cfg)); err != nil {
		t.Fatal(err)
	}
	r.reset()
	r.clk.Advance(2 * time.Minute)
	r.tick()
	if len(r.records()) != 3 || len(r.m.state.Pending) != 0 {
		t.Fatalf("delivered after the restart: %d, pending %d", len(r.records()), len(r.m.state.Pending))
	}
}

func TestAServerThatRejectsABatchDoesNotWedgeTheAgent(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.reg.set(runKey, nil, szVal("Evil", "e.exe"))
	r.sendFn = func([]byte) (*api.ChangeAck, error) {
		return nil, &api.APIError{Status: 422, Code: "invalid", Message: "nope"}
	}
	r.next()
	if len(r.m.state.Pending) != 0 || r.m.state.Dropped == 0 {
		t.Fatalf("a batch the server will never accept is dropped and counted: pending %d dropped %d", len(r.m.state.Pending), r.m.state.Dropped)
	}
	n := r.m.state.Dropped
	r.sendFn = nil
	r.reset()
	r.clk.Advance(30 * time.Second)
	r.tick()
	r.clk.Advance(2 * time.Minute)
	r.tick()
	bs := r.batches()
	if len(bs) == 0 || bs[0].Dropped != n {
		t.Fatalf("the drop is reported to the server: %+v (want %d)", bs, n)
	}
	if r.m.state.Dropped != 0 {
		t.Errorf("and reset once acknowledged: %d", r.m.state.Dropped)
	}
}

func TestADisabledServerPausesAndARevokedOneStops(t *testing.T) {
	r := runRig(t)
	var revoked error
	r.m.o.OnRevoked = func(err error) { revoked = err }
	r.sendFn = func([]byte) (*api.ChangeAck, error) {
		return nil, &api.APIError{Status: 503, Code: "feature_disabled", RetryAfterRaw: time.Hour}
	}
	d := r.tick()
	if d < 15*time.Minute || len(r.batches()) != 1 {
		t.Fatalf("paused for at least 15 minutes: %s", d)
	}
	r.clk.Advance(5 * time.Minute)
	r.tick()
	if len(r.batches()) != 1 {
		t.Error("nothing is sent while paused")
	}
	r.sendFn = func([]byte) (*api.ChangeAck, error) { return nil, &api.APIError{Status: 401, Code: "revoked"} }
	r.clk.Advance(2 * time.Hour)
	r.tick()
	if revoked == nil {
		t.Error("a revoked device is told so")
	}
}

func TestAWithdrawnOfferKeepsTheSnapshotsForADayThenForgetsEverything(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.reg.set(runKey, nil, szVal("Evil", "e.exe"))
	r.sendFn = func([]byte) (*api.ChangeAck, error) { return nil, errors.New("offline") }
	r.next()
	if len(r.m.state.Pending) == 0 {
		t.Fatal("setup")
	}
	r.m.Configure(nil)
	r.tick()
	if r.m.Active() || len(r.m.state.Pending) == 0 || len(r.m.state.Watches) == 0 || r.m.state.WithdrawnAt == "" {
		t.Fatalf("a withdrawn offer stops the reading but keeps what was read (a server under load stops offering for a while): %+v", r.m.state)
	}
	r.sendFn = nil
	r.reset()
	r.clk.Advance(2 * time.Hour)
	r.tick()
	if len(r.batches()) != 0 {
		t.Error("nothing is read or sent while it is not offered")
	}
	// offered again within the day: the snapshots are still good, so a change made in the gap is found
	r.reg.set(runKey, nil, szVal("OneDrive", `C:\Program Files\OneDrive\OneDrive.exe /background`), szVal("Teams", `C:\Teams\update.exe --processStart`), szVal("Evil", "e.exe"), szVal("Gap", "gap.exe"))
	cfg := baseConfig("windows", "a1a1a1a1a1a1a1a1", regWatch("win.startup", map[string]any{"key": runKey}))
	r.m.Configure(r.sg.sign(t, cfg))
	r.tick()
	if x := find(r.records(), "Gap"); x == nil || x.Change != "added" || r.m.state.WithdrawnAt != "" {
		t.Fatalf("%+v", r.records())
	}
	// withdrawn and not back within a day: nothing of what was read stays on disk
	r.m.Configure(nil)
	r.tick()
	r.clk.Advance(25 * time.Hour)
	r.tick()
	if len(r.m.state.Pending) != 0 || len(r.m.state.Watches) != 0 || r.m.state.WithdrawnAt != "" {
		t.Fatalf("%+v", r.m.state)
	}
	if strings.Contains(r.stateText(), "OneDrive") {
		t.Error("nothing of what was read stays on disk")
	}
	// back after that: a fresh baseline, no flood
	r.reset()
	r.reg.set(runKey, nil, szVal("Other", "o.exe"))
	r.m.Configure(r.sg.sign(t, baseConfig("windows", "a1a1a1a1a1a1a1a2", regWatch("win.startup", map[string]any{"key": runKey}))))
	r.tick()
	if len(r.records()) != 0 {
		t.Fatalf("%+v", r.records())
	}
}

func TestAnAgentThatJustStartedHasNotWithdrawnAnything(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.build() // restart: no offer has arrived yet, the persisted snapshots must survive until one does
	r.tick()
	r.clk.Advance(30 * time.Hour)
	r.tick()
	if len(r.m.state.Watches) != 1 {
		t.Fatalf("a restart before the first check-in is not a withdrawal: %+v", r.m.state.Watches)
	}
}

func TestTheLocalKillSwitchForgetsEverythingAtOnce(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.reg.set(runKey, nil, szVal("Evil", "e.exe"))
	r.sendFn = func([]byte) (*api.ChangeAck, error) { return nil, errors.New("offline") }
	r.next()
	r.m.Wipe()
	r.tick()
	if r.m.Active() || len(r.m.state.Pending) != 0 || len(r.m.state.Watches) != 0 || strings.Contains(r.stateText(), "OneDrive") {
		t.Fatalf("%+v", r.m.state)
	}
	saved := r.stateText()
	r.m.Wipe()
	r.tick()
	if r.stateText() != saved {
		t.Error("wiping nothing writes nothing")
	}
}

func TestAnExplicitFeatureDisabledAnswerForgetsEverything(t *testing.T) {
	r := runRig(t)
	r.sendFn = func([]byte) (*api.ChangeAck, error) { return nil, &api.APIError{Status: 503, Code: "feature_disabled"} }
	r.tick()
	if len(r.m.state.Watches) != 0 {
		t.Fatalf("the server says the feature is off: nothing is kept: %+v", r.m.state.Watches)
	}
	// module_disabled is a pause, not a wipe
	r2 := runRig(t)
	r2.sendFn = func([]byte) (*api.ChangeAck, error) { return nil, &api.APIError{Status: 503, Code: "module_disabled"} }
	r2.tick()
	if len(r2.m.state.Watches) != 1 {
		t.Fatalf("%+v", r2.m.state.Watches)
	}
}

func TestABadConfigurationKeepsTheLastGoodOne(t *testing.T) {
	r := runRig(t)
	r.tick()
	bad := baseConfig("windows", "2020202020202020", regWatch("win.startup", map[string]any{"key": `HKLM\SOFTWARE\Evil`}))
	bad["signature"] = "AAAA"
	raw, _ := jsonMarshal(bad)
	if err := r.m.Configure(raw); err == nil {
		t.Fatal("an unsigned configuration was accepted")
	}
	if r.m.config().Version != "a1a1a1a1a1a1a1a1" {
		t.Errorf("the last good one stays: %s", r.m.config().Version)
	}
	if r.reg.openedPath(`HKLM\SOFTWARE\Evil`) {
		t.Error("nothing of the refused configuration was read")
	}
}

func TestTheQueueIsBounded(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.sendFn = func([]byte) (*api.ChangeAck, error) { return nil, errors.New("offline") }
	for round := 0; round < 6; round++ {
		var vals []RegValue
		for i := 0; i < 450; i++ {
			vals = append(vals, szVal(fmt.Sprintf("r%dv%03d", round, i), "1"))
		}
		r.reg.set(runKey, nil, vals...)
		r.next()
		r.clk.Advance(20 * time.Minute) // past the back-off
	}
	if len(r.m.state.Pending) > pendingCap {
		t.Fatalf("pending %d", len(r.m.state.Pending))
	}
	if r.m.state.Dropped == 0 {
		t.Error("what did not fit is counted")
	}
}

func TestNotificationsOnlyMakeThePollRunSooner(t *testing.T) {
	r := runRig(t)
	var wake func()
	started := 0
	r.m.o.StartNotifier = func(roots []NotifyRoot, w func()) func() {
		started++
		wake = w
		if len(roots) != 1 || roots[0].Key != runKey || roots[0].Subtree {
			t.Errorf("roots: %+v", roots)
		}
		return func() {}
	}
	r.tick()
	if started != 1 || wake == nil {
		t.Fatalf("started %d", started)
	}
	r.reg.set(runKey, nil, szVal("Evil", "e.exe"))
	wake()
	select {
	case <-r.m.notifyWake:
		r.m.forcePoll = true
	default:
		t.Fatal("the wake-up reaches the loop")
	}
	r.clk.Advance(11 * time.Second) // well inside the 60 s interval, past the 10 s minimum gap
	r.tick()
	if len(r.records()) != 3 {
		t.Fatalf("a notification polls early: %+v", r.records())
	}
	// a burst of notifications is not a burst of polls
	r.m.forcePoll = true
	r.clk.Advance(2 * time.Second)
	n := len(r.batches())
	r.tick()
	if len(r.batches()) != n {
		t.Error("polls are at least 10 s apart")
	}
}

func TestRunLoopStopsWithItsContext(t *testing.T) {
	r := runRig(t)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { r.m.Run(ctx); close(done) }()
	cancel()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("Run did not return")
	}
}

func TestAWatchThatPanicsIsAnErrorNotACrash(t *testing.T) {
	r := newRig(t, "windows")
	r.reg.listError = nil
	r.m.o.Registry = panicRegistry{}
	r.configure("3030303030303030", regWatch("win.startup", map[string]any{"key": runKey}))
	r.tick()
	if st := string(r.batches()[0].Status); !strings.Contains(st, `"state":"error"`) {
		t.Errorf("%s", st)
	}
}

type panicRegistry struct{}

func (panicRegistry) SubKeys(string) ([]string, error)                     { panic("boom") }
func (panicRegistry) Values(string, func(string) bool) ([]RegValue, error) { panic("boom") }
func (panicRegistry) Users() ([]string, error)                             { return nil, nil }

func TestAnOlderSignedConfigurationIsAReplayAndIsRefused(t *testing.T) {
	r := newRig(t, "windows")
	mk := func(seq int, key string) json.RawMessage {
		c := baseConfig("windows", "4040404040404040", regWatch("win.startup", map[string]any{"key": key}))
		c["seq"] = seq
		return r.sg.sign(t, c)
	}
	if err := r.m.Configure(mk(200, runKey)); err != nil {
		t.Fatal(err)
	}
	if err := r.m.Configure(mk(100, `HKLM\SOFTWARE\Evil\Wide`)); err == nil {
		t.Fatal("an older configuration was accepted")
	}
	if err := r.m.Configure(mk(200, runKey)); err != nil {
		t.Fatal("the same seq is fine (the same configuration again)")
	}
	r.tick()
	r.build() // a restart keeps the highest seq
	if err := r.m.Configure(mk(150, runKey)); err == nil {
		t.Fatal("the highest seq must survive a restart")
	}
	if err := r.m.Configure(mk(300, runKey)); err != nil {
		t.Fatal(err)
	}
}
