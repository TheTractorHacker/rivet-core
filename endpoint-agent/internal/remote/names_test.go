package remote

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"
)

// hostileNames are values that must never reach systemctl, sc or a process lookup.
var hostileNames = []string{
	"", " ", "-rf", "--now", "-", ".hidden", ".service", "a b", "a\tb", "a\nb", "a\x00b", "x;reboot", "$(id)", "`id`", "a|b", "a&b", "a>b", "a<b", "a\"b", "a'b",
	"../etc/passwd", "a/b", "a\\b", "café", "ａｂｃ", strings.Repeat("a", 201), "*", "a=b", "a,b", "a(b)", "a{b}",
}

func TestNormalizeUnitRefusesHostileNames(t *testing.T) {
	for _, n := range hostileNames {
		if got, ok := NormalizeUnit(n); ok {
			t.Errorf("NormalizeUnit(%q) accepted as %q", n, got)
		}
	}
}

func TestNormalizeUnitSuffixes(t *testing.T) {
	good := map[string]string{
		"ssh": "ssh.service", "ssh.service": "ssh.service", "getty@tty1": "getty@tty1.service", "systemd-resolved": "systemd-resolved.service",
		"my.app": "my.app.service", "a:b": "a:b.service", "A_1-2.service": "A_1-2.service",
	}
	for in, want := range good {
		got, ok := NormalizeUnit(in)
		if !ok || got != want {
			t.Errorf("NormalizeUnit(%q) = %q, %v; want %q", in, got, ok, want)
		}
	}
	for _, in := range []string{"cron.socket", "x.timer", "x.target", "x.mount", "x.path", "x.slice", "x.scope", "x.swap", "x.device", "x.automount"} {
		if got, ok := NormalizeUnit(in); ok {
			t.Errorf("NormalizeUnit(%q) accepted as %q: only .service units are managed", in, got)
		}
	}
}

func TestWindowsServiceNames(t *testing.T) {
	for _, n := range []string{"Spooler", "wuauserv", "MSSQL$SQLEXPRESS", "_x", "9lives", "a.b", "svc@1", "a:b", "a-b"} {
		if !ValidWindowsService(n) {
			t.Errorf("%q should be valid", n)
		}
	}
	for _, n := range append([]string{"-x", "$x", ":x", "@x", ".x"}, hostileNames...) {
		if ValidWindowsService(n) {
			t.Errorf("%q must be refused", n)
		}
	}
}

func TestProcessNames(t *testing.T) {
	for _, n := range []string{"sleep", "notepad.exe", "Code Helper (Renderer)", "a+b", "x[1]", "a#b@c~d", "7z"} {
		if !ValidProcName(n) {
			t.Errorf("%q should be valid", n)
		}
	}
	for _, n := range []string{"", "x;y", "$(id)", "a\nb", "a\x00b", "/bin/sh", "a\\b", "a|b", "a`b", strings.Repeat("a", 129), "café"} {
		if ValidProcName(n) {
			t.Errorf("%q must be refused", n)
		}
	}
}

func TestParsePID(t *testing.T) {
	type c struct {
		raw  string
		goos string
		want int
		ok   bool
	}
	for _, x := range []c{
		{"2", "linux", 2, true}, {"4242", "linux", 4242, true}, {"4194304", "linux", 4194304, true}, {" 77 ", "linux", 77, true},
		{"0", "linux", 0, false}, {"1", "linux", 0, false}, {"-1", "linux", 0, false}, {"4194305", "linux", 0, false}, {"99999999999", "linux", 0, false},
		{"1.0", "linux", 0, false}, {"1e3", "linux", 0, false}, {`"42"`, "linux", 0, false}, {"true", "linux", 0, false}, {"null", "linux", 0, false},
		{"", "linux", 0, false}, {"0x10", "linux", 0, false}, {"+5", "linux", 0, false}, {"[5]", "linux", 0, false}, {"4", "windows", 0, false},
		{"0", "windows", 0, false}, {"5", "windows", 5, true}, {"4294967295", "windows", 4294967295, true}, {"4294967296", "windows", 0, false},
	} {
		got, ok := ParsePID(json.RawMessage(x.raw), x.goos)
		if ok != x.ok || (ok && got != x.want) {
			t.Errorf("ParsePID(%s, %s) = %d, %v; want %d, %v", x.raw, x.goos, got, ok, x.want, x.ok)
		}
	}
}

func TestCheckServiceAction(t *testing.T) {
	type c struct{ goos, name, action, canon, detail string }
	for _, x := range []c{
		{"linux", "ssh", "restart", "ssh.service", ""},
		{"linux", "ssh", "start", "ssh.service", ""},
		{"linux", "ssh", "enable", "ssh.service", ""},
		{"linux", "ssh", "kill", "", DetailInvalidAction},
		{"linux", "ssh", "RESTART", "", DetailInvalidAction},
		{"linux", "--now", "start", "", DetailInvalidName},
		{"linux", "a b", "start", "", DetailInvalidName},
		{"linux", "ssh", "manual", "ssh.service", DetailUnsupportedAct},
		{"linux", "rivetit-agent", "stop", "rivetit-agent.service", DetailRefusedSelf},
		{"linux", "rivetit-agent.service", "restart", "rivetit-agent.service", DetailRefusedSelf},
		{"linux", "rivetit-agent", "disable", "rivetit-agent.service", DetailRefusedSelf},
		{"linux", "rivetit-agent", "start", "rivetit-agent.service", ""},
		{"linux", "dbus", "restart", "dbus.service", DetailRefusedProtect},
		{"linux", "systemd-journald", "stop", "systemd-journald.service", DetailRefusedProtect},
		{"linux", "systemd-journald", "restart", "systemd-journald.service", ""},
		{"windows", "Spooler", "restart", "Spooler", ""},
		{"windows", "Spooler", "manual", "Spooler", ""},
		{"windows", "RivetITAgent", "stop", "RivetITAgent", DetailRefusedSelf},
		{"windows", "rivetitagent", "restart", "rivetitagent", DetailRefusedSelf},
		{"windows", "RpcSs", "stop", "RpcSs", DetailRefusedProtect},
		{"windows", "RPCSS", "disable", "RPCSS", DetailRefusedProtect},
		{"windows", "EventLog", "disable", "EventLog", DetailRefusedProtect},
		{"windows", "EventLog", "restart", "EventLog", ""},
		{"windows", "a b", "start", "", DetailInvalidName},
		{"windows", "$x", "start", "", DetailInvalidName},
	} {
		canon, detail := CheckServiceAction(x.goos, x.name, x.action)
		if canon != x.canon || detail != x.detail {
			t.Errorf("%s %q %q = (%q, %q); want (%q, %q)", x.goos, x.name, x.action, canon, detail, x.canon, x.detail)
		}
	}
}

// ---- fakes ----

type fakeSvc struct {
	rows      []Service
	state     map[string]string
	startup   map[string]string
	applied   []string
	applyErr  error
	statusErr error
	listErr   error
}

func (f *fakeSvc) List(context.Context) ([]Service, error) {
	return append([]Service(nil), f.rows...), f.listErr
}
func (f *fakeSvc) Status(_ context.Context, n string) (string, string, error) {
	if f.statusErr != nil {
		return "", "", f.statusErr
	}
	s, ok := f.state[n]
	if !ok {
		return "", "", ErrServiceNotFound
	}
	return s, f.startup[n], nil
}
func (f *fakeSvc) Apply(_ context.Context, n, a string) error {
	f.applied = append(f.applied, a+" "+n)
	if f.applyErr != nil {
		return f.applyErr
	}
	switch a {
	case "start", "restart":
		f.state[n] = StateRunning
	case "stop":
		f.state[n] = StateStopped
	case "enable":
		f.startup[n] = StartupAuto
	case "disable":
		f.startup[n] = StartupDisabled
	}
	return nil
}

func newFakeSvc() *fakeSvc {
	return &fakeSvc{state: map[string]string{"ssh.service": StateRunning, "rivetit-agent.service": StateRunning, "dbus.service": StateRunning},
		startup: map[string]string{"ssh.service": StartupAuto, "rivetit-agent.service": StartupAuto, "dbus.service": StartupStatic}}
}

func decodeOut(t *testing.T, r Result) map[string]any {
	t.Helper()
	var m map[string]any
	if err := json.Unmarshal([]byte(r.Output), &m); err != nil {
		t.Fatalf("output is not JSON: %v: %q", err, r.Output)
	}
	return m
}

func TestServiceControlHappyPathAndRefusals(t *testing.T) {
	f := newFakeSvc()
	s := Services{GOOS: "linux", B: f}
	ctx := context.Background()
	r := s.Control(ctx, json.RawMessage(`{"name":"ssh","action":"restart"}`), 4096)
	if !r.OK || r.Detail != "" {
		t.Fatalf("restart: %+v", r)
	}
	m := decodeOut(t, r)
	if m["name"] != "ssh.service" || m["before"] != "running" || m["after"] != "running" || m["ok"] != true {
		t.Errorf("unexpected output %v", m)
	}
	if len(f.applied) != 1 || f.applied[0] != "restart ssh.service" {
		t.Errorf("applied %v", f.applied)
	}
	for _, body := range []string{
		`{"name":"rivetit-agent","action":"stop"}`, `{"name":"rivetit-agent.service","action":"restart"}`, `{"name":"dbus","action":"stop"}`,
		`{"name":"--now","action":"start"}`, `{"name":"x;reboot","action":"start"}`, `{"name":"ssh","action":"kill"}`, `{"name":"ssh","action":"manual"}`,
		`{"name":"nonexistent","action":"start"}`, `{"name":5,"action":"start"}`, `{"action":"start"}`, `[]`, `not json`,
	} {
		before := len(f.applied)
		r := s.Control(ctx, json.RawMessage(body), 4096)
		if r.OK {
			t.Errorf("%s was accepted", body)
		}
		if len(f.applied) != before {
			t.Errorf("%s reached the backend", body)
		}
		var m map[string]any
		if err := json.Unmarshal([]byte(r.Output), &m); err != nil {
			t.Errorf("%s: output is not JSON: %q", body, r.Output)
		}
	}
	if r := s.Control(ctx, json.RawMessage(`{"name":"nonexistent","action":"start"}`), 4096); r.Detail != DetailNotFound {
		t.Errorf("unknown unit detail = %q", r.Detail)
	}
	if r := s.Control(ctx, json.RawMessage(`{"name":"rivetit-agent","action":"stop"}`), 4096); r.Detail != DetailRefusedSelf {
		t.Errorf("self detail = %q", r.Detail)
	}
	if r := s.Control(ctx, json.RawMessage(`{`), 4096); !r.Cannot {
		t.Errorf("a params object that does not parse never started: %+v", r)
	}
}

func TestServiceControlBackendFailure(t *testing.T) {
	f := newFakeSvc()
	f.applyErr = errors.New("systemctl_failed: exit 1: Failed to restart ssh.service")
	r := Services{GOOS: "linux", B: f}.Control(context.Background(), json.RawMessage(`{"name":"ssh","action":"restart"}`), 4096)
	if r.OK || !strings.HasPrefix(r.Detail, "systemctl_failed") {
		t.Fatalf("%+v", r)
	}
	if m := decodeOut(t, r); m["ok"] != false {
		t.Errorf("%v", m)
	}
}

func TestServiceListFitsTheCapWithoutCuttingText(t *testing.T) {
	f := newFakeSvc()
	f.rows = nil
	for i := 0; i < 400; i++ {
		f.rows = append(f.rows, Service{Name: "svc-" + strings.Repeat("x", 30) + string(rune('a'+i%26)) + time.Duration(i).String() + ".service", Display: "Display name of the service number " + time.Duration(i).String(), State: StateRunning, Startup: StartupAuto})
	}
	s := Services{GOOS: "linux", B: f}
	full := s.List(context.Background(), nil, 1<<20)
	if !full.OK {
		t.Fatalf("%+v", full)
	}
	if m := decodeOut(t, full); m["truncated"] != false || int(m["count"].(float64)) != 400 {
		t.Fatalf("full list: %v", m["count"])
	}
	for _, cap := range []int{40000, 10000, 2000, 300} {
		r := s.List(context.Background(), nil, cap)
		if !r.OK {
			t.Fatalf("cap %d: %+v", cap, r)
		}
		if len(r.Output) > cap {
			t.Errorf("cap %d: output has %d bytes", cap, len(r.Output))
		}
		m := decodeOut(t, r) // must stay valid JSON
		if int(m["count"].(float64)) != len(m["services"].([]any)) {
			t.Errorf("cap %d: count does not match the list", cap)
		}
		if int(m["count"].(float64)) < 400 && m["truncated"] != true {
			t.Errorf("cap %d: shortened without saying so", cap)
		}
	}
	if r := s.List(context.Background(), nil, 20); r.OK || r.Detail != "output_cap_too_small" {
		t.Errorf("an impossible cap: %+v", r)
	}
}

func TestServiceListCapsAtMaxServices(t *testing.T) {
	f := newFakeSvc()
	f.rows = nil
	for i := 0; i < MaxServices+50; i++ {
		f.rows = append(f.rows, Service{Name: "s" + time.Duration(i).String(), State: StateStopped, Startup: StartupManual})
	}
	r := Services{GOOS: "linux", B: f}.List(context.Background(), nil, 1<<22)
	m := decodeOut(t, r)
	if int(m["count"].(float64)) != MaxServices || m["truncated"] != true {
		t.Errorf("count %v truncated %v", m["count"], m["truncated"])
	}
}

// ---- systemd argv ----

type recRunner struct {
	calls [][]string
	out   map[string]RunResult
}

func (r *recRunner) Run(_ context.Context, name string, args []string, _ int, _ time.Duration) (RunResult, error) {
	r.calls = append(r.calls, append([]string{name}, args...))
	if res, ok := r.out[args[0]]; ok {
		return res, nil
	}
	return RunResult{}, nil
}

func TestSystemdArgvIsAListWithADoubleDash(t *testing.T) {
	for _, action := range []string{"start", "stop", "restart", "enable", "disable"} {
		for _, unit := range []string{"ssh.service", "getty@tty1.service"} {
			argv, ok := ControlArgv(action, unit)
			if !ok || len(argv) != 3 || argv[0] != action || argv[1] != "--" || argv[2] != unit {
				t.Errorf("ControlArgv(%s, %s) = %v", action, unit, argv)
			}
		}
	}
	if _, ok := ControlArgv("manual", "ssh.service"); ok {
		t.Error("manual has no systemd verb")
	}
	if _, ok := ControlArgv("kill", "ssh.service"); ok {
		t.Error("kill is not a verb")
	}
	show := ShowArgv("ssh.service")
	if show[len(show)-2] != "--" || show[len(show)-1] != "ssh.service" {
		t.Errorf("ShowArgv %v", show)
	}
	rr := &recRunner{}
	sd := Systemd{R: rr}
	if err := sd.Apply(context.Background(), "ssh.service", "restart"); err != nil {
		t.Fatal(err)
	}
	want := []string{"systemctl", "restart", "--", "ssh.service"}
	if len(rr.calls) != 1 || strings.Join(rr.calls[0], "\x00") != strings.Join(want, "\x00") {
		t.Errorf("calls %v", rr.calls)
	}
	for _, c := range rr.calls {
		for _, a := range c {
			if strings.ContainsAny(a, " \t\n;|&$`") && a != "" {
				t.Errorf("argument %q looks like a shell string", a)
			}
		}
	}
}

const listUnitsFixture = `  ssh.service                       loaded    active   running OpenBSD Secure Shell server
  cron.service                      loaded    active   running Regular background program processing daemon
  foo.service                       not-found inactive dead    foo.service
● failed-thing.service              loaded    failed   failed  A failed thing
  getty@tty1.service                loaded    active   running Getty on tty1
  systemd-journald.service          loaded    active   running Journal Service
  user@1000.service                 loaded    active   running User Manager for UID 1000
`

const unitFilesFixture = `ssh.service                            enabled         enabled
cron.service                           enabled         enabled
getty@.service                         enabled         enabled
masked-one.service                     masked          enabled
static-one.service                     static          -
failed-thing.service                   disabled        enabled
`

func TestSystemdListParsing(t *testing.T) {
	rr := &recRunner{out: map[string]RunResult{
		"list-units":      {Stdout: []byte(listUnitsFixture)},
		"list-unit-files": {Stdout: []byte(unitFilesFixture)},
	}}
	svcs, err := Systemd{R: rr}.List(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	by := map[string]Service{}
	for _, s := range svcs {
		by[s.Name] = s
	}
	if by["ssh.service"].State != StateRunning || by["ssh.service"].Startup != StartupAuto || by["ssh.service"].Display != "OpenBSD Secure Shell server" {
		t.Errorf("ssh: %+v", by["ssh.service"])
	}
	if by["failed-thing.service"].State != StateFailed || by["failed-thing.service"].Startup != StartupDisabled {
		t.Errorf("failed-thing: %+v", by["failed-thing.service"])
	}
	if _, ok := by["foo.service"]; ok {
		t.Error("a not-found unit must not be listed")
	}
	if s, ok := by["masked-one.service"]; !ok || s.State != StateStopped || s.Startup != StartupDisabled {
		t.Errorf("masked-one: %+v %v", s, ok)
	}
	if s := by["static-one.service"]; s.Startup != StartupStatic {
		t.Errorf("static-one: %+v", s)
	}
	if _, ok := by["getty@.service"]; ok {
		t.Error("a template unit must not be listed")
	}
	if by["getty@tty1.service"].State != StateRunning {
		t.Errorf("getty@tty1: %+v", by["getty@tty1.service"])
	}
	for _, c := range rr.calls {
		if c[0] != "systemctl" || c[1] == "" {
			t.Errorf("call %v", c)
		}
	}
}

func TestSystemdStatusAndNotFound(t *testing.T) {
	rr := &recRunner{out: map[string]RunResult{"show": {Stdout: []byte("LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n")}}}
	st, startup, err := Systemd{R: rr}.Status(context.Background(), "ssh.service")
	if err != nil || st != StateRunning || startup != StartupAuto {
		t.Errorf("%s %s %v", st, startup, err)
	}
	rr = &recRunner{out: map[string]RunResult{"show": {Stdout: []byte("LoadState=not-found\nActiveState=inactive\nUnitFileState=\n")}}}
	if _, _, err := (Systemd{R: rr}).Status(context.Background(), "nope.service"); !errors.Is(err, ErrServiceNotFound) {
		t.Errorf("err = %v", err)
	}
}
