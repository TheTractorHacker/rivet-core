package change

import (
	"fmt"
	"strings"
	"testing"
	"time"
)

const runKey = `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run`

func runRig(t *testing.T) *rig {
	r := newRig(t, "windows")
	r.reg.set(runKey, nil, szVal("OneDrive", `C:\Program Files\OneDrive\OneDrive.exe /background`), szVal("Teams", `C:\Teams\update.exe --processStart`))
	r.configure("a1a1a1a1a1a1a1a1", regWatch("win.startup", map[string]any{"key": runKey}))
	return r
}

func TestFirstSightIsABaselineAndReportsNothing(t *testing.T) {
	r := runRig(t)
	r.tick()
	if recs := r.records(); len(recs) != 0 {
		t.Fatalf("a baseline is not a change: %+v", recs)
	}
	b := r.batches()
	if len(b) != 1 || len(b[0].Changes) != 0 || !strings.Contains(string(b[0].Status), `"state":"baseline"`) {
		t.Fatalf("the first batch is the status heartbeat: %+v", b)
	}
	if b[0].ConfigVersion != "a1a1a1a1a1a1a1a1" {
		t.Errorf("config_version: %q", b[0].ConfigVersion)
	}
	// the next poll: still nothing changed; the watch moves from baseline to ok, which is one status heartbeat, and then silence
	r.next()
	if b := r.batches(); len(b) != 2 || len(b[1].Changes) != 0 || !strings.Contains(string(b[1].Status), `"state":"ok"`) {
		t.Fatalf("one heartbeat for the new state: %+v", r.batches())
	}
	r.next()
	r.next()
	if len(r.batches()) != 2 || len(r.records()) != 0 {
		t.Fatalf("nothing changed, nothing is sent: %d batches", len(r.batches()))
	}
}

func TestAddModifyRemoveAreReportedWithOldAndNewValues(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.reg.set(runKey, nil, szVal("OneDrive", `C:\Program Files\OneDrive\OneDrive.exe /background`), szVal("Teams", `C:\Teams\update2.exe --processStart`), szVal("Evil", `C:\ProgramData\x\evil.exe`))
	r.next()
	recs := r.records()
	if len(recs) != 2 {
		t.Fatalf("records: %+v", recs)
	}
	ev := find(recs, "Evil")
	if ev == nil || ev.Change != "added" || ev.New != `C:\ProgramData\x\evil.exe` || ev.Old != "" || ev.Kind != "registry" || ev.Watch != "win.startup" || ev.Target != runKey {
		t.Errorf("added: %+v", ev)
	}
	tm := find(recs, "Teams")
	if tm == nil || tm.Change != "modified" || !strings.Contains(tm.Old, "update.exe") || !strings.Contains(tm.New, "update2.exe") || tm.OldHash == tm.NewHash || len(tm.NewHash) != 64 {
		t.Errorf("modified: %+v", tm)
	}
	if ev.TS != "2026-10-18T12:01:01Z" {
		t.Errorf("the time is the detection time: %s", ev.TS)
	}
	r.reset()
	r.reg.set(runKey, nil, szVal("Teams", `C:\Teams\update2.exe --processStart`))
	r.next()
	recs = r.records()
	if len(recs) != 2 || find(recs, "OneDrive").Change != "removed" || find(recs, "OneDrive").Old == "" || find(recs, "Evil").Change != "removed" {
		t.Errorf("removed: %+v", recs)
	}
}

func TestRegistryNamesAreCaseInsensitive(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.reg.set(strings.ToLower(runKey), nil, szVal("ONEDRIVE", `C:\Program Files\OneDrive\OneDrive.exe /background`), szVal("teams", `C:\Teams\update.exe --processStart`))
	r.next()
	if recs := r.records(); len(recs) != 0 {
		t.Fatalf("a change of case is not a change: %+v", recs)
	}
}

func TestSecretNamedValuesAreNeverReadShownHashedInTheClearOrKept(t *testing.T) {
	r := newRig(t, "windows")
	key := `HKLM\SOFTWARE\Acme\Agent`
	// the reader (wrongly) hands over the data of a secret: the engine masks it anyway
	r.reg.set(key, nil, RegValue{Name: "DbPassword", Type: regSZ, Raw: u16("hunter2-old"), Size: 24}, szVal("Server", "db1"), RegValue{Name: "ApiToken", Type: regSZ, Size: 40, Secret: true})
	r.configure("b2b2b2b2b2b2b2b2", regWatch("custom.acme", map[string]any{"key": key}))
	r.tick()
	r.reg.set(key, nil, RegValue{Name: "DbPassword", Type: regSZ, Raw: u16("hunter2-new!"), Size: 26}, szVal("Server", "db1"), RegValue{Name: "ApiToken", Type: regSZ, Size: 44, Secret: true})
	r.next()
	recs := r.records()
	if len(recs) != 2 {
		t.Fatalf("a changed secret is still a change: %+v", recs)
	}
	for _, name := range []string{"DbPassword", "ApiToken"} {
		x := find(recs, name)
		if x == nil || x.Change != "modified" || x.Old != Mask || x.New != Mask || !x.Redacted {
			t.Errorf("%s: %+v", name, x)
		}
		if len(x.OldHash) != 64 || x.OldHash == x.NewHash {
			t.Errorf("%s: the keyed hash still tells that it changed: %+v", name, x)
		}
	}
	all := r.stateText()
	for _, b := range r.bodies {
		all += string(b)
	}
	if strings.Contains(all, "hunter2") {
		t.Fatalf("a secret reached the state file or the wire")
	}
	// the hash of a secret is keyed: it is not the SHA-256 of anything an attacker can compute
	h := Hasher{}
	if x := find(recs, "DbPassword"); x.NewHash == h.Plain(u16("hunter2-new!")) {
		t.Error("the hash of a secret must be keyed")
	}
}

func TestSecretPatternsInsideAValueAreRedactedAndKeyedHashed(t *testing.T) {
	r := newRig(t, "windows")
	key := `HKLM\SOFTWARE\Acme\Agent`
	r.reg.set(key, nil, szVal("Cmd", `app.exe --password=hunter2 --host a`))
	r.configure("c3c3c3c3c3c3c3c3", regWatch("custom.acme", map[string]any{"key": key}))
	r.tick()
	r.reg.set(key, nil, szVal("Cmd", `app.exe --password=hunter3 --host a`))
	r.next()
	x := find(r.records(), "Cmd")
	if x == nil || strings.Contains(x.New+x.Old, "hunter") || !strings.Contains(strings.ToLower(x.New), "[redacted]") || !x.Redacted {
		t.Fatalf("%+v", x)
	}
	if x.OldHash == x.NewHash {
		t.Error("the two secrets differ: the hash must differ")
	}
	if x.NewHash == (Hasher{}).Plain(append([]byte("REG_SZ\x00"), u16(`app.exe --password=hunter3 --host a`)...)) {
		t.Error("a value that held a secret pattern must not be hashed in the clear")
	}
}

func TestValueRenderingAndCaps(t *testing.T) {
	multi := u16("one\x00two\x00three") // u16 appends the final NUL
	big := strings.Repeat("A ", 1500)
	r := newRig(t, "windows")
	key := `HKLM\SOFTWARE\Acme\Agent`
	r.reg.set(key, nil, dwordVal("Mode", 3), RegValue{Name: "List", Type: regMultiSZ, Raw: multi, Size: len(multi)}, RegValue{Name: "Blob", Type: regBinary, Raw: []byte{1, 2, 3, 4}, Size: 4},
		szVal("Big", big), RegValue{Name: "Huge", Type: regSZ, Raw: u16(big)[:RegReadCap], Size: 60000}, szVal("", "default-value"))
	r.configure("d4d4d4d4d4d4d4d4", regWatch("custom.acme", map[string]any{"key": key}))
	r.tick()
	st := r.m.state.Watches["custom.acme"].Snap
	get := func(name string) Item {
		for _, it := range st {
			if it.Name == name {
				return it
			}
		}
		t.Fatalf("no item %s in %v", name, sortedKeys(st))
		return Item{}
	}
	if v := get("Mode").Value; v != "3" {
		t.Errorf("dword: %q", v)
	}
	if v := get("List").Value; v != "one | two | three" {
		t.Errorf("multi: %q", v)
	}
	if v := get("Blob").Value; v != "REG_BINARY (4 bytes)" {
		t.Errorf("binary is described, never printed: %q", v)
	}
	if v := get("(Default)").Value; v != "default-value" {
		t.Errorf("the unnamed value is (Default): %q", v)
	}
	if b := get("Big"); len(b.Value) != 256 || b.Flags&FlagTruncated == 0 {
		t.Errorf("a long value is cut at max_value_bytes and flagged: %d %d", len(b.Value), b.Flags)
	}
	if h := get("Huge"); h.Flags&FlagTruncated == 0 {
		t.Errorf("a value bigger than what was read is flagged: %+v", h)
	}
}

func TestAnUnreadableKeyIsNotARemovedKey(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.reg.deny[strings.ToLower(runKey)] = true
	r.next()
	if recs := r.records(); len(recs) != 0 {
		t.Fatalf("access denied must not read as 'everything was removed': %+v", recs)
	}
	if !strings.Contains(string(r.batches()[len(r.batches())-1].Status), `"partial"`) {
		t.Errorf("the status says the watch is partial: %s", r.batches()[len(r.batches())-1].Status)
	}
	delete(r.reg.deny, strings.ToLower(runKey))
	r.reset()
	r.next()
	if recs := r.records(); len(recs) != 0 {
		t.Fatalf("readable again, nothing changed: %+v", recs)
	}
	// a key that is really gone IS removed
	r.reg.del(runKey)
	r.next()
	if recs := r.records(); len(recs) != 2 || recs[0].Change != "removed" {
		t.Fatalf("the key vanished: %+v", recs)
	}
}

func TestRecursionNeverStepsIntoACredentialKey(t *testing.T) {
	r := newRig(t, "windows")
	base := `HKLM\SOFTWARE\Acme`
	r.reg.set(base, []string{"Agent", "SAM", "Secrets", "SECURITY"}, szVal("Top", "1"))
	r.reg.set(base+`\Agent`, []string{"Deep"}, szVal("A", "1"))
	r.reg.set(base+`\Agent\Deep`, nil, szVal("B", "2"))
	r.reg.set(base+`\SAM`, nil, szVal("Hash", "xxxx"))
	r.reg.set(base+`\Secrets`, nil, szVal("Hash", "yyyy"))
	r.reg.set(base+`\SECURITY`, nil, szVal("Hash", "zzzz"))
	r.configure("e5e5e5e5e5e5e5e5", regWatch("custom.acme", map[string]any{"key": base, "depth": 3}))
	r.tick()
	for _, p := range []string{base + `\SAM`, base + `\Secrets`, base + `\SECURITY`} {
		if r.reg.openedPath(p) {
			t.Errorf("%s must never be opened", p)
		}
	}
	if !r.reg.openedPath(base+`\Agent\Deep`) || len(r.m.state.Watches["custom.acme"].Snap) != 3 {
		t.Errorf("the rest is read: %v", sortedKeys(r.m.state.Watches["custom.acme"].Snap))
	}
}

func TestEveryLoadedUserIsExpandedFromHKUStar(t *testing.T) {
	r := newRig(t, "windows")
	r.reg.users = []string{"S-1-5-21-1-2-3-1001", "S-1-5-21-1-2-3-1002", `evil\..\path`}
	suffix := `\Software\Microsoft\Windows\CurrentVersion\Run`
	r.reg.set(`HKU\S-1-5-21-1-2-3-1001`+suffix, nil, szVal("A", "a.exe"))
	r.reg.set(`HKU\S-1-5-21-1-2-3-1002`+suffix, nil, szVal("B", "b.exe"))
	r.configure("f6f6f6f6f6f6f6f6", regWatch("win.startup", map[string]any{"key": `HKU\*` + suffix}))
	r.tick()
	if n := len(r.m.state.Watches["win.startup"].Snap); n != 2 {
		t.Fatalf("both users: %d", n)
	}
	r.reg.set(`HKU\S-1-5-21-1-2-3-1002`+suffix, nil, szVal("B", "b.exe"), szVal("C", "c.exe"))
	r.next()
	x := find(r.records(), "C")
	if x == nil || x.Target != `HKU\S-1-5-21-1-2-3-1002`+suffix || x.Change != "added" {
		t.Fatalf("%+v", r.records())
	}
	if r.reg.openedPath(`HKU\evil\..\path` + suffix) {
		t.Error("only SID-shaped user hives are expanded")
	}
}

func TestOnlyTheNamedValuesAreReadWhenTheWatchNamesThem(t *testing.T) {
	r := newRig(t, "windows")
	key := `HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon`
	r.reg.set(key, nil, szVal("Shell", "explorer.exe"), szVal("DefaultPassword", "pw"), szVal("Userinit", `C:\Windows\system32\userinit.exe,`), szVal("Other", "x"))
	r.configure("a7a7a7a7a7a7a7a7", regWatch("win.startup", map[string]any{"key": key, "values": []string{"shell", "USERINIT"}}))
	r.tick()
	snap := r.m.state.Watches["win.startup"].Snap
	if len(snap) != 2 {
		t.Fatalf("the values filter is case-insensitive and exclusive: %v", sortedKeys(snap))
	}
}

func TestTheItemLimitStopsAWatchThatIsTooBig(t *testing.T) {
	r := newRig(t, "windows")
	key := `HKLM\SOFTWARE\Acme\Big`
	var vals []RegValue
	for i := 0; i < 50; i++ {
		vals = append(vals, szVal(strings.Repeat("v", 1)+string(rune('a'+i%26))+strings.Repeat("x", i), "1"))
	}
	r.reg.set(key, nil, vals...)
	r.m.maxItems = 10
	r.configure("b8b8b8b8b8b8b8b8", regWatch("custom.big", map[string]any{"key": key}))
	r.tick()
	if n := len(r.m.state.Watches["custom.big"].Snap); n != 10 {
		t.Fatalf("items kept: %d", n)
	}
	if !strings.Contains(string(r.batches()[0].Status), "narrow it") {
		t.Errorf("the status says why: %s", r.batches()[0].Status)
	}
}

func TestChangingTheWatchSpecificationRebaselinesInsteadOfFlooding(t *testing.T) {
	r := runRig(t)
	r.tick()
	r.reg.set(runKey, []string{"Sub"}, szVal("OneDrive", `C:\Program Files\OneDrive\OneDrive.exe /background`), szVal("Teams", `C:\Teams\update.exe --processStart`))
	r.reg.set(runKey+`\Sub`, nil, szVal("X", "x.exe"), szVal("Y", "y.exe"))
	r.configure("c9c9c9c9c9c9c9c9", regWatch("win.startup", map[string]any{"key": runKey, "depth": 1}))
	r.next()
	if recs := r.records(); len(recs) != 0 {
		t.Fatalf("a widened watch starts a new baseline: %+v", recs)
	}
	r.reg.set(runKey+`\Sub`, nil, szVal("X", "x.exe"), szVal("Y", "y.exe"), szVal("Z", "z.exe"))
	r.next()
	if recs := r.records(); len(recs) != 1 || recs[0].Item != "Z" {
		t.Fatalf("%+v", recs)
	}
}

func TestChangesMadeWhileTheAgentWasStoppedAreFoundAfterARestart(t *testing.T) {
	r := runRig(t)
	r.tick()
	cfg := r.m.want
	r.build() // the agent restarts: same state file
	r.m.set(cfg)
	r.reg.set(runKey, nil, szVal("OneDrive", `C:\Program Files\OneDrive\OneDrive.exe /background`))
	r.clk.Advance(5 * time.Minute)
	r.tick()
	recs := r.records()
	if len(recs) != 1 || recs[0].Item != "Teams" || recs[0].Change != "removed" {
		t.Fatalf("%+v", recs)
	}
}

func TestMassChangeIsCappedPerPollAndCounted(t *testing.T) {
	r := newRig(t, "windows")
	key := `HKLM\SOFTWARE\Acme\Many`
	mk := func(n int) []RegValue {
		var v []RegValue
		for i := 0; i < n; i++ {
			v = append(v, szVal(fmt.Sprintf("v%04d", i), "1"))
		}
		return v
	}
	r.reg.set(key, nil)
	r.configure("c0c0c0c0c0c0c0c0", regWatch("custom.many", map[string]any{"key": key}))
	r.tick()
	r.reg.set(key, nil, mk(450)...)
	r.next()
	if n := len(r.records()); n != 450 {
		t.Fatalf("under the cap everything is reported: %d", n)
	}
	r.reset()
	r.reg.set(key, nil, mk(1200)...)
	r.next()
	b := r.batches()
	total, dropped := 0, 0
	for _, x := range b {
		total += len(x.Changes)
		dropped = max(dropped, x.Dropped)
	}
	if total != perPollCap || dropped != 750-perPollCap {
		t.Fatalf("at most %d records per poll, the rest counted: reported %d, dropped %d", perPollCap, total, dropped)
	}
	if !strings.Contains(string(b[0].Status), "more than 500 changes") {
		t.Errorf("the status says so: %s", b[0].Status)
	}
	r.reset()
	r.next()
	if len(r.records()) != 0 {
		t.Fatalf("the snapshot moved on: the next poll is quiet, not a re-report of the rest: %d", len(r.records()))
	}
}

func TestAKeyThePolicyRefusesIsCountedInTheStatus(t *testing.T) {
	r := newRig(t, "windows")
	base := `HKLM\SOFTWARE\Acme`
	r.reg.set(base, []string{"ok", "bad.", " padded", "with/slash"}, szVal("Top", "1"))
	r.reg.set(base+`\ok`, nil, szVal("A", "1"))
	r.configure("f0f0f0f0f0f0f0f0", regWatch("custom.acme", map[string]any{"key": base, "depth": 1}))
	r.tick()
	if st := string(r.batches()[0].Status); !strings.Contains(st, `"skipped":3`) {
		t.Errorf("%s", st)
	}
	if r.reg.openedPath(base + `\bad.`) {
		t.Error("a refused key must not be opened")
	}
}
