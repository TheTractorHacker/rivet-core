package patch

import (
	"context"
	"encoding/json"
	"errors"
	"sort"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/jobs"
)

// fakeReg is an in-memory registry. Keys and names are compared case-insensitively, like Windows.
type fakeReg struct {
	vals     map[string]RegVal
	keys     map[string]bool
	failSet  string // a value name whose Set fails
	sets     []string
	deletes  []string
	getCalls int
}

func newReg() *fakeReg { return &fakeReg{vals: map[string]RegVal{}, keys: map[string]bool{}} }

func k(key, name string) string { return strings.ToLower(key + "|" + name) }

func (f *fakeReg) put(key, name string, v RegVal) {
	f.vals[k(key, name)] = v
	f.keys[strings.ToLower(key)] = true
}

func (f *fakeReg) Get(key, name string) (RegVal, bool, error) {
	f.getCalls++
	v, ok := f.vals[k(key, name)]
	return v, ok, nil
}

func (f *fakeReg) Names(key string) ([]string, bool, error) {
	lk := strings.ToLower(key)
	var out []string
	for kk := range f.vals {
		if strings.HasPrefix(kk, lk+"|") {
			out = append(out, kk[len(lk)+1:])
		}
	}
	sort.Strings(out)
	return out, f.keys[lk] || len(out) > 0, nil
}

func (f *fakeReg) Set(key, name string, v RegVal) error {
	if f.failSet != "" && strings.EqualFold(name, f.failSet) {
		return errors.New("access denied")
	}
	f.sets = append(f.sets, key+"|"+name)
	f.put(key, name, v)
	return nil
}

func (f *fakeReg) Delete(key, name string) error {
	f.deletes = append(f.deletes, key+"|"+name)
	delete(f.vals, k(key, name))
	return nil
}

func win11(f *fakeReg, version string) *fakeReg {
	f.put(versionKey, "DisplayVersion", str(version))
	f.put(versionKey, "CurrentBuildNumber", str("26100"))
	f.put(versionKey, "InstallationType", str("Client"))
	return f
}

var t0 = time.Date(2026, 11, 12, 10, 0, 0, 0, time.UTC)

func apply(t *testing.T, f *fakeReg, p ConfigureParams) ConfigureOut {
	t.Helper()
	out, err := Configure(f, p, t0)
	if err != nil {
		t.Fatal(err)
	}
	return out
}

func val(f *fakeReg, key, name string) (RegVal, bool) {
	v, ok := f.vals[k(key, name)]
	return v, ok
}

// ---------------------------------------------------------------- validation

func TestParseConfigureParamsAcceptsTheContractAndNothingElse(t *testing.T) {
	good := []string{
		`{}`, `{"mode":"apply","update_mode":"managed","feature_target":"25H2","defer_days":7}`,
		`{"mode":"dry_run","update_mode":"leave","feature_target":"hold","defer_days":0}`, `{"feature_target":"26H2"}`, `{"defer_days":30}`,
	}
	for _, g := range good {
		if _, err := ParseConfigureParams(json.RawMessage(g)); err != nil {
			t.Errorf("%s refused: %v", g, err)
		}
	}
	p, _ := ParseConfigureParams(json.RawMessage(`{}`))
	if p.Mode != "dry_run" || p.UpdateMode != "leave" || p.FeatureTarget != "none" || p.DeferDays != 0 {
		t.Errorf("the defaults must change nothing and be a dry run: %+v", p)
	}
	bad := []string{
		`{"mode":"live"}`, `{"mode":1}`, `{"update_mode":"auto"}`, `{"update_mode":""}`, `{"feature_target":"25h2"}`, `{"feature_target":"25H3"}`, `{"feature_target":"2025H2"}`,
		`{"feature_target":"25H2 "}`, `{"feature_target":"25H2\n"}`, `{"feature_target":"hold;"}`, `{"feature_target":"..\\..\\x"}`, `{"feature_target":"25H2\"}`, `{"feature_target":"Windows 11"}`,
		`{"feature_target":"1"}`, `{"feature_target":""}`, `{"feature_target":null}`, `{"defer_days":31}`, `{"defer_days":-1}`, `{"defer_days":"7"}`, `{"defer_days":7.5}`, `{"defer_days":true}`,
		`{"key":"HKLM\\x"}`, `{"path":"x"}`, `{"value":1}`, `{"mode":"apply","extra":1}`, `[]`, `"x"`, `{"mode":"apply"} {"mode":"apply"}`,
	}
	for _, b := range bad {
		if _, err := ParseConfigureParams(json.RawMessage(b)); err == nil {
			t.Errorf("%s was accepted", b)
		}
	}
}

func TestValidFeatureVersion(t *testing.T) {
	for _, s := range []string{"22H2", "23H2", "24H2", "25H2", "26H2", "26H1"} {
		if !ValidFeatureVersion(s) {
			t.Errorf("%s refused", s)
		}
	}
	for _, s := range []string{"", "H2", "2H2", "225H2", "25H3", "25h2", "25H2\n", " 25H2", "25H20", "latest", "1909", "21H2;", "Windows 11"} {
		if ValidFeatureVersion(s) {
			t.Errorf("%q accepted", s)
		}
	}
}

// ---------------------------------------------------------------- the decision

func TestAnEmptyRequestOnACleanMachineChangesNothingAndWritesNothing(t *testing.T) {
	f := win11(newReg(), "24H2")
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"})
	if out.Outcome != "unchanged" || len(out.Changes) != 0 || len(f.sets) != 0 || len(f.deletes) != 0 {
		t.Fatalf("outcome %s changes %v sets %v deletes %v", out.Outcome, out.Changes, f.sets, f.deletes)
	}
}

func TestManagedModeWritesOnlyTheTwoAUValuesAndTheMarker(t *testing.T) {
	f := win11(newReg(), "24H2")
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "none"})
	if out.Outcome != "changed" || len(out.Changes) != 2 {
		t.Fatalf("%+v", out)
	}
	if v, _ := val(f, auPolicyKey, "NoAutoUpdate"); v != dword(0) {
		t.Errorf("NoAutoUpdate %v", v)
	}
	if v, _ := val(f, auPolicyKey, "AUOptions"); v != dword(2) {
		t.Errorf("AUOptions %v", v)
	}
	want := []string{auPolicyKey + "|NoAutoUpdate", auPolicyKey + "|AUOptions", markerKeyPath + "|State"}
	got := append([]string{}, f.sets...)
	sort.Strings(got)
	sort.Strings(want)
	if strings.Join(got, ",") != strings.Join(want, ",") {
		t.Fatalf("wrote %v, want exactly %v", f.sets, want)
	}
	if f.sets[0] != markerKeyPath+"|State" {
		t.Errorf("the marker must be written FIRST (crash safety): %v", f.sets)
	}
	if len(out.After.Core.Keys) != 2 || out.After.Core.Pinned != nil {
		t.Errorf("after: %+v", out.After.Core)
	}
}

func TestPinningToANamedVersionWritesTheThreeFeatureValuesWithTheRightProduct(t *testing.T) {
	f := win11(newReg(), "24H2")
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "25H2"})
	if out.Outcome != "changed" {
		t.Fatalf("%+v", out)
	}
	for name, want := range map[string]RegVal{"TargetReleaseVersion": dword(1), "TargetReleaseVersionInfo": str("25H2"), "ProductVersion": str("Windows 11")} {
		if v, _ := val(f, wuPolicyKey, name); v != want {
			t.Errorf("%s = %v want %v", name, v, want)
		}
	}
	if _, ok := val(f, auPolicyKey, "AUOptions"); ok {
		t.Error("leave mode must not touch the AU policy")
	}
	if out.After.Core.Pinned == nil || *out.After.Core.Pinned != "25H2" || out.After.Core.Held {
		t.Errorf("%+v", out.After.Core)
	}
	// Windows 10 build: the product is Windows 10.
	g := newReg()
	g.put(versionKey, "DisplayVersion", str("22H2"))
	g.put(versionKey, "CurrentBuildNumber", str("19045"))
	apply(t, g, ConfigureParams{Mode: "apply", FeatureTarget: "22H2", UpdateMode: "leave"})
	if v, _ := val(g, wuPolicyKey, "ProductVersion"); v != str("Windows 10") {
		t.Errorf("ProductVersion %v", v)
	}
}

func TestHoldPinsTheVersionTheDeviceRunsAndKeepsItWhenAskedAgain(t *testing.T) {
	f := win11(newReg(), "24H2")
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "hold"})
	if v, _ := val(f, wuPolicyKey, "TargetReleaseVersionInfo"); v != str("24H2") || out.After.Core.Pinned == nil || *out.After.Core.Pinned != "24H2" || !out.After.Core.Held {
		t.Fatalf("%v %+v", v, out.After.Core)
	}
	// the device moves to 25H2 behind our back (a breach): a second hold does NOT follow it
	f.put(versionKey, "DisplayVersion", str("25H2"))
	f.sets = nil
	out = apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "hold"})
	if out.Outcome != "unchanged" || len(f.sets) != 0 {
		t.Fatalf("a hold must be idempotent: %+v %v", out, f.sets)
	}
	if v, _ := val(f, wuPolicyKey, "TargetReleaseVersionInfo"); v != str("24H2") {
		t.Errorf("pin moved to %v", v)
	}
}

func TestHoldAfterANamedPinRepinsToTheCurrentVersion(t *testing.T) {
	f := win11(newReg(), "24H2")
	apply(t, f, ConfigureParams{Mode: "apply", FeatureTarget: "25H2", UpdateMode: "leave"})
	out := apply(t, f, ConfigureParams{Mode: "apply", FeatureTarget: "hold", UpdateMode: "leave"})
	if v, _ := val(f, wuPolicyKey, "TargetReleaseVersionInfo"); v != str("24H2") || out.Outcome != "changed" {
		t.Fatalf("a hold means 'stay where you are': %v %+v", v, out)
	}
}

func TestHoldOnAnUnknownVersionOrAServerRefusesAndWritesNothing(t *testing.T) {
	f := newReg()
	f.put(versionKey, "CurrentBuildNumber", str("19041"))
	f.put(versionKey, "InstallationType", str("Client")) // no DisplayVersion (a 2004-or-older build)
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "hold"})
	if out.Outcome != "cannot_hold" || out.Detail != "unknown_feature_version" || len(f.sets) != 0 {
		t.Fatalf("%+v %v", out, f.sets)
	}
	s := win11(newReg(), "24H2")
	s.put(versionKey, "InstallationType", str("Server"))
	out = apply(t, s, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "25H2"})
	if out.Outcome != "cannot_hold" || len(s.sets) != 0 {
		t.Fatalf("%+v", out)
	}
	// ...but managed mode alone works on a server
	out = apply(t, s, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "none"})
	if out.Outcome != "changed" {
		t.Fatalf("%+v", out)
	}
}

func TestQualityDeferralWritesTwoValuesAndZeroMeansNone(t *testing.T) {
	f := win11(newReg(), "24H2")
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none", DeferDays: 14})
	if out.Outcome != "changed" {
		t.Fatalf("%+v", out)
	}
	if v, _ := val(f, wuPolicyKey, "DeferQualityUpdates"); v != dword(1) {
		t.Error(v)
	}
	if v, _ := val(f, wuPolicyKey, "DeferQualityUpdatesPeriodInDays"); v != dword(14) {
		t.Error(v)
	}
	out = apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none", DeferDays: 0})
	if out.Outcome != "restored" {
		t.Fatalf("%+v", out)
	}
	if _, ok := val(f, wuPolicyKey, "DeferQualityUpdates"); ok {
		t.Error("deferral not removed")
	}
}

func TestChangingTheNamedTargetUpdatesOnlyTheVersionString(t *testing.T) {
	f := win11(newReg(), "24H2")
	apply(t, f, ConfigureParams{Mode: "apply", FeatureTarget: "25H2", UpdateMode: "leave"})
	f.sets = nil
	out := apply(t, f, ConfigureParams{Mode: "apply", FeatureTarget: "26H2", UpdateMode: "leave"})
	if out.Outcome != "changed" || len(out.Changes) != 1 || out.Changes[0].Key != "target_release_version_info" || out.Changes[0].From != "25H2" || out.Changes[0].To != "26H2" {
		t.Fatalf("%+v", out)
	}
}

// ---------------------------------------------------------------- externally managed

func external1(t *testing.T, f *fakeReg, want string) {
	t.Helper()
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "25H2", DeferDays: 7})
	if out.Outcome != "externally_managed" || len(out.Changes) != 0 || len(f.sets) != 0 || len(f.deletes) != 0 {
		t.Fatalf("%s: outcome %s changes %v sets %v deletes %v", want, out.Outcome, out.Changes, f.sets, f.deletes)
	}
	if !contains(out.Before.External, want) {
		t.Fatalf("external %v does not name %s", out.Before.External, want)
	}
}

func TestAWSUSMachineIsExternallyManagedAndNothingIsWritten(t *testing.T) {
	f := win11(newReg(), "24H2")
	f.put(auPolicyKey, "UseWUServer", dword(1))
	f.put(wuPolicyKey, "WUServer", str("http://wsus.example:8530"))
	external1(t, f, "wsus")
}

func TestAGroupPolicyThatAlreadySetsTheValuesOrOthersOwnsTheSetting(t *testing.T) {
	f := win11(newReg(), "24H2")
	f.put(auPolicyKey, "AUOptions", dword(4)) // a GPO sets the same value Core wants
	external1(t, f, "gpo")
	g := win11(newReg(), "24H2")
	g.put(wuPolicyKey, "DeferFeatureUpdatesPeriodInDays", dword(30)) // a value Core never writes
	external1(t, g, "gpo")
	h := win11(newReg(), "24H2")
	h.put(wuPolicyKey, "TargetReleaseVersionInfo", str("23H2")) // an owned name, not written by Core
	external1(t, h, "gpo")
	i := win11(newReg(), "24H2")
	i.put(wuPolicyKey, "TargetReleaseVersion", RegVal{Kind: "other"}) // an owned name of another type
	external1(t, i, "gpo")
}

func TestMDMIsExternallyManaged(t *testing.T) {
	f := win11(newReg(), "24H2")
	f.put(mdmUpdateKey, "AllowAutoUpdate", dword(1))
	f.put(mdmUpdateKey, "AllowAutoUpdate_ProviderSet", dword(1))
	external1(t, f, "mdm")
	g := win11(newReg(), "24H2")
	g.put(mdmUpdateKey, "DeferQualityUpdatesPeriodInDays_WinningProvider", str("MS DM Server"))
	external1(t, g, "mdm")
	// a PolicyManager key that only holds defaults is not MDM
	h := win11(newReg(), "24H2")
	h.put(mdmUpdateKey, "SomeDefault", dword(3))
	if out := apply(t, h, ConfigureParams{Mode: "dry_run", UpdateMode: "managed", FeatureTarget: "none"}); out.Outcome == "externally_managed" {
		t.Errorf("a defaults-only key must not count as MDM: %+v", out)
	}
}

func TestADryRunOnAnExternallyManagedMachineSaysSoToo(t *testing.T) {
	f := win11(newReg(), "24H2")
	f.put(auPolicyKey, "UseWUServer", dword(1))
	out := apply(t, f, ConfigureParams{Mode: "dry_run", UpdateMode: "managed", FeatureTarget: "none"})
	if out.Outcome != "externally_managed" {
		t.Fatal(out.Outcome)
	}
}

func TestSomeoneChangingAValueCoreWroteMakesTheDeviceExternalAndCoreStopsWriting(t *testing.T) {
	f := win11(newReg(), "24H2")
	apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "none"})
	f.put(auPolicyKey, "AUOptions", dword(4)) // a GPO refresh
	f.sets, f.deletes = nil, nil
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "none"})
	if out.Outcome != "externally_managed" || !contains(out.Before.External, "changed_since") || len(f.sets)+len(f.deletes) != 0 {
		t.Fatalf("%+v %v %v", out, f.sets, f.deletes)
	}
}

// ---------------------------------------------------------------- restore

func TestRestoreDeletesExactlyTheValuesCoreWroteAndTheMarkerAndNothingElse(t *testing.T) {
	f := win11(newReg(), "24H2")
	apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "25H2", DeferDays: 3})
	f.put(`SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\Other`, "x", dword(1)) // a different key: never touched
	f.sets, f.deletes = nil, nil
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"})
	if out.Outcome != "restored" || len(out.Changes) != 7 {
		t.Fatalf("%+v", out)
	}
	if len(f.sets) != 0 {
		t.Errorf("a restore never sets anything: %v", f.sets)
	}
	for _, o := range ownedValues {
		if _, ok := val(f, o.Key, o.Name); ok {
			t.Errorf("%s still there", o.Name)
		}
	}
	if _, ok := val(f, markerKeyPath, markerValue); ok {
		t.Error("marker not removed")
	}
	if _, ok := val(f, `SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\Other`, "x"); !ok {
		t.Error("a foreign key was touched")
	}
	if len(out.After.Core.Keys) != 0 || out.After.Core.Pinned != nil {
		t.Errorf("%+v", out.After.Core)
	}
}

func TestRestorePutsBackAPreviousValueWhenTheMarkerRecordsOne(t *testing.T) {
	// A marker with a recorded previous value (the design allows it): the restore writes it back, but only where the value is still what Core wrote.
	f := win11(newReg(), "24H2")
	prev := dword(5)
	m := Marker{V: 1, At: "x", Keys: map[string]MarkerEntry{"au_options": {Wrote: dword(2), Prev: &prev}}}
	b, _ := json.Marshal(m)
	f.put(markerKeyPath, markerValue, str(string(b)))
	f.put(auPolicyKey, "AUOptions", dword(2))
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"})
	if out.Outcome != "restored" {
		t.Fatalf("%+v", out)
	}
	if v, _ := val(f, auPolicyKey, "AUOptions"); v != dword(5) {
		t.Errorf("AUOptions %v, want the previous 5", v)
	}
}

func TestRestoreLeavesAValueAloneThatSomeoneElseChangedAndForgetsIt(t *testing.T) {
	f := win11(newReg(), "24H2")
	apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "none", DeferDays: 3})
	f.put(auPolicyKey, "AUOptions", dword(4)) // changed since
	f.sets, f.deletes = nil, nil
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"})
	if out.Outcome != "restored" {
		t.Fatalf("%+v", out)
	}
	if v, ok := val(f, auPolicyKey, "AUOptions"); !ok || v != dword(4) {
		t.Errorf("a value somebody else set must stay: %v %v", v, ok)
	}
	if _, ok := val(f, wuPolicyKey, "DeferQualityUpdates"); ok {
		t.Error("an unchanged value of Core's was not restored")
	}
	for _, d := range f.deletes {
		if strings.HasSuffix(d, "|AUOptions") {
			t.Error("deleted a changed value")
		}
	}
	if _, ok := val(f, markerKeyPath, markerValue); ok {
		t.Error("nothing is Core's any more: the marker goes")
	}
}

func TestRestoreWithNothingToRestoreIsUnchangedAndDoesNotFight(t *testing.T) {
	f := win11(newReg(), "24H2")
	f.put(auPolicyKey, "AUOptions", dword(4)) // a GPO, no marker
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"})
	if out.Outcome != "externally_managed" || len(f.deletes) != 0 {
		t.Fatalf("%+v", out)
	}
}

func TestAStaleMarkerWithNothingLeftIsCleanedUp(t *testing.T) {
	f := win11(newReg(), "24H2")
	apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "none"})
	f.Delete(auPolicyKey, "NoAutoUpdate") // somebody deleted both values
	f.Delete(auPolicyKey, "AUOptions")
	out := apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"})
	if out.Outcome != "unchanged" {
		t.Fatalf("%+v", out)
	}
	if _, ok := val(f, markerKeyPath, markerValue); ok {
		t.Error("stale marker kept")
	}
}

// ---------------------------------------------------------------- dry run, idempotence, failure

func TestADryRunReportsWhatItWouldChangeAndWritesNothingAtAll(t *testing.T) {
	f := win11(newReg(), "24H2")
	out := apply(t, f, ConfigureParams{Mode: "dry_run", UpdateMode: "managed", FeatureTarget: "25H2", DeferDays: 5})
	if out.Outcome != "would_change" || len(out.Changes) != 7 || len(f.sets) != 0 || len(f.deletes) != 0 {
		t.Fatalf("%+v %v", out, f.sets)
	}
	if len(out.After.Core.Keys) != 0 {
		t.Error("a dry run's `after` must equal `before`")
	}
	apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "25H2", DeferDays: 5})
	f.sets, f.deletes = nil, nil
	out = apply(t, f, ConfigureParams{Mode: "dry_run", UpdateMode: "leave", FeatureTarget: "none"})
	if out.Outcome != "would_restore" || len(f.deletes) != 0 {
		t.Fatalf("%+v", out)
	}
}

func TestApplyingTwiceTheSecondTimeWritesNothing(t *testing.T) {
	f := win11(newReg(), "24H2")
	p := ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "25H2", DeferDays: 5}
	apply(t, f, p)
	f.sets, f.deletes = nil, nil
	out := apply(t, f, p)
	if out.Outcome != "unchanged" || len(f.sets) != 0 || len(f.deletes) != 0 {
		t.Fatalf("%+v %v %v", out, f.sets, f.deletes)
	}
}

func TestAWriteFailureRollsBackWhatThisRunDidAndReportsAnError(t *testing.T) {
	f := win11(newReg(), "24H2")
	f.failSet = "TargetReleaseVersionInfo"
	_, err := Configure(f, ConfigureParams{Mode: "apply", UpdateMode: "managed", FeatureTarget: "25H2"}, t0)
	if err == nil {
		t.Fatal("the failure was swallowed")
	}
	for _, o := range ownedValues {
		if _, ok := val(f, o.Key, o.Name); ok {
			t.Errorf("%s left behind after the rollback", o.Name)
		}
	}
}

func TestATamperedMarkerCannotNameAnythingOutsideTheAllowList(t *testing.T) {
	bad := []string{
		`{"v":1,"keys":{"UseWUServer":{"wrote":{"k":"dword","n":1}}}}`,
		`{"v":1,"keys":{"au_options":{"wrote":{"k":"sz","s":"x"}}}}`,
		`{"v":1,"keys":{"target_release_version_info":{"wrote":{"k":"sz","s":"x\\y"}}}}`,
		`{"v":1,"keys":{"target_release_version_info":{"wrote":{"k":"sz","s":"` + strings.Repeat("a", 50) + `"}}}}`,
		`{"v":1,"pinned":"latest","keys":{}}`, `{"v":2,"keys":{}}`, `not json`, `{"v":1,"keys":{"au_options":{"wrote":{"k":"dword","n":2},"prev":{"k":"sz","s":"x"}}}}`,
	}
	for _, b := range bad {
		f := win11(newReg(), "24H2")
		f.put(markerKeyPath, markerValue, str(b))
		f.put(`SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU`, "UseWUServerX", dword(1))
		snap, err := Read(f)
		if err != nil {
			t.Fatal(err)
		}
		if snap.Marker != nil {
			t.Errorf("accepted marker %s", b)
		}
	}
	// a restore that reads a bad marker deletes nothing it was not told to own
	f := win11(newReg(), "24H2")
	f.put(markerKeyPath, markerValue, str(`{"v":1,"keys":{"UseWUServer":{"wrote":{"k":"dword","n":1}}}}`))
	f.put(auPolicyKey, "UseWUServer", dword(1))
	apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"})
	if _, ok := val(f, auPolicyKey, "UseWUServer"); !ok {
		t.Error("a forged marker made the agent delete a value outside the allow-list")
	}
}

func TestOnlyTheOwnedNamesAreEverWritten(t *testing.T) {
	allowed := map[string]bool{}
	for _, o := range ownedValues {
		allowed[strings.ToLower(o.Key+"|"+o.Name)] = true
	}
	allowed[strings.ToLower(markerKeyPath+"|"+markerValue)] = true
	for _, p := range []ConfigureParams{
		{Mode: "apply", UpdateMode: "managed", FeatureTarget: "26H2", DeferDays: 30}, {Mode: "apply", UpdateMode: "leave", FeatureTarget: "hold"},
		{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"},
	} {
		f := win11(newReg(), "24H2")
		apply(t, f, p)
		apply(t, f, ConfigureParams{Mode: "apply", UpdateMode: "leave", FeatureTarget: "none"})
		for _, s := range append(append([]string{}, f.sets...), f.deletes...) {
			if !allowed[strings.ToLower(s)] {
				t.Errorf("wrote or deleted %s", s)
			}
		}
	}
	if len(ownedValues) != 7 {
		t.Fatalf("the allow-list changed (%d): update the docs and the PHP WindowsUpdatePlan::KEYS together", len(ownedValues))
	}
}

// ---------------------------------------------------------------- the report

func TestBuildStateReportsThePolicyTheMarkerAndWhoOwnsIt(t *testing.T) {
	f := win11(newReg(), "24H2")
	f.put(auPolicyKey, "UseWUServer", dword(1))
	f.put(auPolicyKey, "AUOptions", dword(3))
	snap, _ := Read(f)
	st := BuildState(snap)
	b, _ := json.Marshal(st)
	var m map[string]any
	_ = json.Unmarshal(b, &m)
	if m["feature_version"] != "24H2" || m["build"] != "26100" || m["product"] != "Windows 11" {
		t.Errorf("%s", b)
	}
	ext, _ := m["external"].([]any)
	if len(ext) == 0 {
		t.Errorf("external %s", b)
	}
	if pol, _ := m["policy"].(map[string]any); pol["au_options"] != float64(3) {
		t.Errorf("policy %s", b)
	}
	if core, _ := m["core"].(map[string]any); core["pinned"] != nil {
		t.Errorf("core %s", b)
	}
}

// ---------------------------------------------------------------- the handler

func TestConfigureHandlerIsWindowsOnlyAndEndToEnd(t *testing.T) {
	f := win11(newReg(), "24H2")
	e := Engine{GOOS: "windows", Reg: f, Now: func() time.Time { return t0 }}
	r := e.Configure(context.Background(), json.RawMessage(`{"mode":"apply","update_mode":"managed","feature_target":"hold","defer_days":0}`), 65536)
	if !r.OK {
		t.Fatalf("%+v", r)
	}
	var out ConfigureOut
	if err := json.Unmarshal([]byte(r.Output), &out); err != nil || out.Outcome != "changed" || out.V != 1 || !out.OK {
		t.Fatalf("%s %v", r.Output, err)
	}
	if v, _ := val(f, wuPolicyKey, "TargetReleaseVersionInfo"); v != str("24H2") {
		t.Error(v)
	}
	if r := (Engine{GOOS: "linux", Reg: f}).Configure(context.Background(), json.RawMessage(`{}`), 65536); r.OK || !r.Cannot {
		t.Errorf("linux: %+v", r)
	}
	if r := e.Configure(context.Background(), json.RawMessage(`{"mode":"apply","path":"x"}`), 65536); r.OK || !r.Cannot {
		t.Errorf("an unknown parameter must refuse the job: %+v", r)
	}
	if r := (Engine{GOOS: "windows"}).Configure(context.Background(), json.RawMessage(`{}`), 65536); r.OK {
		t.Errorf("without a registry (the real one only exists on Windows) the job cannot run: %+v", r)
	}
}

func TestConfigureHandlerReportsARegistryFailureAsAFailedJob(t *testing.T) {
	f := win11(newReg(), "24H2")
	f.failSet = "AUOptions"
	e := Engine{GOOS: "windows", Reg: f}
	r := e.Configure(context.Background(), json.RawMessage(`{"mode":"apply","update_mode":"managed"}`), 65536)
	if r.OK || !strings.Contains(r.Output, "registry_error") {
		t.Fatalf("%+v", r)
	}
}

func TestTheScanReportCarriesTheWindowsUpdateStateOnlyWhenARegistryIsAvailable(t *testing.T) {
	f := win11(newReg(), "25H2")
	f.put(auPolicyKey, "UseWUServer", dword(1))
	script := `{"v":1,"ok":true,"manager":"wua","scan_state":"ok","wu_state":"running","reboot_pending":false,"total":0,"updates":[]}`
	res := jobs.ExecResult{Output: script, HaveExit: true}
	with := Engine{GOOS: "windows", Reg: f, Now: func() time.Time { return t0 }}.normaliseScan(res, ScanParams{MaxItems: 10}, 1<<20)
	if !with.OK || !strings.Contains(with.Output, `"windows_update":{"feature_version":"25H2"`) || !strings.Contains(with.Output, `"external":["wsus"]`) {
		t.Fatalf("%s", with.Output)
	}
	without := Engine{GOOS: "windows", Now: func() time.Time { return t0 }}.normaliseScan(res, ScanParams{MaxItems: 10}, 1<<20)
	if strings.Contains(without.Output, "windows_update") {
		t.Fatalf("an agent without a registry must produce the old document byte for byte: %s", without.Output)
	}
}

func TestJobAndCapabilityNamesAreTheWireContract(t *testing.T) {
	if JobConfigure != "patch_configure" || CapConfigure != "patch:configure" || jobs.TypePatchConfigure != JobConfigure {
		t.Fatal("names changed")
	}
	if !jobs.SupportsType(jobs.TypePatchConfigure) == (jobsGOOS() == "windows") {
		t.Fatal("patch_configure must be supported on Windows only")
	}
}

func jobsGOOS() string { return runtimeGOOS }
