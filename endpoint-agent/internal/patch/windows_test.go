package patch

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/jobs"
)

type psCall struct {
	script  string
	env     []string
	timeout time.Duration
}

func psEngine(res jobs.ExecResult, calls *[]psCall) Engine {
	return Engine{GOOS: "windows", Now: func() time.Time { return time.Date(2024, 5, 4, 12, 0, 0, 0, time.UTC) },
		PS: func(_ context.Context, script string, env []string, timeout time.Duration) jobs.ExecResult {
			if calls != nil {
				*calls = append(*calls, psCall{script, env, timeout})
			}
			return res
		}}
}

func ok(out string) jobs.ExecResult { return jobs.ExecResult{Output: out, HaveExit: true} }

func TestWindowsScanNormalisesScriptOutput(t *testing.T) {
	for _, file := range []string{"win_scan_ok.json", "win_clixml_prefix.txt"} {
		var calls []psCall
		e := psEngine(ok(fixture(t, file)), &calls)
		res := e.Scan(context.Background(), json.RawMessage(`{"categories":"security,driver","max_items":50}`), 1<<20)
		o := scanOf(t, res)
		if len(calls) != 1 || calls[0].script != ScanScript() {
			t.Fatalf("%s: the embedded scan.ps1 runs verbatim", file)
		}
		if got := strings.Join(calls[0].env, "|"); got != "RIVETIT_PATCH_CATEGORIES=security,driver|RIVETIT_PATCH_MAX_ITEMS=50" {
			t.Errorf("env %q", got)
		}
		if o.Manager != "wua" || o.WUState != "stopped" || !o.RebootPending || o.ScanState != "ok" || o.LastInstallAt == nil || *o.LastInstallAt != "2024-04-10T03:11:00Z" {
			t.Errorf("%s: %+v", file, o)
		}
		// BAD-ID is dropped; security first, then by title
		if got := strings.Join(ids(o.Updates), ","); got != "11111111-2222-3333-4444-555555555555,99999999-8888-7777-6666-555555555555,aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee" {
			t.Errorf("%s: order %s", file, got)
		}
		drv := o.Updates[1]
		if drv.Title != "Intel - System - 10.1.1.45 é" || drv.EulaAccepted || drv.ReleasedAt != nil || drv.Kind != "driver" {
			t.Errorf("%+v", drv)
		}
		if o.Updates[0].KB != "KB5036893" || o.Updates[0].Size != 412345678 || !o.Updates[0].RebootRequired || o.Updates[0].Severity != "critical" {
			t.Errorf("%+v", o.Updates[0])
		}
	}
	// the script is the unit under test: it runs standalone with no environment, so a scan without params passes the defaults
	var calls []psCall
	psEngine(ok(fixture(t, "win_scan_ok.json")), &calls).Scan(context.Background(), nil, 1<<20)
	if got := strings.Join(calls[0].env, "|"); got != "RIVETIT_PATCH_CATEGORIES=|RIVETIT_PATCH_MAX_ITEMS=500" {
		t.Errorf("%q", got)
	}
}

func TestWindowsScanCapsAndHostileScriptOutput(t *testing.T) {
	var us []Update
	for i := 0; i < 30; i++ {
		us = append(us, Update{ID: "00000000-0000-0000-0000-0000000000" + string(rune('0'+i/10)) + string(rune('0'+i%10)), Title: strings.Repeat("T", 5000), Kind: "rm -rf", Severity: "apocalyptic",
			KB: "KB1;calc", Categories: make([]string, 40), ReleasedAt: ptr("yesterday"), Size: -5, Current: strings.Repeat("c", 999)})
	}
	doc, _ := json.Marshal(map[string]any{"v": 1, "ok": true, "manager": "evil", "scan_state": "who knows", "wu_state": "exploded", "scanned_at": "never", "last_install_at": "soon",
		"total": 30, "truncated": false, "updates": us})
	o := scanOf(t, psEngine(ok(string(doc)), nil).Scan(context.Background(), json.RawMessage(`{"max_items":10}`), 1<<20))
	if o.Manager != "wua" || o.ScanState != "ok" || o.WUState != "n/a" || o.LastInstallAt != nil || o.ScannedAt != "2024-05-04T12:00:00Z" {
		t.Errorf("%+v", o)
	}
	if len(o.Updates) != 10 || o.Total != 30 || !o.Truncated {
		t.Errorf("cap: %d %d %v", len(o.Updates), o.Total, o.Truncated)
	}
	u := o.Updates[0]
	if u.Kind != "other" || u.Severity != "unspecified" || u.KB != "" || len(u.Title) != 200 || len(u.Categories) != 20 || u.ReleasedAt != nil || u.Size != 0 || len(u.Current) != 100 {
		t.Errorf("%+v", u)
	}
	// the output cap drops entries from the end
	res := psEngine(ok(string(doc)), nil).Scan(context.Background(), json.RawMessage(`{"max_items":30}`), 3000)
	o = scanOf(t, res)
	if len(res.Output) > 3000 || !o.Truncated || len(o.Updates) >= 30 || o.Total != 30 {
		t.Errorf("len=%d n=%d", len(res.Output), len(o.Updates))
	}
}

func ptr(s string) *string { return &s }

func TestWindowsScanFailures(t *testing.T) {
	e := psEngine(ok(fixture(t, "win_scan_fail.json")), nil)
	res := e.Scan(context.Background(), nil, 1<<20)
	var m map[string]any
	_ = json.Unmarshal([]byte(res.Output), &m)
	if res.OK || m["detail"] != "wu_unavailable" || m["ok"] != false || !strings.Contains(m["message"].(string), "Class not registered") {
		t.Errorf("%+v", res)
	}
	for name, c := range map[string]struct {
		res    jobs.ExecResult
		detail string
	}{
		"garbage":       {ok("Access is denied\nnot json"), DetailParseError},
		"empty":         {ok(""), DetailParseError},
		"no ok member":  {ok(`{"v":1}`), DetailParseError},
		"bad detail":    {ok(`{"v":1,"ok":false,"detail":"x; rm -rf /"}`), DetailScanFailed},
		"timeout":       {jobs.ExecResult{TimedOut: true}, DetailTimeout},
		"start error":   {jobs.ExecResult{StartErr: errors.New("exec: powershell.exe not found")}, DetailWUUnavailable},
		"truncated doc": {ok(`{"v":1,"ok":true,"updates":[{"id":`), DetailParseError},
	} {
		res := psEngine(c.res, nil).Scan(context.Background(), nil, 1<<20)
		if res.OK || !strings.Contains(res.Output, `"detail":"`+c.detail+`"`) {
			t.Errorf("%s: %+v", name, res)
		}
	}
	// off Windows there is no PowerShell: the engine says so instead of guessing
	if res := (Engine{GOOS: "windows"}).Scan(context.Background(), nil, 1<<20); res.OK {
		t.Errorf("no runner: %+v", res)
	}
}

const (
	wid1 = "11111111-2222-3333-4444-555555555555"
	wid2 = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"
	wid3 = "bbbbbbbb-cccc-dddd-eeee-ffffffffffff"
)

func TestWindowsInstall(t *testing.T) {
	var calls []psCall
	e := psEngine(ok(fixture(t, "win_install_dry.json")), &calls)
	res := e.Install(context.Background(), json.RawMessage(`{"ids":"`+wid1+`,99999999-8888-7777-6666-555555555555"}`), 1<<20)
	o := installOf(t, res)
	if len(calls) != 1 || calls[0].script != InstallScript() {
		t.Fatal("install.ps1 runs verbatim")
	}
	if got := strings.Join(calls[0].env, "|"); got != "RIVETIT_PATCH_IDS="+wid1+",99999999-8888-7777-6666-555555555555|RIVETIT_PATCH_MODE=dry_run|RIVETIT_PATCH_MAX_MINUTES=60" {
		t.Errorf("env %q", got)
	}
	if calls[0].timeout != 10*time.Minute {
		t.Errorf("a dry run is bounded to minutes: %v", calls[0].timeout)
	}
	if res.OK || o.OK || o.Mode != "dry_run" || o.Results[0].Result != "would_install" || o.Results[1].Result != "eula_required" {
		t.Errorf("eula_required fails the job: %+v / %+v", res, o)
	}

	// a real run: needs_reboot is a success with the reboot flag; a missing id and a foreign id are handled; the verdict is Go's, not the script's
	calls = nil
	e = psEngine(ok(fixture(t, "win_install_done.json")), &calls)
	res = e.Install(context.Background(), json.RawMessage(`{"ids":"`+wid1+","+wid2+","+wid3+`","mode":"install","max_minutes":90}`), 1<<20)
	o = installOf(t, res)
	if len(o.Results) != 3 || o.Results[0].ID != wid1 || o.Results[1].ID != wid2 || o.Results[2].ID != wid3 {
		t.Fatalf("exactly the requested ids: %+v", o.Results)
	}
	if o.Results[0].Result != "needs_reboot" || !o.Results[0].RebootRequired || o.Results[1].Result != "failed" || o.Results[1].Code != "0x80070643" || o.Results[2].Result != "failed" || o.Results[2].Code != "no_result" {
		t.Errorf("%+v", o.Results)
	}
	if res.OK || o.OK || !o.RebootRequired || o.Mode != "install" || calls[0].timeout != 100*time.Minute {
		t.Errorf("%+v %v", o, calls[0].timeout)
	}
	if !strings.Contains(strings.Join(calls[0].env, "|"), "RIVETIT_PATCH_MODE=install|RIVETIT_PATCH_MAX_MINUTES=90") {
		t.Errorf("%v", calls[0].env)
	}

	// everything good = succeeded; a script that claims ok:true with a failed result does not get the job a pass
	good := `{"v":1,"ok":true,"mode":"install","manager":"wua","results":[{"id":"` + wid1 + `","title":"x","result":"installed","code":"0x00000000","detail":"","reboot_required":false},{"id":"` + wid2 + `","title":"y","result":"not_applicable","code":"","detail":"gone","reboot_required":false}],"reboot_required":false}`
	res = psEngine(ok(good), nil).Install(context.Background(), json.RawMessage(`{"ids":"`+wid1+","+wid2+`","mode":"install"}`), 1<<20)
	if !res.OK || !installOf(t, res).OK {
		t.Errorf("%+v", res)
	}
	liar := strings.Replace(good, `"result":"installed"`, `"result":"failed"`, 1)
	if res = psEngine(ok(liar), nil).Install(context.Background(), json.RawMessage(`{"ids":"`+wid1+","+wid2+`","mode":"install"}`), 1<<20); res.OK {
		t.Error("the verdict is computed from the results")
	}
	weird := strings.Replace(good, `"result":"installed"`, `"result":"rm -rf"`, 1)
	if o = installOf(t, psEngine(ok(weird), nil).Install(context.Background(), json.RawMessage(`{"ids":"`+wid1+","+wid2+`"}`), 1<<20)); o.Results[0].Result != "failed" || o.Results[0].Code != "bad_result" {
		t.Errorf("%+v", o.Results[0])
	}
}

func TestWindowsInstallRefusesBadParamsAndHandlesFailures(t *testing.T) {
	var calls []psCall
	e := psEngine(ok("{}"), &calls)
	for _, p := range []string{`{"ids":"openssl","mode":"install"}`, `{"ids":"` + strings.ToUpper(wid2) + `"}`, `{"ids":"{` + wid1 + `}"}`, `{"ids":"` + wid1 + `;calc"}`, `{"ids":"` + wid1 + `","mode":"x"}`, `{}`} {
		if res := e.Install(context.Background(), json.RawMessage(p), 1<<20); !res.Cannot {
			t.Errorf("%s: %+v", p, res)
		}
	}
	if len(calls) != 0 {
		t.Fatal("PowerShell must not start for invalid parameters")
	}
	res := psEngine(ok(fixture(t, "win_scan_fail.json")), nil).Install(context.Background(), json.RawMessage(`{"ids":"`+wid1+`","mode":"install"}`), 1<<20)
	if res.OK || !strings.Contains(res.Output, "wu_unavailable") {
		t.Errorf("%+v", res)
	}
	res = psEngine(jobs.ExecResult{TimedOut: true}, nil).Install(context.Background(), json.RawMessage(`{"ids":"`+wid1+`"}`), 1<<20)
	if res.OK || !strings.Contains(res.Output, DetailTimeout) {
		t.Errorf("%+v", res)
	}
}

func TestExtractJSON(t *testing.T) {
	for name, c := range map[string]struct {
		in   string
		want string
		ok   bool
	}{
		"plain":       {`{"a":1}`, `{"a":1}`, true},
		"crlf":        {"noise\r\n{\"a\":1}\r\n", `{"a":1}`, true},
		"bom":         {"\xef\xbb\xbf{\"a\":1}\n", `{"a":1}`, true},
		"last wins":   {"{\"a\":1}\n{\"a\":2}\n", `{"a":2}`, true},
		"trailing":    {"{\"a\":1}\nWARNING: x\n", `{"a":1}`, true},
		"not json":    {"{oops}\n", "", false},
		"empty":       {"", "", false},
		"clixml only": {"#< CLIXML\n<Objs Version=\"1.1.0.1\" xmlns=\"http://schemas.microsoft.com/powershell/2004/04\"><S S=\"Error\">boom</S></Objs>", "", false},
	} {
		got, ok := extractJSON(c.in)
		if ok != c.ok || string(got) != c.want {
			t.Errorf("%s: %q %v", name, got, ok)
		}
	}
}

func TestJobTypeNamesMatchTheJobsPackage(t *testing.T) {
	if JobScan != jobs.TypePatchScan || JobInstall != jobs.TypePatchInstall {
		t.Fatal("patch and jobs disagree on the wire names")
	}
	h := (Engine{}).Handlers()
	if h[JobScan] == nil || h[JobInstall] == nil || len(h) != 2 {
		t.Fatal("handlers")
	}
}
