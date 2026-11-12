package patch

import (
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strings"
	"time"
)

// Windows Update control (patch_configure). This file has NO build tag: the whole decision (what the policy asks for, who owns the setting, what to write, what to
// restore, what to report) is pure and tested on Linux against a fake registry. Only the real registry access lives in wupolicy_windows.go.
//
// THE RULES
//   - Core writes ONLY the seven values of ownedValues, nowhere else (the table is the allow-list; nothing the server sends is a path or a value name).
//   - Core writes a value only where NOTHING is: if the Windows Update policy keys already hold any value Core did not write, or hold a WSUS server, or MDM sets Update policy,
//     or a value Core wrote was changed by someone else, the setting belongs to somebody else: the result is externally_managed and NOTHING is changed.
//   - Everything Core writes is recorded in a marker (HKLM\SOFTWARE\RivetIT\WindowsUpdateControl, value State): the value written and the value that was there before.
//     Restoring puts back exactly those values, and only where the current value is still the one Core wrote.
//   - A dry run reads and decides but writes nothing.
//
// NOT RUN ON WINDOWS: the registry code has been compiled for windows (go vet GOOS=windows) but never executed on a Windows machine. See docs/rmm/WINDOWS_UPDATE_CONTROL_TEST.md.

const (
	JobConfigure = "patch_configure"
	CapConfigure = "patch:configure"
)

// Registry paths (under HKLM, 64 bit view).
const (
	wuPolicyKey   = `SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate`
	auPolicyKey   = `SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU`
	mdmUpdateKey  = `SOFTWARE\Microsoft\PolicyManager\current\device\Update`
	versionKey    = `SOFTWARE\Microsoft\Windows NT\CurrentVersion`
	markerKeyPath = `SOFTWARE\RivetIT\WindowsUpdateControl`
	markerValue   = "State"
)

// Kind is a registry value type Core writes.
type Kind string

const (
	KindDWord  Kind = "dword"
	KindString Kind = "sz"
)

// RegVal is one registry value.
type RegVal struct {
	Kind Kind   `json:"k"`
	N    uint32 `json:"n,omitempty"`
	S    string `json:"s,omitempty"`
}

func dword(n uint32) RegVal { return RegVal{Kind: KindDWord, N: n} }
func str(s string) RegVal   { return RegVal{Kind: KindString, S: s} }

func (v RegVal) String() string {
	if v.Kind == KindDWord {
		return fmt.Sprint(v.N)
	}
	return v.S
}

// jsonValue is the value as the report carries it: a whole number or a string.
func (v RegVal) jsonValue() any {
	if v.Kind == KindDWord {
		return int64(v.N)
	}
	return v.S
}

// owned is one value Core may ever write: its id on the wire, where it lives and what it is.
type owned struct {
	ID   string
	Key  string
	Name string
	Kind Kind
}

// ownedValues is the allow-list, in a fixed order. Names are Microsoft's documented policy values (see docs/rmm/PATCHING.md for the confidence of each).
var ownedValues = []owned{
	{"no_auto_update", auPolicyKey, "NoAutoUpdate", KindDWord},
	{"au_options", auPolicyKey, "AUOptions", KindDWord},
	{"target_release_version", wuPolicyKey, "TargetReleaseVersion", KindDWord},
	{"target_release_version_info", wuPolicyKey, "TargetReleaseVersionInfo", KindString},
	{"product_version", wuPolicyKey, "ProductVersion", KindString},
	{"defer_quality_updates", wuPolicyKey, "DeferQualityUpdates", KindDWord},
	{"defer_quality_days", wuPolicyKey, "DeferQualityUpdatesPeriodInDays", KindDWord},
}

func ownedByID(id string) (owned, bool) {
	for _, o := range ownedValues {
		if o.ID == id {
			return o, true
		}
	}
	return owned{}, false
}

// Registry is the seam to the registry (HKLM, 64 bit view). Missing keys and values are (_, false, nil), never errors.
type Registry interface {
	Get(key, name string) (RegVal, bool, error)
	// Names lists the value names of a key (exists=false: the key is not there).
	Names(key string) (names []string, exists bool, err error)
	Set(key, name string, v RegVal) error
	// Delete removes a value; a missing value is not an error.
	Delete(key, name string) error
}

// ---------------------------------------------------------------- parameters

// ConfigureParams are the validated parameters of patch_configure: the DESIRED state.
type ConfigureParams struct {
	Mode          string // "dry_run" | "apply"
	UpdateMode    string // "leave" | "managed"
	FeatureTarget string // "none" | "hold" | a version such as 25H2
	DeferDays     int    // 0..30, 0 = no deferral policy
}

var versionRe = regexp.MustCompile(`^[0-9]{2}H[12]$`)

// ValidFeatureVersion reports whether s is a named feature version (two digits, H1 or H2).
func ValidFeatureVersion(s string) bool { return versionRe.MatchString(s) }

// ParseConfigureParams validates the patch_configure parameters. An unknown key, a wrong type or a value outside its set refuses the job.
func ParseConfigureParams(raw json.RawMessage) (ConfigureParams, error) {
	m, err := flatParams(raw, "mode", "update_mode", "feature_target", "defer_days")
	if err != nil {
		return ConfigureParams{}, err
	}
	p := ConfigureParams{Mode: "dry_run", UpdateMode: "leave", FeatureTarget: "none"}
	if s, has, err := paramString(m, "mode"); err != nil {
		return p, err
	} else if has {
		p.Mode = s
	}
	if p.Mode != "dry_run" && p.Mode != "apply" {
		return ConfigureParams{}, errParams
	}
	if s, has, err := paramString(m, "update_mode"); err != nil {
		return p, err
	} else if has {
		p.UpdateMode = s
	}
	if p.UpdateMode != "leave" && p.UpdateMode != "managed" {
		return ConfigureParams{}, errParams
	}
	if s, has, err := paramString(m, "feature_target"); err != nil {
		return p, err
	} else if has {
		p.FeatureTarget = s
	}
	if p.FeatureTarget != "none" && p.FeatureTarget != "hold" && !ValidFeatureVersion(p.FeatureTarget) {
		return ConfigureParams{}, errParams
	}
	if p.DeferDays, err = paramInt(m, "defer_days", 0, 30, 0); err != nil {
		return ConfigureParams{}, err
	}
	return p, nil
}

// ---------------------------------------------------------------- the snapshot

// Marker is what Core recorded of its own changes.
type Marker struct {
	V      int                    `json:"v"`
	At     string                 `json:"at"`
	Pinned string                 `json:"pinned,omitempty"`
	Held   bool                   `json:"held,omitempty"` // the pin came from "hold" (the version the device was on), not from a named version
	Keys   map[string]MarkerEntry `json:"keys"`
}

// MarkerEntry is one value Core owns: what it wrote, and what was there before (nil = nothing).
type MarkerEntry struct {
	Wrote RegVal  `json:"wrote"`
	Prev  *RegVal `json:"prev,omitempty"`
}

// Snapshot is everything the decision reads.
type Snapshot struct {
	Policy         map[string]RegVal // owned values present now, by id
	Foreign        []string          // values under the two policy keys that are not Core's ("key\name")
	WSUS           bool
	MDM            bool
	DisplayVersion string // "24H2" ("" when the build has none or it is not a named version)
	Build          string // CurrentBuildNumber
	Installation   string // "Client", "Server", "Server Core"
	Marker         *Marker
	MarkerBad      bool // the marker was there but unreadable
}

var wsusNames = map[string]bool{"usewuserver": true, "wuserver": true, "wustatusserver": true}

// Read takes the snapshot from a registry.
func Read(r Registry) (Snapshot, error) {
	s := Snapshot{Policy: map[string]RegVal{}}
	for _, o := range ownedValues {
		v, ok, err := r.Get(o.Key, o.Name)
		if err != nil {
			return s, err
		}
		if ok && v.Kind == o.Kind {
			s.Policy[o.ID] = v
		} else if ok {
			s.Foreign = append(s.Foreign, o.Key+`\`+o.Name) // the right name holding a value of another type: not something Core wrote
		}
	}
	mk, mbad, err := readMarker(r)
	if err != nil {
		return s, err
	}
	s.Marker, s.MarkerBad = mk, mbad
	// Foreign values: every value of the two policy keys that is not an owned name. (An owned name holding something Core did not write is judged in external().)
	known := map[string]bool{}
	for _, o := range ownedValues {
		known[strings.ToLower(o.Key+`\`+o.Name)] = true
	}
	for _, k := range []string{wuPolicyKey, auPolicyKey} {
		names, exists, err := r.Names(k)
		if err != nil {
			return s, err
		}
		if !exists {
			continue
		}
		for _, n := range names {
			if wsusNames[strings.ToLower(n)] {
				continue // judged as WSUS below, not as "some other policy"
			}
			if !known[strings.ToLower(k+`\`+n)] {
				s.Foreign = append(s.Foreign, k+`\`+n)
			}
		}
	}
	sort.Strings(s.Foreign)
	if v, ok, err := r.Get(auPolicyKey, "UseWUServer"); err != nil {
		return s, err
	} else if ok && v.Kind == KindDWord && v.N == 1 {
		s.WSUS = true
	}
	for _, n := range []string{"WUServer", "WUStatusServer"} {
		if v, ok, err := r.Get(wuPolicyKey, n); err != nil {
			return s, err
		} else if ok && v.Kind == KindString && strings.TrimSpace(v.S) != "" {
			s.WSUS = true
		}
	}
	if !s.WSUS { // WSUS value names that are present but do not point at a server (UseWUServer=0, empty URLs) are still somebody's policy
		for _, n := range []string{"UseWUServer"} {
			if _, ok, _ := r.Get(auPolicyKey, n); ok {
				s.Foreign = append(s.Foreign, auPolicyKey+`\`+n)
			}
		}
		for _, n := range []string{"WUServer", "WUStatusServer"} {
			if _, ok, _ := r.Get(wuPolicyKey, n); ok {
				s.Foreign = append(s.Foreign, wuPolicyKey+`\`+n)
			}
		}
		sort.Strings(s.Foreign)
	}
	names, exists, err := r.Names(mdmUpdateKey)
	if err != nil {
		return s, err
	}
	if exists {
		for _, n := range names {
			ln := strings.ToLower(n)
			if strings.HasSuffix(ln, "_providerset") {
				if v, ok, _ := r.Get(mdmUpdateKey, n); ok && v.Kind == KindDWord && v.N == 1 {
					s.MDM = true
				}
			} else if strings.HasSuffix(ln, "_winningprovider") {
				if v, ok, _ := r.Get(mdmUpdateKey, n); ok && v.Kind == KindString && strings.TrimSpace(v.S) != "" {
					s.MDM = true
				}
			}
		}
	}
	if v, ok, err := r.Get(versionKey, "DisplayVersion"); err != nil {
		return s, err
	} else if ok && v.Kind == KindString && ValidFeatureVersion(strings.TrimSpace(v.S)) {
		s.DisplayVersion = strings.TrimSpace(v.S)
	}
	if v, ok, _ := r.Get(versionKey, "CurrentBuildNumber"); ok && v.Kind == KindString && regexp.MustCompile(`^[0-9]{4,6}$`).MatchString(strings.TrimSpace(v.S)) {
		s.Build = strings.TrimSpace(v.S)
	}
	if v, ok, _ := r.Get(versionKey, "InstallationType"); ok && v.Kind == KindString {
		s.Installation = clip(strings.TrimSpace(v.S), 20)
	}
	return s, nil
}

func readMarker(r Registry) (m *Marker, bad bool, err error) {
	v, ok, err := r.Get(markerKeyPath, markerValue)
	if err != nil || !ok {
		return nil, false, err
	}
	m = parseMarker(v)
	return m, m == nil, nil
}

// parseMarker reads and VALIDATES the marker: a tampered or damaged one can never name anything outside the allow-list, change a type or carry an odd string.
func parseMarker(v RegVal) *Marker {
	if v.Kind != KindString || len(v.S) > 4096 {
		return nil
	}
	var m Marker
	if json.Unmarshal([]byte(v.S), &m) != nil || m.V != 1 {
		return nil
	}
	clean := Marker{V: 1, At: clip(m.At, 40), Held: m.Held, Keys: map[string]MarkerEntry{}}
	if m.Pinned != "" {
		if !ValidFeatureVersion(m.Pinned) {
			return nil
		}
		clean.Pinned = m.Pinned
	}
	for id, e := range m.Keys {
		o, ok := ownedByID(id)
		if !ok || !validVal(o, e.Wrote) || (e.Prev != nil && !validVal(o, *e.Prev)) {
			return nil
		}
		clean.Keys[id] = e
	}
	return &clean
}

var safeStrRe = regexp.MustCompile(`^[A-Za-z0-9 ._-]{0,40}$`)

func validVal(o owned, v RegVal) bool {
	if v.Kind != o.Kind {
		return false
	}
	if v.Kind == KindString {
		return safeStrRe.MatchString(v.S) && v.N == 0
	}
	return v.S == ""
}

// ---------------------------------------------------------------- the decision

// Change is one value that changes (or would): from/to are nil for "not there".
type Change struct {
	Key  string `json:"key"`
	From any    `json:"from"`
	To   any    `json:"to"`
}

// op is one registry operation of a plan.
type op struct {
	ID     string
	Delete bool
	Val    RegVal
	Prev   *RegVal // value to put back when the operation is rolled back (nil = delete)
}

// Decision is the pure outcome of Decide.
type Decision struct {
	Outcome    string // unchanged | changed | restored | would_change | would_restore | externally_managed | cannot_hold
	Detail     string
	External   []string
	Changes    []Change
	ops        []op
	newMarker  *Marker // nil with dropMarker=false: leave it
	dropMarker bool
	Pinned     string
}

// external lists who else owns the Windows Update setting. changed_since means a value Core wrote is no longer what Core wrote.
func external(s Snapshot) (list []string, changedKeys []string) {
	if s.WSUS {
		list = append(list, "wsus")
	}
	if s.MDM {
		list = append(list, "mdm")
	}
	if len(s.Foreign) > 0 {
		list = append(list, "gpo")
	}
	owns := map[string]MarkerEntry{}
	if s.Marker != nil {
		owns = s.Marker.Keys
	}
	for _, o := range ownedValues {
		cur, present := s.Policy[o.ID]
		if !present {
			continue
		}
		e, ours := owns[o.ID]
		if !ours {
			if !contains(list, "gpo") {
				list = append(list, "gpo") // an owned name holding a value Core never wrote
			}
			continue
		}
		if cur != e.Wrote {
			changedKeys = append(changedKeys, o.ID)
		}
	}
	if len(changedKeys) > 0 {
		list = append(list, "changed_since")
	}
	return list, changedKeys
}

func contains(l []string, s string) bool {
	for _, x := range l {
		if x == s {
			return true
		}
	}
	return false
}

func productFor(build string) string {
	n := 0
	fmt.Sscanf(build, "%d", &n)
	if n >= 22000 {
		return "Windows 11"
	}
	return "Windows 10"
}

// wanted is the set of values Core should own for a request (and the version it pins, for the marker).
func wanted(p ConfigureParams, s Snapshot) (w map[string]RegVal, pin string, held bool, d *Decision) {
	w = map[string]RegVal{}
	if p.UpdateMode == "managed" {
		w["no_auto_update"] = dword(0)
		w["au_options"] = dword(2) // 2 = notify for download and notify for install
	}
	if p.FeatureTarget != "none" {
		if s.Installation != "" && s.Installation != "Client" {
			return nil, "", false, &Decision{Outcome: "cannot_hold", Detail: "feature_target_needs_a_client_windows"}
		}
		switch {
		case p.FeatureTarget == "hold":
			held = true
			if s.Marker != nil && s.Marker.Held && s.Marker.Pinned != "" {
				pin = s.Marker.Pinned // already held: a hold is idempotent, it never moves by itself
			} else if s.DisplayVersion != "" {
				pin = s.DisplayVersion
			} else {
				return nil, "", false, &Decision{Outcome: "cannot_hold", Detail: "unknown_feature_version"}
			}
		default:
			pin = p.FeatureTarget
		}
		if s.Build == "" {
			return nil, "", false, &Decision{Outcome: "cannot_hold", Detail: "unknown_build"}
		}
		w["target_release_version"] = dword(1)
		w["target_release_version_info"] = str(pin)
		w["product_version"] = str(productFor(s.Build))
	}
	if p.DeferDays > 0 {
		w["defer_quality_updates"] = dword(1)
		w["defer_quality_days"] = dword(uint32(p.DeferDays))
	}
	return w, pin, held, nil
}

// Decide turns a request and a snapshot into a decision. Pure: no registry, no clock beyond `now` for the marker's timestamp.
func Decide(p ConfigureParams, s Snapshot, now time.Time) Decision {
	apply := p.Mode == "apply"
	ext, changedKeys := external(s)
	empty := p.UpdateMode == "leave" && p.FeatureTarget == "none" && p.DeferDays == 0
	// Someone else owns it: nothing is written. The one exception is a plain restore request after only "changed_since": Core then gives back what is still its own.
	onlyChanged := len(ext) == 1 && ext[0] == "changed_since"
	if len(ext) > 0 && !(empty && onlyChanged) {
		return Decision{Outcome: "externally_managed", External: ext, Detail: "something else manages the Windows Update policy on this device; nothing was changed"}
	}
	d := Decision{External: ext}
	w, pin, held, bad := wanted(p, s)
	if bad != nil {
		bad.External = ext
		return *bad
	}
	owns := map[string]MarkerEntry{}
	if s.Marker != nil {
		for k, v := range s.Marker.Keys {
			owns[k] = v
		}
	}
	newKeys := map[string]MarkerEntry{}
	changedSet := map[string]bool{}
	for _, k := range changedKeys {
		changedSet[k] = true
	}
	for _, o := range ownedValues {
		want, wantIt := w[o.ID]
		cur, present := s.Policy[o.ID]
		e, ours := owns[o.ID]
		switch {
		case wantIt && ours && present && !changedSet[o.ID] && cur == want:
			newKeys[o.ID] = e // already as asked
		case wantIt:
			// write (new, or a changed target of a value Core already owns); the previous value is whatever was there before Core first wrote it
			var prev *RegVal
			if ours {
				prev = e.Prev
			}
			newKeys[o.ID] = MarkerEntry{Wrote: want, Prev: prev}
			var from any
			if present {
				from = cur.jsonValue()
			}
			d.ops = append(d.ops, op{ID: o.ID, Val: want, Prev: ptrIf(present, cur)})
			d.Changes = append(d.Changes, Change{Key: o.ID, From: from, To: want.jsonValue()})
		case ours:
			// no longer wanted: put the previous value back, but only if the value is still what Core wrote
			if present && cur == e.Wrote && !changedSet[o.ID] {
				var to any
				if e.Prev != nil {
					to = e.Prev.jsonValue()
					d.ops = append(d.ops, op{ID: o.ID, Val: *e.Prev, Prev: ptrIf(true, cur)})
				} else {
					d.ops = append(d.ops, op{ID: o.ID, Delete: true, Prev: ptrIf(true, cur)})
				}
				d.Changes = append(d.Changes, Change{Key: o.ID, From: cur.jsonValue(), To: to})
			}
			// absent or changed since: forget it (it is no longer Core's), report nothing to restore
		}
	}
	d.Pinned = pin
	if len(newKeys) == 0 {
		if s.Marker != nil {
			d.dropMarker = true
		}
	} else {
		pinned := ""
		if _, ok := newKeys["target_release_version_info"]; ok {
			pinned = pin
		}
		nm := &Marker{V: 1, At: now.UTC().Format(time.RFC3339), Pinned: pinned, Held: held && pinned != "", Keys: newKeys}
		if s.Marker == nil || !sameMarker(*s.Marker, *nm) {
			d.newMarker = nm // written only when it changes (not on every run)
		}
	}
	if len(newKeys) == 0 && s.Marker == nil && s.MarkerBad {
		d.dropMarker = true // an unreadable marker with nothing to own is removed
	}
	if len(d.Changes) == 0 {
		d.Outcome = "unchanged"
		return d
	}
	switch {
	case apply && empty:
		d.Outcome = "restored"
	case apply:
		d.Outcome = "changed"
	case empty:
		d.Outcome = "would_restore"
	default:
		d.Outcome = "would_change"
	}
	return d
}

func sameMarker(a, b Marker) bool {
	if a.Pinned != b.Pinned || a.Held != b.Held || len(a.Keys) != len(b.Keys) {
		return false
	}
	for k, v := range a.Keys {
		w, ok := b.Keys[k]
		if !ok || v.Wrote != w.Wrote || (v.Prev == nil) != (w.Prev == nil) || (v.Prev != nil && *v.Prev != *w.Prev) {
			return false
		}
	}
	return true
}

func ptrIf(ok bool, v RegVal) *RegVal {
	if !ok {
		return nil
	}
	return &v
}

// ---------------------------------------------------------------- applying

// Apply performs the decision's writes: marker first (so a crash leaves a record of what may have been written), then the values; a failure rolls back what this call did.
func Apply(r Registry, d Decision) error {
	if d.newMarker != nil {
		b, err := json.Marshal(d.newMarker)
		if err != nil {
			return err
		}
		if err := r.Set(markerKeyPath, markerValue, str(string(b))); err != nil {
			return err
		}
	}
	var done []op
	for _, o := range d.ops {
		ov, _ := ownedByID(o.ID)
		var err error
		if o.Delete {
			err = r.Delete(ov.Key, ov.Name)
		} else {
			err = r.Set(ov.Key, ov.Name, o.Val)
		}
		if err != nil {
			rollback(r, done)
			return err
		}
		done = append(done, o)
	}
	if d.dropMarker {
		_ = r.Delete(markerKeyPath, markerValue)
	}
	return nil
}

func rollback(r Registry, done []op) {
	for i := len(done) - 1; i >= 0; i-- {
		o := done[i]
		ov, _ := ownedByID(o.ID)
		if o.Prev == nil {
			_ = r.Delete(ov.Key, ov.Name)
		} else {
			_ = r.Set(ov.Key, ov.Name, *o.Prev)
		}
	}
}

// ---------------------------------------------------------------- the report

// WUState is the `windows_update` object of a scan and the before/after of a configure result.
type WUState struct {
	FeatureVersion string         `json:"feature_version"`
	Build          string         `json:"build"`
	Product        string         `json:"product"`
	External       []string       `json:"external"`
	Policy         map[string]any `json:"policy"`
	Core           WUCore         `json:"core"`
}

// WUCore is what Core owns on the device.
type WUCore struct {
	Keys   []string `json:"keys"`
	Pinned *string  `json:"pinned"`
	Held   bool     `json:"held"`
}

// BuildState renders a snapshot for the server.
func BuildState(s Snapshot) WUState {
	st := WUState{FeatureVersion: s.DisplayVersion, Build: s.Build, External: []string{}, Policy: map[string]any{}, Core: WUCore{Keys: []string{}}}
	switch {
	case s.Installation != "" && s.Installation != "Client":
		st.Product = "Windows Server"
	case s.Build != "":
		st.Product = productFor(s.Build)
	}
	ext, _ := external(s)
	if ext != nil {
		st.External = ext
	}
	for _, o := range ownedValues {
		if v, ok := s.Policy[o.ID]; ok {
			st.Policy[o.ID] = v.jsonValue()
		}
	}
	if s.Marker != nil {
		for _, o := range ownedValues {
			if _, ok := s.Marker.Keys[o.ID]; ok {
				st.Core.Keys = append(st.Core.Keys, o.ID)
			}
		}
		if s.Marker.Pinned != "" {
			p := s.Marker.Pinned
			st.Core.Pinned = &p
			st.Core.Held = s.Marker.Held
		}
	}
	return st
}

// ConfigureOut is the output of patch_configure.
type ConfigureOut struct {
	V       int      `json:"v"`
	OK      bool     `json:"ok"`
	Mode    string   `json:"mode"`
	Outcome string   `json:"outcome"`
	Detail  string   `json:"detail,omitempty"`
	Changes []Change `json:"changes"`
	Before  WUState  `json:"before"`
	After   WUState  `json:"after"`
}

// Configure runs a whole request against a registry: read, decide, (apply), read again, report. It is the testable body of the job.
func Configure(r Registry, p ConfigureParams, now time.Time) (ConfigureOut, error) {
	before, err := Read(r)
	if err != nil {
		return ConfigureOut{}, err
	}
	d := Decide(p, before, now)
	out := ConfigureOut{V: 1, OK: true, Mode: p.Mode, Outcome: d.Outcome, Detail: d.Detail, Changes: d.Changes, Before: BuildState(before)}
	if out.Changes == nil {
		out.Changes = []Change{}
	}
	if p.Mode == "apply" && (len(d.ops) > 0 || d.dropMarker || d.newMarker != nil) && d.Outcome != "externally_managed" && d.Outcome != "cannot_hold" {
		if err := Apply(r, d); err != nil {
			return ConfigureOut{}, err
		}
		after, err := Read(r)
		if err != nil {
			return ConfigureOut{}, err
		}
		out.After = BuildState(after)
		return out, nil
	}
	out.After = out.Before
	return out, nil
}
