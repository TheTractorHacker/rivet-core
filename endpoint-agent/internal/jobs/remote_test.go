//go:build !windows

package jobs

import (
	"context"
	"encoding/json"
	"strings"
	"testing"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/remote"
)

func remoteJob(id, typ string, params map[string]any) map[string]any {
	j := baseJob(id, "")
	j["type"] = typ
	j["script"] = ""
	j["params"] = params
	return j
}

func lastReport(rep *fakeReporter) api.JobReport { return rep.reports[len(rep.reports)-1] }

func TestRemoteJobsAreVerifiedRunAndReportedAsJSON(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	ran := 0
	e.Remote = map[string]remote.Handler{
		TypeSvcList: func(_ context.Context, p json.RawMessage, maxOut int) remote.Result {
			ran++
			return remote.Result{OK: true, Output: `{"v":1,"count":0,"services":[],"note":"password=hunter2 for bob"}`}
		},
		TypeProcKill: func(_ context.Context, p json.RawMessage, maxOut int) remote.Result {
			ran++
			return remote.Result{OK: false, Detail: "name_mismatch", Output: `{"v":1,"ok":false,"detail":"name_mismatch"}`}
		},
		TypeSvcControl: func(_ context.Context, p json.RawMessage, maxOut int) remote.Result {
			ran++
			return remote.Result{OK: false, Detail: "bad_params", Cannot: true, Output: `{"v":1,"ok":false,"detail":"bad_params"}`}
		},
		TypeFileGet: func(_ context.Context, p json.RawMessage, maxOut int) remote.Result {
			ran++
			return remote.Result{OK: true, Output: strings.Repeat("x", maxOut+1)}
		},
	}
	start(t, e)
	e.Submit([]api.Job{k.signJob(t, remoteJob("l1", TypeSvcList, map[string]any{}))})
	waitIdle(t, e)
	r := lastReport(rep)
	if r.State != "succeeded" || r.ExitCode == nil || *r.ExitCode != 0 {
		t.Fatalf("%+v", r)
	}
	var m map[string]any
	if err := json.Unmarshal([]byte(r.Output), &m); err != nil {
		t.Fatalf("the reported output must stay valid JSON after redaction: %v: %q", err, r.Output)
	}
	if strings.Contains(r.Output, "hunter2") {
		t.Errorf("the output was not redacted: %q", r.Output)
	}

	e.Submit([]api.Job{k.signJob(t, remoteJob("k1", TypeProcKill, map[string]any{"pid": 5, "name": "x"}))})
	waitIdle(t, e)
	if r := lastReport(rep); r.State != "failed" || r.ExitCode == nil || *r.ExitCode != 1 || !strings.Contains(r.Output, "name_mismatch") {
		t.Fatalf("%+v", r)
	}
	e.Submit([]api.Job{k.signJob(t, remoteJob("c1", TypeSvcControl, map[string]any{}))})
	waitIdle(t, e)
	if r := lastReport(rep); r.State != "failed" || r.ExitCode != nil {
		t.Fatalf("a job that never started has no exit code: %+v", r)
	}
	e.Submit([]api.Job{k.signJob(t, remoteJob("g1", TypeFileGet, map[string]any{}))})
	waitIdle(t, e)
	if r := lastReport(rep); !strings.Contains(r.Output, "output_too_large") || len(r.Output) > 4096 {
		t.Fatalf("an output over the cap is replaced, never cut: %q", r.Output)
	}
	if ran != 4 {
		t.Errorf("handlers ran %d times", ran)
	}
}

func TestRemoteJobsHonourTheLocalKillSwitchAndMissingHandlers(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	called := false
	e.Remote = map[string]remote.Handler{TypeSvcList: func(context.Context, json.RawMessage, int) remote.Result {
		called = true
		return remote.Result{OK: true, Output: "{}"}
	}}
	e.DisableRemote = true
	start(t, e)
	e.Submit([]api.Job{k.signJob(t, remoteJob("d1", TypeSvcList, map[string]any{}))})
	waitIdle(t, e)
	if called {
		t.Fatal("a locally disabled agent ran a remote tool")
	}
	if r := lastReport(rep); r.State != "failed" || !strings.Contains(r.Output, "disabled_locally") {
		t.Fatalf("%+v", r)
	}
	e.DisableRemote = false
	e.Submit([]api.Job{k.signJob(t, remoteJob("d2", TypeProcList, map[string]any{}))})
	waitIdle(t, e)
	if r := lastReport(rep); r.State != "failed" || !strings.Contains(r.Output, "unsupported") {
		t.Fatalf("%+v", r)
	}
}

func TestAnUnverifiedRemoteJobNeverRuns(t *testing.T) {
	k, other := newKeys(t), newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	called := false
	e.Remote = map[string]remote.Handler{TypeProcKill: func(context.Context, json.RawMessage, int) remote.Result {
		called = true
		return remote.Result{OK: true, Output: "{}"}
	}}
	start(t, e)
	e.Submit([]api.Job{other.signJob(t, remoteJob("x1", TypeProcKill, map[string]any{"pid": 5, "name": "x"}))})
	tampered := k.signJob(t, remoteJob("x2", TypeProcKill, map[string]any{"pid": 5, "name": "x"}))
	tampered.Raw = []byte(strings.Replace(string(tampered.Raw), `"pid":5`, `"pid":6`, 1))
	e.Submit([]api.Job{tampered})
	waitIdle(t, e)
	if called || len(rep.reports) != 0 {
		t.Fatalf("an unverified remote job ran (%v) or was reported (%d)", called, len(rep.reports))
	}
}

func TestRedactJSONStringsKeepsTheDocumentValid(t *testing.T) {
	doc := `{"a":"password=secret1 ok","b":["Bearer abcdefghijklmnop","plain"],"c":"quote \" and \\ slash","n":5,"emoji":"café"}`
	out := RedactJSONStrings(doc, "literal-secret")
	var m map[string]any
	if err := json.Unmarshal([]byte(out), &m); err != nil {
		t.Fatalf("%v: %s", err, out)
	}
	if strings.Contains(out, "secret1") || strings.Contains(out, "abcdefghijklmnop") {
		t.Errorf("%s", out)
	}
	if m["c"] != `quote " and \ slash` || m["n"].(float64) != 5 {
		t.Errorf("untouched members changed: %v", m)
	}
	if got := RedactJSONStrings(`{"x":"tok literal-secret tok"}`, "literal-secret"); strings.Contains(got, "literal-secret") {
		t.Errorf("%s", got)
	}
	if got := RedactJSONStrings(doc[:20]); got == "" {
		t.Error("a truncated document is returned unchanged, not dropped")
	}
}

func TestReasonCode(t *testing.T) {
	for in, want := range map[string]string{"": "failed", "name_mismatch": "name_mismatch", "path_denied:deny_list": "path_denied", "systemctl_failed: exit 1": "systemctl_failed", strings.Repeat("a", 50): "failed"} {
		if got := reasonCode(in); got != want {
			t.Errorf("reasonCode(%q) = %q; want %q", in, got, want)
		}
	}
	for _, typ := range []string{TypeSvcList, TypeSvcControl, TypeProcList, TypeProcKill, TypeFileGet, TypeFilePut} {
		if !IsRemoteType(typ) || !SupportsType(typ) || len(typ) > 20 {
			t.Errorf("%s", typ)
		}
	}
	if IsRemoteType("shell") || IsRemoteType("") {
		t.Error("IsRemoteType")
	}
}

func TestPatchJobsFollowTheirOwnLocalSwitch(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	ran := 0
	h := func(_ context.Context, p json.RawMessage, _ int) remote.Result {
		ran++
		return remote.Result{OK: true, Output: `{"v":1,"ok":true,"updates":[]}`}
	}
	e.Remote = map[string]remote.Handler{TypePatchScan: h, TypePatchInstall: h, TypeSvcList: h}
	e.DisablePatching = true
	start(t, e)
	e.Submit([]api.Job{k.signJob(t, remoteJob("p1", TypePatchScan, map[string]any{}))})
	waitIdle(t, e)
	if r := lastReport(rep); ran != 0 || r.State != "failed" || !strings.Contains(r.Output, "disabled_locally") || r.ExitCode != nil {
		t.Fatalf("disable_patching must stop the job before it runs: ran=%d %+v", ran, r)
	}
	// disable_patching does not touch the remote tools, and disable_remote does not touch patching
	e.Submit([]api.Job{k.signJob(t, remoteJob("p2", TypeSvcList, map[string]any{}))})
	waitIdle(t, e)
	if r := lastReport(rep); ran != 1 || r.State != "succeeded" {
		t.Fatalf("%d %+v", ran, r)
	}
	e.DisablePatching, e.DisableRemote = false, true
	e.Submit([]api.Job{k.signJob(t, remoteJob("p3", TypePatchInstall, map[string]any{"ids": "openssl"}))})
	waitIdle(t, e)
	if r := lastReport(rep); ran != 2 || r.State != "succeeded" || r.ExitCode == nil || *r.ExitCode != 0 {
		t.Fatalf("%d %+v", ran, r)
	}
	// a patch job carries no script
	j := remoteJob("p4", TypePatchScan, map[string]any{})
	j["script"] = "echo hi"
	e.Submit([]api.Job{k.signJob(t, j)})
	waitIdle(t, e)
	if ran != 2 {
		t.Error("a signed patch job with a script must be rejected by the verifier")
	}
}
