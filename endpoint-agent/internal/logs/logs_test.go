package logs

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/remote"
)

var t0 = time.Date(2026, 10, 11, 12, 0, 0, 0, time.UTC)

// ---- journald ----

func TestJournalArgvIsAnArgumentList(t *testing.T) {
	src := api.LogSource{Kind: "journal", Units: []string{"ssh.service", "cron.service"}, Identifiers: []string{"sudo"}, MinLevel: "warning"}
	got, err := JournalArgv(src, 200, "s=abc;i=1;b=2", t0)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"--output=json", "--no-pager", "--quiet", "--lines=200", "--after-cursor=s=abc;i=1;b=2", "--priority=4", "_SYSTEMD_UNIT=ssh.service", "_SYSTEMD_UNIT=cron.service", "SYSLOG_IDENTIFIER=sudo"}
	if strings.Join(got, "\x00") != strings.Join(want, "\x00") {
		t.Errorf("argv\n got %q\nwant %q", got, want)
	}
	first, _ := JournalArgv(api.LogSource{Kind: "journal"}, 50, "", t0)
	if first[4] != "--since=2026-10-11 11:55:00 UTC" {
		t.Errorf("the first run starts five minutes back: %q", first)
	}
	for _, a := range first {
		if strings.HasPrefix(a, "--after-cursor") {
			t.Error("no cursor on the first run")
		}
	}
}

func TestJournalArgvRefusesHostileInput(t *testing.T) {
	for _, bad := range []string{"-x", "--unit=ssh", "a b", "a;b", "$(id)", "a\nb", "", strings.Repeat("a", 201), "a/b", "café"} {
		if _, err := JournalArgv(api.LogSource{Units: []string{bad}}, 10, "", t0); err == nil {
			t.Errorf("unit %q accepted", bad)
		}
		if _, err := JournalArgv(api.LogSource{Identifiers: []string{bad}}, 10, "", t0); err == nil {
			t.Errorf("identifier %q accepted", bad)
		}
	}
	for _, cur := range []string{"a b", "$(id)", "x\n", "a`b", "a|b", strings.Repeat("a", 513)} {
		if _, err := JournalArgv(api.LogSource{}, 10, cur, t0); err == nil {
			t.Errorf("cursor %q accepted", cur)
		}
	}
	for _, lv := range []string{"fatal", "5", "warning;reboot", "ERROR"} {
		if _, err := JournalArgv(api.LogSource{MinLevel: lv}, 10, "", t0); err == nil {
			t.Errorf("level %q accepted", lv)
		}
	}
	if !ValidJournalName("getty@tty1.service") || ValidJournalName("-x") {
		t.Error("ValidJournalName")
	}
}

func TestJournalPriorities(t *testing.T) {
	for lv, want := range map[string]int{"critical": 2, "error": 3, "warning": 4, "info": 6, "debug": 7} {
		if got, ok := JournalPriority(lv); !ok || got != want {
			t.Errorf("%s -> %d %v", lv, got, ok)
		}
	}
	for p, want := range map[int]string{0: "critical", 2: "critical", 3: "error", 4: "warning", 5: "info", 6: "info", 7: "debug"} {
		if LevelName(p) != want {
			t.Errorf("LevelName(%d) = %s", p, LevelName(p))
		}
	}
}

func jline(m map[string]any) string { b, _ := json.Marshal(m); return string(b) }

func TestParseJournalLine(t *testing.T) {
	ok := jline(map[string]any{"__CURSOR": "s=1;i=2", "__REALTIME_TIMESTAMP": "1791720000123456", "PRIORITY": "3", "_SYSTEMD_UNIT": "ssh.service", "SYSLOG_IDENTIFIER": "sshd", "MESSAGE": "Failed password for root\nfrom 10.0.0.1\twith a\x07bell"})
	ev, cur, valid := ParseJournalLine([]byte(ok))
	if !valid || cur != "s=1;i=2" {
		t.Fatalf("%v %q", valid, cur)
	}
	if ev.Level != "error" || ev.Source != "ssh.service" || ev.TS != "2026-10-11T12:00:00Z" || strings.ContainsAny(ev.Message, "\n\t\x07") {
		t.Errorf("%+v", ev)
	}
	// the syslog identifier is the source when there is no unit
	ev, _, _ = ParseJournalLine([]byte(jline(map[string]any{"__REALTIME_TIMESTAMP": "1791720000000000", "SYSLOG_IDENTIFIER": "sudo", "MESSAGE": "x"})))
	if ev.Source != "sudo" || ev.Level != "info" {
		t.Errorf("%+v", ev)
	}
	// MESSAGE as a byte array (journald encodes binary or non-UTF-8 data that way)
	ev, _, valid = ParseJournalLine([]byte(`{"__REALTIME_TIMESTAMP":"1791720000000000","MESSAGE":[104,105,255,33],"PRIORITY":"4"}`))
	if !valid || !strings.HasPrefix(ev.Message, "hi") || !strings.HasSuffix(ev.Message, "!") || ev.Level != "warning" || ev.Source != "unknown" {
		t.Errorf("%+v %v", ev, valid)
	}
	// missing or wrong fields never panic; a skipped record still gives its cursor
	for _, bad := range []string{``, `{`, `[]`, `null`, `{"MESSAGE":"x"}`, `{"__REALTIME_TIMESTAMP":"abc","MESSAGE":"x"}`, `{"__REALTIME_TIMESTAMP":"0","MESSAGE":"x"}`, `{"__REALTIME_TIMESTAMP":"-5","MESSAGE":"x"}`,
		`{"__REALTIME_TIMESTAMP":"1791720000000000"}`, `{"__REALTIME_TIMESTAMP":"1791720000000000","MESSAGE":{"a":1}}`, `{"__REALTIME_TIMESTAMP":"1791720000000000","MESSAGE":[300]}`} {
		if _, _, v := ParseJournalLine([]byte(bad)); v {
			t.Errorf("%q parsed as valid", bad)
		}
	}
	if _, cur, v := ParseJournalLine([]byte(`{"__CURSOR":"c1","__REALTIME_TIMESTAMP":"1791720000000000"}`)); v || cur != "c1" {
		t.Errorf("a record without MESSAGE advances the cursor: %v %q", v, cur)
	}
	if _, cur, _ := ParseJournalLine([]byte(`{"__CURSOR":"--evil cursor","__REALTIME_TIMESTAMP":"1791720000000000","MESSAGE":"x"}`)); cur != "" {
		t.Errorf("an unsafe cursor is dropped: %q", cur)
	}
	long := jline(map[string]any{"__REALTIME_TIMESTAMP": "1791720000000000", "MESSAGE": strings.Repeat("é", 5000), "_SYSTEMD_UNIT": strings.Repeat("u", 900)})
	ev, _, _ = ParseJournalLine([]byte(long))
	if len(ev.Source) > 200 {
		t.Errorf("source %d bytes", len(ev.Source))
	}
}

func TestParseJournalOutputFiltersAndTracksTheLastRecord(t *testing.T) {
	var b strings.Builder
	for i, msg := range []string{"Accepted publickey for bob", "Failed password for root", "FAILED PASSWORD for admin", "cron: job done"} {
		b.WriteString(jline(map[string]any{"__CURSOR": fmt.Sprintf("c%d", i+1), "__REALTIME_TIMESTAMP": fmt.Sprintf("%d", 1791720000000000+i), "MESSAGE": msg, "PRIORITY": "4"}))
		b.WriteString("\n")
	}
	res := ParseJournalOutput([]byte(b.String()), false, []string{"failed password"})
	if len(res.Items) != 2 || res.LastPos != "c4" {
		t.Fatalf("%d items, last %q", len(res.Items), res.LastPos)
	}
	if res.Items[0].Pos != "c2" || res.Items[1].Pos != "c3" {
		t.Errorf("positions %q %q", res.Items[0].Pos, res.Items[1].Pos)
	}
	// a truncated read drops the cut-off line
	out := b.String()
	out = out[:len(out)-20]
	res = ParseJournalOutput([]byte(out), true, nil)
	if len(res.Items) != 3 || res.LastPos != "c3" {
		t.Errorf("truncated: %d items, last %q", len(res.Items), res.LastPos)
	}
	if res := ParseJournalOutput([]byte("garbage with no newline"), true, nil); len(res.Items) != 0 || res.LastPos != "" {
		t.Errorf("%+v", res)
	}
}

type fakeRun struct {
	calls [][]string
	res   remote.RunResult
	err   error
}

func (f *fakeRun) Run(_ context.Context, name string, args []string, _ int, _ time.Duration) (remote.RunResult, error) {
	f.calls = append(f.calls, append([]string{name}, args...))
	return f.res, f.err
}

func TestJournalReaderRunsJournalctlAndReportsErrors(t *testing.T) {
	line := jline(map[string]any{"__CURSOR": "c9", "__REALTIME_TIMESTAMP": "1791720000000000", "MESSAGE": "Failed password", "PRIORITY": "3"})
	fr := &fakeRun{res: remote.RunResult{Stdout: []byte(line + "\n")}}
	jr := JournalReader{R: fr, Now: func() time.Time { return t0 }}
	res, err := jr.Read(context.Background(), ReadRequest{Src: api.LogSource{Units: []string{"ssh.service"}}, From: "c1", Limit: 100})
	if err != nil || len(res.Items) != 1 || res.LastPos != "c9" {
		t.Fatalf("%+v %v", res, err)
	}
	if fr.calls[0][0] != "journalctl" || fr.calls[0][len(fr.calls[0])-1] != "_SYSTEMD_UNIT=ssh.service" {
		t.Errorf("%v", fr.calls[0])
	}
	fr = &fakeRun{res: remote.RunResult{ExitCode: 1, Stderr: []byte("Failed to open journal\nmore")}}
	if _, err := (JournalReader{R: fr}).Read(context.Background(), ReadRequest{Src: api.LogSource{}, Limit: 10}); err == nil || !strings.Contains(err.Error(), "exited 1") || strings.Contains(err.Error(), "more") {
		t.Errorf("%v", err)
	}
	fr = &fakeRun{err: errors.New("exec: journalctl: not found")}
	if _, err := (JournalReader{R: fr}).Read(context.Background(), ReadRequest{Src: api.LogSource{}, Limit: 10}); err == nil {
		t.Error("a missing journalctl is an error the manager logs once and retries")
	}
	fr = &fakeRun{}
	if _, err := (JournalReader{R: fr}).Read(context.Background(), ReadRequest{Src: api.LogSource{Units: []string{"-x"}}, Limit: 10}); err == nil || len(fr.calls) != 0 {
		t.Errorf("a hostile unit must not reach the runner: %v %v", err, fr.calls)
	}
}

// ---- Windows event log (pure parts; the reader is exercised through a fake runner) ----

func TestEventLogXPathIsBuiltFromIntegersOnly(t *testing.T) {
	q, err := EventLogXPath([]string{"error", "critical", "error"}, []int{7040, 7036, 7036}, 1234)
	if err != nil || q != "*[System[(Level=1 or Level=2) and EventRecordID>1234 and (EventID=7036 or EventID=7040)]]" {
		t.Errorf("%q %v", q, err)
	}
	q, _ = EventLogXPath(nil, nil, 0)
	if q != "*[System[EventRecordID>0]]" {
		t.Errorf("%q", q)
	}
	for _, c := range []struct {
		lv  []string
		ids []int
		id  int64
	}{{[]string{"error)] or 1=1 or [System[(Level=1"}, nil, 0}, {[]string{"loud"}, nil, 0}, {nil, []int{-1}, 0}, {nil, []int{65536}, 0}, {nil, make([]int, 51), 0}, {nil, nil, -1}} {
		if _, err := EventLogXPath(c.lv, c.ids, c.id); err == nil {
			t.Errorf("%+v accepted", c)
		}
	}
}

func TestEventLogArgv(t *testing.T) {
	argv, err := EventLogArgv(api.LogSource{Channel: "System", Levels: []string{"error"}}, 100, 5)
	want := []string{"qe", "System", "/f:xml", "/rd:false", "/c:100", "/q:*[System[(Level=2) and EventRecordID>5]]"}
	if err != nil || strings.Join(argv, "\x00") != strings.Join(want, "\x00") {
		t.Errorf("%q %v", argv, err)
	}
	for _, ch := range []string{"", "-x", "/q:*", "System;calc", "Sys\ntem", "a|b", "a&b", strings.Repeat("a", 101), "Sys\"tem", "$(x)", "Sys tem>"} {
		if _, err := EventLogArgv(api.LogSource{Channel: ch}, 10, 0); err == nil {
			t.Errorf("channel %q accepted", ch)
		}
		if _, err := EventLogNewestArgv(ch); err == nil {
			t.Errorf("newest: channel %q accepted", ch)
		}
	}
	for _, ch := range []string{"System", "Application", "Security", "Microsoft-Windows-PowerShell/Operational", "Windows PowerShell", "Setup"} {
		if !ValidChannel(ch) {
			t.Errorf("%q should be valid", ch)
		}
	}
	nw, _ := EventLogNewestArgv("System")
	if strings.Join(nw, " ") != "qe System /f:xml /rd:true /c:1" {
		t.Errorf("%v", nw)
	}
}

const eventXML = `<Event xmlns="http://schemas.microsoft.com/win/2004/08/events/event"><System><Provider Name="Service Control Manager" Guid="{555908d1}" EventSourceName="Service Control Manager"/><EventID Qualifiers="16384">7036</EventID><Level>4</Level><TimeCreated SystemTime="2026-10-11T12:00:01.2345678Z"/><EventRecordID>1001</EventRecordID><Channel>System</Channel></System><EventData><Data Name="param1">Print Spooler</Data><Data Name="param2">stopped</Data></EventData></Event>
<Event xmlns="http://schemas.microsoft.com/win/2004/08/events/event"><System><Provider Name="Microsoft-Windows-Kernel-Power"/><EventID>41</EventID><Level>1</Level><TimeCreated SystemTime="2026-10-11T12:00:02Z"/><EventRecordID>1002</EventRecordID></System><EventData/></Event>
<Event xmlns="http://schemas.microsoft.com/win/2004/08/events/event"><System><Provider Name="Bad"/><EventID>1</EventID><Level>2</Level><TimeCreated SystemTime="not a time"/><EventRecordID>1003</EventRecordID></System></Event>
<Event xmlns="http://schemas.microsoft.com/win/2004/08/events/event"><System><Provider Name="Svc"/><EventID>7000</EventID><Level>2</Level><TimeCreated SystemTime="2026-10-11T12:00:04Z"/><EventRecordID>1004</EventRecordID></System><UserData><Anything><Detail>failed to start &lt;x&gt; with</Detail></Anything></UserData></Event>`

func TestParseEventLogXML(t *testing.T) {
	recs, err := ParseEventLogXML([]byte(eventXML))
	if err != nil {
		t.Fatal(err)
	}
	if len(recs) != 3 {
		t.Fatalf("%d records (the one with an invalid time is skipped)", len(recs))
	}
	a := recs[0]
	if a.RecordID != 1001 || a.Event.Level != "info" || a.Event.Source != "Service Control Manager" || a.Event.EventID == nil || *a.Event.EventID != 7036 || a.Event.Message != "Print Spooler | stopped" || a.Event.TS != "2026-10-11T12:00:01Z" {
		t.Errorf("%+v", a)
	}
	if recs[1].Event.Level != "critical" || recs[1].Event.Message != "event 41" {
		t.Errorf("%+v", recs[1])
	}
	if recs[2].RecordID != 1004 || !strings.Contains(recs[2].Event.Message, "failed to start") {
		t.Errorf("%+v", recs[2])
	}
	if recs, err := ParseEventLogXML(nil); err != nil || len(recs) != 0 {
		t.Errorf("%v %v", recs, err)
	}
	// a cut-off tail keeps what parsed
	cut := eventXML[:strings.Index(eventXML, "<Event xmlns=\"http://schemas.microsoft.com/win/2004/08/events/event\"><System><Provider Name=\"Svc\"")+60]
	if recs, _ := ParseEventLogXML([]byte(cut)); len(recs) != 2 {
		t.Errorf("cut tail kept %d", len(recs))
	}
}

type seqRun struct {
	outs []remote.RunResult
	i    int
	args [][]string
}

func (s *seqRun) Run(_ context.Context, _ string, args []string, _ int, _ time.Duration) (remote.RunResult, error) {
	s.args = append(s.args, args)
	r := s.outs[min(s.i, len(s.outs)-1)]
	s.i++
	return r, nil
}

func TestEventLogReaderBaselineThenIncremental(t *testing.T) {
	sr := &seqRun{outs: []remote.RunResult{{Stdout: []byte(eventXML)}, {Stdout: []byte(eventXML)}}}
	er := EventLogReader{R: sr}
	src := api.LogSource{Kind: "eventlog", Channel: "System", Levels: []string{"critical", "error", "info"}, Contains: []string{"spooler"}}
	// first run: the newest record only, no events delivered
	res, err := er.Read(context.Background(), ReadRequest{Src: src})
	if err != nil || len(res.Items) != 0 || res.LastPos != "1004" {
		t.Fatalf("baseline: %+v %v", res, err)
	}
	if strings.Join(sr.args[0], " ") != "qe System /f:xml /rd:true /c:1" {
		t.Errorf("%v", sr.args[0])
	}
	res, err = er.Read(context.Background(), ReadRequest{Src: src, From: "1000", Limit: 50})
	if err != nil || res.LastPos != "1004" || len(res.Items) != 1 || res.Items[0].Pos != "1001" {
		t.Fatalf("incremental: %+v %v", res, err)
	}
	if !strings.Contains(strings.Join(sr.args[1], " "), "EventRecordID>1000") {
		t.Errorf("%v", sr.args[1])
	}
	// records at or below the bookmark are ignored even if the tool returns them
	res, _ = er.Read(context.Background(), ReadRequest{Src: src, From: "1002", Limit: 50})
	if len(res.Items) != 0 || res.LastPos != "1004" {
		t.Errorf("%+v", res)
	}
	for _, from := range []string{"-5", "abc", "1;2"} {
		if _, err := er.Read(context.Background(), ReadRequest{Src: src, From: from}); err == nil {
			t.Errorf("bookmark %q accepted", from)
		}
	}
}

// ---- helpers ----

func TestCleanTruncateContains(t *testing.T) {
	if got := CleanMessage("a\nb\tc\x00d\x1be\u0085f\xffg  "); strings.ContainsAny(got, "\n\t\x00\x1b\u0085") || !strings.HasPrefix(got, "a b cde") {
		t.Errorf("%q", got)
	}
	if got := TruncateUTF8("héllo", 2); got != "h" {
		t.Errorf("%q", got)
	}
	if got := TruncateUTF8("abc", 10); got != "abc" {
		t.Errorf("%q", got)
	}
	if !MatchesContains("A Failed Password", []string{"failed password"}) || MatchesContains("ok", []string{"failed"}) || !MatchesContains("anything", nil) || MatchesContains("x", []string{""}) {
		t.Error("MatchesContains")
	}
}

func TestNormalizeClampsTheServerConfig(t *testing.T) {
	if Normalize(nil) != nil {
		t.Fatal("nil in, nil out")
	}
	c := Normalize(&api.LogsConfig{Version: "v1", IntervalS: 1, BatchMax: 5000, MaxMessageBytes: 99999, RatePerMin: 999999})
	if c.Interval != MinInterval || c.BatchMax != MaxBatch || c.MaxMessageBytes != MaxMaxMessage || c.RatePerMin != MaxRatePerMin {
		t.Errorf("%+v", c)
	}
	c = Normalize(&api.LogsConfig{Version: "v1", IntervalS: 999999, BatchMax: -1, MaxMessageBytes: 1})
	if c.Interval != MaxInterval || c.BatchMax != MaxBatch || c.MaxMessageBytes != MinMaxMessage || c.RatePerMin != DefaultRatePerMin {
		t.Errorf("%+v", c)
	}
	many := make([]api.LogSource, 100)
	if got := Normalize(&api.LogsConfig{Sources: many}); len(got.Sources) != MaxSources {
		t.Errorf("%d sources", len(got.Sources))
	}
	if cfg := api.ParseLogsConfig(json.RawMessage(`{"version":"v","interval_s":"sixty"}`)); cfg != nil {
		t.Error("a type mismatch means no collection, not an error")
	}
	for _, raw := range []string{``, `null`, `[]`, `"x"`, `5`} {
		if api.ParseLogsConfig(json.RawMessage(raw)) != nil {
			t.Errorf("%q parsed", raw)
		}
	}
	if k1, k2 := SourceKey(api.LogSource{Kind: "journal", Units: []string{"a"}}), SourceKey(api.LogSource{Kind: "journal", Units: []string{"b"}}); k1 == k2 || !strings.HasPrefix(k1, "journal:") {
		t.Errorf("%q %q", k1, k2)
	}
}

// ---- manager ----

type fakeReader struct {
	mu    sync.Mutex
	items []Item
	last  string
	err   error
	froms []string
}

func (f *fakeReader) Read(_ context.Context, req ReadRequest) (ReadResult, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.froms = append(f.froms, req.From)
	if f.err != nil {
		return ReadResult{}, f.err
	}
	var out []Item
	for _, it := range f.items {
		if it.Pos > req.From {
			out = append(out, it)
		}
	}
	if len(out) > req.Limit {
		out = out[:req.Limit]
	}
	last := req.From
	if n := len(out); n > 0 {
		last = out[n-1].Pos
	} else if f.last != "" && f.last > last {
		last = f.last
	}
	return ReadResult{Items: out, LastPos: last}, nil
}

func items(n int, from int) []Item {
	var out []Item
	for i := from; i < from+n; i++ {
		out = append(out, Item{Pos: fmt.Sprintf("%06d", i), Event: api.LogEvent{TS: "2026-10-11T12:00:00Z", Level: "error", Source: "u.service", Message: fmt.Sprintf("failed password %d password=hunter2", i)}})
	}
	return out
}

type sink struct {
	mu      sync.Mutex
	bodies  []api.LogBatch
	err     error
	errOnce error
}

func (s *sink) send(_ context.Context, body []byte) (*api.LogAck, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.errOnce != nil {
		e := s.errOnce
		s.errOnce = nil
		return nil, e
	}
	if s.err != nil {
		return nil, s.err
	}
	var b api.LogBatch
	if err := json.Unmarshal(body, &b); err != nil {
		return nil, err
	}
	s.bodies = append(s.bodies, b)
	return &api.LogAck{OK: true, Accepted: len(b.Events)}, nil
}

func (s *sink) total() int {
	n := 0
	for _, b := range s.bodies {
		n += len(b.Events)
	}
	return n
}

func newMgr(t *testing.T, rd Reader, sk *sink, clock *time.Time) (*Manager, string) {
	t.Helper()
	state := filepath.Join(t.TempDir(), "logs_state.json")
	m := New(Options{Readers: map[string]Reader{"journal": rd}, Send: sk.send, StatePath: state, Now: func() time.Time { return *clock },
		Redact: func(s string) string { return strings.ReplaceAll(s, "hunter2", "[REDACTED]") }, Log: slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError}))})
	return m, state
}

func cfgOf(rate int) *Config {
	return Normalize(&api.LogsConfig{Version: "v1", IntervalS: 60, BatchMax: 200, MaxMessageBytes: 1024, RatePerMin: rate, Sources: []api.LogSource{{Kind: "journal", Units: []string{"u.service"}}}})
}

func TestManagerShipsRedactsAndCommitsOnlyAfterTheAcknowledgement(t *testing.T) {
	clock := t0
	rd := &fakeReader{items: items(5, 1)}
	sk := &sink{errOnce: &api.APIError{Status: 503, Code: "unavailable"}}
	m, state := newMgr(t, rd, sk, &clock)
	m.Configure(cfgOf(600))
	d := m.Tick(context.Background())
	if sk.total() != 0 || d <= 0 {
		t.Fatalf("a failed send delivered %d and waits %v", sk.total(), d)
	}
	if b, _ := os.ReadFile(state); strings.Contains(string(b), "000005") {
		t.Fatalf("the bookmark moved before the server acknowledged: %s", b)
	}
	clock = clock.Add(2 * time.Minute)
	m.Tick(context.Background())
	if sk.total() != 5 || len(sk.bodies) != 1 {
		t.Fatalf("delivered %d in %d batches", sk.total(), len(sk.bodies))
	}
	if sk.bodies[0].ConfigVersion != "v1" || sk.bodies[0].Dropped != 0 {
		t.Errorf("%+v", sk.bodies[0])
	}
	for _, e := range sk.bodies[0].Events {
		if strings.Contains(e.Message, "hunter2") {
			t.Error("credential-shaped text was shipped")
		}
	}
	if b, _ := os.ReadFile(state); !strings.Contains(string(b), "000005") {
		t.Errorf("bookmark not persisted after the ack: %s", b)
	}
	// a restart resumes from the persisted bookmark and does not send the five again
	clock = clock.Add(2 * time.Minute)
	rd2 := &fakeReader{items: items(7, 1)}
	sk2 := &sink{}
	m2 := New(Options{Readers: map[string]Reader{"journal": rd2}, Send: sk2.send, StatePath: state, Now: func() time.Time { return clock }})
	m2.Configure(cfgOf(600))
	m2.Tick(context.Background())
	if sk2.total() != 2 || rd2.froms[0] != "000005" {
		t.Errorf("after a restart: %d events, first read from %q", sk2.total(), rd2.froms)
	}
}

func TestManagerBatchesAndTheRateBudgetDropsAndCounts(t *testing.T) {
	clock := t0
	rd := &fakeReader{items: items(450, 1)}
	sk := &sink{}
	m, _ := newMgr(t, rd, sk, &clock)
	m.Configure(cfgOf(250))
	m.Tick(context.Background())
	if sk.total() != 250 {
		t.Fatalf("shipped %d against a budget of 250 per minute", sk.total())
	}
	for _, b := range sk.bodies {
		if len(b.Events) > 200 {
			t.Errorf("a batch of %d", len(b.Events))
		}
	}
	// the 200 beyond the budget were dropped and are reported with the next request
	clock = clock.Add(61 * time.Second)
	rd.items = append(rd.items, items(3, 451)...)
	m.Tick(context.Background())
	var dropped int
	for _, b := range sk.bodies {
		dropped += b.Dropped
	}
	if dropped != 200 || sk.total() != 253 {
		t.Errorf("dropped %d (want 200), total %d (want 253)", dropped, sk.total())
	}
}

func TestManagerStopsWhenTheConfigGoesAndKeepsItsBookmark(t *testing.T) {
	clock := t0
	rd := &fakeReader{items: items(3, 1)}
	sk := &sink{}
	m, state := newMgr(t, rd, sk, &clock)
	m.Configure(cfgOf(600))
	m.Tick(context.Background())
	reads := len(rd.froms)
	m.Configure(nil)
	if d := m.Tick(context.Background()); d < time.Minute || len(rd.froms) != reads {
		t.Errorf("nothing is read once the config is gone (waits %v, reads %d -> %d)", d, reads, len(rd.froms))
	}
	if m.Active() {
		t.Error("still active")
	}
	if b, _ := os.ReadFile(state); !strings.Contains(string(b), "000003") {
		t.Errorf("the bookmark must survive: %s", b)
	}
	// back on: continues after the bookmark
	rd.items = items(5, 1)
	m.Configure(cfgOf(600))
	m.Tick(context.Background())
	if sk.total() != 5 {
		t.Errorf("total %d", sk.total())
	}
}

func TestManagerHandlesServerAnswers(t *testing.T) {
	clock := t0
	// feature disabled: pause at least 15 minutes, keep the events
	rd := &fakeReader{items: items(2, 1)}
	sk := &sink{err: &api.APIError{Status: 503, Code: "feature_disabled", RetryAfterRaw: time.Hour}}
	m, _ := newMgr(t, rd, sk, &clock)
	m.Configure(cfgOf(600))
	if d := m.Tick(context.Background()); d < 15*time.Minute {
		t.Errorf("waits %v after feature_disabled", d)
	}
	reads := len(rd.froms)
	clock = clock.Add(5 * time.Minute)
	m.Tick(context.Background())
	if len(rd.froms) != reads {
		t.Error("still paused after 5 minutes")
	}
	// a rejected batch (422) is dropped so the loop cannot wedge
	sk2 := &sink{err: &api.APIError{Status: 422, Code: "invalid"}}
	clock = t0
	m2, _ := newMgr(t, &fakeReader{items: items(2, 1)}, sk2, &clock)
	m2.Configure(cfgOf(600))
	m2.Tick(context.Background())
	if m2.dropped == 0 && len(m2.backlog) != 0 {
		t.Errorf("rejected batch kept: backlog %d", len(m2.backlog))
	}
	// revoked: the callback fires
	revoked := false
	sk3 := &sink{err: &api.APIError{Status: 401, Code: "revoked"}}
	m3 := New(Options{Readers: map[string]Reader{"journal": &fakeReader{items: items(1, 1)}}, Send: sk3.send, Now: func() time.Time { return clock }, OnRevoked: func(error) { revoked = true }})
	m3.Configure(cfgOf(600))
	m3.Tick(context.Background())
	if !revoked {
		t.Error("OnRevoked not called")
	}
}

func TestManagerRecoversFromAnUnusableBookmark(t *testing.T) {
	clock := t0
	rd := &fakeReader{err: errors.New("journalctl exited 1: Failed to seek to cursor")}
	sk := &sink{}
	m, state := newMgr(t, rd, sk, &clock)
	os.WriteFile(state, []byte(`{"v":1,"marks":{"`+SourceKey(cfgOf(600).Sources[0])+`":"stale-cursor"}}`), 0o600)
	m = New(Options{Readers: map[string]Reader{"journal": rd}, Send: sk.send, StatePath: state, Now: func() time.Time { return clock }})
	m.Configure(cfgOf(600))
	for i := 0; i < maxFailBeforeReset+1; i++ {
		m.Tick(context.Background())
	}
	if b, _ := os.ReadFile(state); strings.Contains(string(b), "stale-cursor") {
		t.Errorf("an unusable cursor must be dropped after %d failures: %s", maxFailBeforeReset, b)
	}
}

func TestEveryConfiguredSourceKindWithoutAReaderIsSkipped(t *testing.T) {
	clock := t0
	sk := &sink{}
	m, _ := newMgr(t, &fakeReader{items: items(1, 1)}, sk, &clock)
	c := cfgOf(600)
	c.Sources = append(c.Sources, api.LogSource{Kind: "eventlog", Channel: "System"}, api.LogSource{Kind: "nonsense"})
	m.Configure(c)
	m.Tick(context.Background())
	if sk.total() != 1 {
		t.Errorf("total %d", sk.total())
	}
}
