package change

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
	"unicode/utf16"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/remote"
)

// ---- signing ----

type signer struct {
	pub  ed25519.PublicKey
	priv ed25519.PrivateKey
}

func newSigner(t *testing.T) *signer {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return &signer{pub, priv}
}

func (s *signer) verify() VerifyFunc {
	v := &jobs.Verifier{PublicKey: s.pub}
	return v.VerifyDomain
}

// sign returns the configuration object with its signature, as the server sends it.
func (s *signer) sign(t *testing.T, cfg map[string]any) json.RawMessage {
	t.Helper()
	raw, _ := json.Marshal(cfg)
	canon, err := jobs.Canonical(raw)
	if err != nil {
		t.Fatal(err)
	}
	cfg["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(s.priv, append([]byte(SignDomain+"\n"), canon...)))
	raw, _ = json.Marshal(cfg)
	return raw
}

func baseConfig(platform, version string, watches ...map[string]any) map[string]any {
	ws := make([]any, len(watches))
	for i, w := range watches {
		ws[i] = w
	}
	return map[string]any{"v": 1, "kind": "change_config", "platform": platform, "interval_s": 60, "batch_max": 100, "rate_per_min": 600, "max_value_bytes": 256, "watches": ws, "version": version}
}

func regWatch(id string, paths ...map[string]any) map[string]any {
	ps := make([]any, len(paths))
	for i, p := range paths {
		ps[i] = p
	}
	return map[string]any{"id": id, "kind": "registry", "paths": ps}
}

func fileWatch(id string, paths ...map[string]any) map[string]any {
	ps := make([]any, len(paths))
	for i, p := range paths {
		ps[i] = p
	}
	return map[string]any{"id": id, "kind": "file", "paths": ps}
}

// ---- fake registry ----

type fakeKey struct {
	vals []RegValue
	subs []string
}

type fakeReg struct {
	mu        sync.Mutex
	keys      map[string]*fakeKey // lower-case path
	deny      map[string]bool
	opened    []string
	users     []string
	listError error
}

func newFakeReg() *fakeReg { return &fakeReg{keys: map[string]*fakeKey{}, deny: map[string]bool{}} }

func (f *fakeReg) set(path string, subs []string, vals ...RegValue) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.keys[strings.ToLower(path)] = &fakeKey{vals: vals, subs: subs}
}

func (f *fakeReg) del(path string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	delete(f.keys, strings.ToLower(path))
}

func (f *fakeReg) get(path string) (*fakeKey, error) {
	f.opened = append(f.opened, path)
	if f.deny[strings.ToLower(path)] {
		return nil, ErrAccess
	}
	k, ok := f.keys[strings.ToLower(path)]
	if !ok {
		return nil, ErrNotExist
	}
	return k, nil
}

func (f *fakeReg) SubKeys(path string) ([]string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	k, err := f.get(path)
	if err != nil {
		return nil, err
	}
	return append([]string(nil), k.subs...), nil
}

func (f *fakeReg) Values(path string, want func(string) bool) ([]RegValue, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	k, err := f.get(path)
	if err != nil {
		return nil, err
	}
	var out []RegValue
	for _, v := range k.vals {
		if !want(v.Name) {
			continue
		}
		out = append(out, v) // a misbehaving reader may even hand over the data of a secret: the engine must still mask it
	}
	return out, nil
}

func (f *fakeReg) Users() ([]string, error) { return f.users, f.listError }

func (f *fakeReg) openedPath(p string) bool {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, o := range f.opened {
		if strings.EqualFold(o, p) {
			return true
		}
	}
	return false
}

func u16(s string) []byte {
	var b bytes.Buffer
	for _, c := range utf16.Encode([]rune(s + "\x00")) {
		binary.Write(&b, binary.LittleEndian, c)
	}
	return b.Bytes()
}

func szVal(name, text string) RegValue {
	raw := u16(text)
	return RegValue{Name: name, Type: regSZ, Raw: raw, Size: len(raw)}
}

func dwordVal(name string, v uint32) RegValue {
	raw := make([]byte, 4)
	binary.LittleEndian.PutUint32(raw, v)
	return RegValue{Name: name, Type: regDWord, Raw: raw, Size: 4}
}

// ---- fake runner ----

type fakeRunner struct {
	mu    sync.Mutex
	calls [][]string
	fn    func(name string, args []string) (remote.RunResult, error)
}

func (f *fakeRunner) Run(_ context.Context, name string, args []string, _ int, _ time.Duration) (remote.RunResult, error) {
	f.mu.Lock()
	f.calls = append(f.calls, append([]string{name}, args...))
	fn := f.fn
	f.mu.Unlock()
	if fn == nil {
		return remote.RunResult{ExitCode: 1}, nil
	}
	return fn(name, args)
}

func (f *fakeRunner) count() int { f.mu.Lock(); defer f.mu.Unlock(); return len(f.calls) }

// ---- clock ----

type fakeClock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *fakeClock) Now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.t }
func (c *fakeClock) Advance(d time.Duration) {
	c.mu.Lock()
	c.t = c.t.Add(d)
	c.mu.Unlock()
}

// ---- the rig ----

type rig struct {
	t      *testing.T
	sg     *signer
	clk    *fakeClock
	m      *Manager
	reg    *fakeReg
	run    *fakeRunner
	state  string
	roots  []string
	sendFn func(body []byte) (*api.ChangeAck, error)
	mu     sync.Mutex
	bodies [][]byte
	goos   string
	taskRt string
}

func newRig(t *testing.T, goos string) *rig {
	r := &rig{t: t, sg: newSigner(t), clk: &fakeClock{t: time.Date(2026, 10, 18, 12, 0, 0, 0, time.UTC)}, reg: newFakeReg(), run: &fakeRunner{}, goos: goos}
	r.state = filepath.Join(t.TempDir(), "change_state.json")
	r.build()
	return r
}

// build (re)creates the Manager on the same state file: an agent restart.
func (r *rig) build() {
	var reg Registry
	if r.goos == "windows" {
		reg = r.reg
	}
	r.m = New(Options{
		Registry: reg, Runner: r.run, StatePath: r.state, Now: r.clk.Now, GOOS: r.goos, Verify: r.sg.verify(),
		Log:        slog.New(slog.NewTextHandler(io.Discard, nil)),
		Redact:     func(s string) string { return jobs.Redact(s) },
		ExtraRoots: func() []string { return r.roots },
		TaskRoot:   r.taskRt,
		Send: func(_ context.Context, body []byte) (*api.ChangeAck, error) {
			r.mu.Lock()
			r.bodies = append(r.bodies, append([]byte(nil), body...))
			fn := r.sendFn
			r.mu.Unlock()
			if fn != nil {
				return fn(body)
			}
			return &api.ChangeAck{OK: true}, nil
		},
		Backoff:       api.Backoff{Base: time.Second, Max: time.Minute},
		StartNotifier: func([]NotifyRoot, func()) func() { return func() {} },
	})
}

func (r *rig) configure(version string, watches ...map[string]any) {
	r.t.Helper()
	if err := r.m.Configure(r.sg.sign(r.t, baseConfig(r.goos, version, watches...))); err != nil {
		r.t.Fatalf("configure: %v", err)
	}
}

// tick runs one pass at the current time.
func (r *rig) tick() time.Duration { return r.m.Tick(context.Background()) }

// next moves past the polling interval and runs a pass.
func (r *rig) next() time.Duration {
	r.clk.Advance(61 * time.Second)
	return r.tick()
}

type sentBatch struct {
	Changes       []Record        `json:"changes"`
	Dropped       int             `json:"dropped"`
	ConfigVersion string          `json:"config_version"`
	Status        json.RawMessage `json:"status"`
}

func (r *rig) batches() []sentBatch {
	r.mu.Lock()
	defer r.mu.Unlock()
	var out []sentBatch
	for _, b := range r.bodies {
		var sb sentBatch
		if err := json.Unmarshal(b, &sb); err != nil {
			r.t.Fatalf("bad body %s: %v", b, err)
		}
		out = append(out, sb)
	}
	return out
}

func (r *rig) records() []Record {
	var out []Record
	for _, b := range r.batches() {
		out = append(out, b.Changes...)
	}
	return out
}

func (r *rig) reset() { r.mu.Lock(); r.bodies = nil; r.mu.Unlock() }

func (r *rig) stateText() string {
	b, _ := os.ReadFile(r.state)
	return string(b)
}

func find(recs []Record, item string) *Record {
	for i := range recs {
		if recs[i].Item == item {
			return &recs[i]
		}
	}
	return nil
}

// signNoDomain signs the canonical object WITHOUT the domain line: what a job or a check signature looks like.
func signNoDomain(t *testing.T, s *signer, cfg map[string]any) json.RawMessage {
	t.Helper()
	raw, _ := json.Marshal(cfg)
	canon, err := jobs.Canonical(raw)
	if err != nil {
		t.Fatal(err)
	}
	cfg["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(s.priv, canon))
	raw, _ = json.Marshal(cfg)
	return raw
}

func jsonMarshal(v any) (json.RawMessage, error) { b, err := json.Marshal(v); return b, err }
