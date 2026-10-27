package patch

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/remote"
)

// Windows side. This file has no build tag: everything that is not "start powershell.exe" is pure and tested on Linux with captured outputs.
//
// The scripts print ONE JSON line. Go does not forward it: it decodes it, validates every field against the contract (allow-lists, lengths, id
// syntax), re-sorts, applies the caps and encodes again, so a script bug or a polluted stream cannot put anything unexpected on the wire.

const (
	psMaxOutput    = 4 << 20 // the script caps itself at 1000 items; this is only a safety net
	psDefaultTime  = 10 * time.Minute
	psInstallExtra = 10 * time.Minute // budget on top of max_minutes: download of the last update, final registry reads
)

// utf8BOM is the byte order mark some hosts put in front of redirected output.
const utf8BOM = "\xef\xbb\xbf"

var detailCodeRe = regexp.MustCompile(`^[a-z][a-z0-9_]{0,39}$`)

func (e Engine) scanWindows(ctx context.Context, p ScanParams, maxOut int) remote.Result {
	run := e.ps()
	if run == nil {
		return remote.Cannot(DetailUnsupported)
	}
	env := []string{
		"RIVETIT_PATCH_CATEGORIES=" + strings.Join(p.Categories, ","),
		"RIVETIT_PATCH_MAX_ITEMS=" + strconv.Itoa(p.MaxItems),
	}
	res := run(ctx, ScanScript(), env, timeoutFor(ctx, psDefaultTime))
	return e.normaliseScan(res, p, maxOut)
}

func (e Engine) installWindows(ctx context.Context, p InstallParams, maxOut int) remote.Result {
	run := e.ps()
	if run == nil {
		return remote.Cannot(DetailUnsupported)
	}
	env := []string{
		"RIVETIT_PATCH_IDS=" + strings.Join(p.IDs, ","),
		"RIVETIT_PATCH_MODE=" + p.Mode,
		"RIVETIT_PATCH_MAX_MINUTES=" + strconv.Itoa(p.MaxMinutes),
	}
	d := psDefaultTime
	if p.Mode == "install" {
		d = time.Duration(p.MaxMinutes)*time.Minute + psInstallExtra
	}
	res := run(ctx, InstallScript(), env, timeoutFor(ctx, d))
	return e.normaliseInstall(res, p, maxOut)
}

// timeoutFor is the lifetime of the child: the budget, but never past the job's own deadline.
func timeoutFor(ctx context.Context, budget time.Duration) time.Duration {
	if dl, ok := ctx.Deadline(); ok {
		if left := time.Until(dl); left < budget {
			if left < time.Second {
				return time.Second
			}
			return left
		}
	}
	return budget
}

// runFailure turns a process-level problem into a failure result; ok is false when the process produced a usable exit.
func runFailure(res jobs.ExecResult) (remote.Result, bool) {
	switch {
	case res.StartErr != nil && !res.HaveExit:
		return remote.Fail(DetailWUUnavailable, map[string]any{"message": clip("cannot start powershell: "+res.StartErr.Error(), maxDetail)}), true
	case res.TimedOut:
		return remote.Fail(DetailTimeout, nil), true
	case res.Cancelled:
		return remote.Fail("cancelled", nil), true
	}
	return remote.Result{}, false
}

// extractJSON returns the last line of the output that is a complete JSON object. CLIXML noise (error and progress streams of Windows PowerShell 5.1),
// a byte order mark, blank lines and anything printed before the document are ignored.
func extractJSON(out string) ([]byte, bool) {
	out = jobs.CleanCLIXML(out)
	lines := strings.Split(strings.ReplaceAll(out, "\r", ""), "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		l := strings.TrimSpace(strings.TrimPrefix(lines[i], utf8BOM))
		if strings.HasPrefix(l, "{") && strings.HasSuffix(l, "}") && json.Valid([]byte(l)) {
			return []byte(l), true
		}
	}
	return nil, false
}

// scriptFailure converts an {"ok":false,...} document of a script into the contract's failure output.
func scriptFailure(raw []byte) remote.Result {
	var f struct {
		Detail  string `json:"detail"`
		Message string `json:"message"`
	}
	_ = json.Unmarshal(raw, &f)
	detail := f.Detail
	if !detailCodeRe.MatchString(detail) {
		detail = DetailScanFailed
	}
	extra := map[string]any{}
	if f.Message != "" {
		extra["message"] = clip(f.Message, maxDetail)
	}
	return remote.Fail(detail, extra)
}

func (e Engine) normaliseScan(res jobs.ExecResult, p ScanParams, maxOut int) remote.Result {
	if r, bad := runFailure(res); bad {
		return r
	}
	raw, ok := extractJSON(res.Output)
	if !ok {
		return remote.Fail(DetailParseError, map[string]any{"message": clip(fmt.Sprintf("no JSON document in the script output (exit %d)", res.ExitCode), maxDetail)})
	}
	var head struct {
		OK *bool `json:"ok"`
	}
	if err := json.Unmarshal(raw, &head); err != nil || head.OK == nil {
		return remote.Fail(DetailParseError, nil)
	}
	if !*head.OK {
		return scriptFailure(raw)
	}
	var in struct {
		ScanOut
		Updates []Update `json:"updates"`
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	if err := dec.Decode(&in); err != nil {
		return remote.Fail(DetailParseError, nil)
	}
	out := ScanOut{V: 1, OK: true, Manager: ManagerWUA, ScanState: "ok", WUState: "n/a", RebootPending: in.RebootPending, Truncated: in.Truncated, Total: in.Total}
	if in.ScanState == "partial" {
		out.ScanState = "partial"
	}
	if validWU[in.WUState] {
		out.WUState = in.WUState
	}
	out.ScannedAt = rfc3339(e.now())
	if t, err := time.Parse(time.RFC3339, in.ScannedAt); err == nil {
		out.ScannedAt = rfc3339(t)
	}
	out.LastInstallAt = normTime(in.LastInstallAt)
	seen := map[string]bool{}
	for _, u := range in.Updates {
		if !ValidWindowsID(u.ID) || seen[u.ID] {
			continue
		}
		seen[u.ID] = true
		out.Updates = append(out.Updates, normUpdate(u))
	}
	return finishScan(out, p.MaxItems, maxOut)
}

func normTime(s *string) *string {
	if s == nil {
		return nil
	}
	t, err := time.Parse(time.RFC3339, *s)
	if err != nil {
		return nil
	}
	v := rfc3339(t)
	return &v
}

var kbRe = regexp.MustCompile(`^KB[0-9]{4,9}$`)

// normUpdate forces one update into the contract: allow-listed enums, bounded text, valid dates.
func normUpdate(u Update) Update {
	if !validKinds[u.Kind] {
		u.Kind = "other"
	}
	if !validSev[u.Severity] {
		u.Severity = "unspecified"
	}
	if !kbRe.MatchString(u.KB) {
		u.KB = ""
	}
	u.Title = clip(u.Title, maxTitle)
	if u.Size < 0 {
		u.Size = 0
	}
	u.ReleasedAt = normTime(u.ReleasedAt)
	cats := make([]string, 0, len(u.Categories))
	for _, c := range u.Categories {
		if len(cats) < 20 {
			cats = append(cats, clip(c, 100))
		}
	}
	u.Categories = cats
	u.Current, u.Version = clip(u.Current, 100), clip(u.Version, 100)
	return u
}

func (e Engine) normaliseInstall(res jobs.ExecResult, p InstallParams, maxOut int) remote.Result {
	if r, bad := runFailure(res); bad {
		return r
	}
	raw, ok := extractJSON(res.Output)
	if !ok {
		return remote.Fail(DetailParseError, map[string]any{"message": clip(fmt.Sprintf("no JSON document in the script output (exit %d)", res.ExitCode), maxDetail)})
	}
	var head struct {
		OK *bool `json:"ok"`
	}
	if err := json.Unmarshal(raw, &head); err != nil || head.OK == nil {
		return remote.Fail(DetailParseError, nil)
	}
	if !*head.OK {
		// A script failure (wu_unavailable, invalid_params) keeps its code and the mode, so the server can tell a dry run from a real one.
		r := scriptFailure(raw)
		return r
	}
	var in InstallOut
	if err := json.Unmarshal(raw, &in); err != nil {
		return remote.Fail(DetailParseError, nil)
	}
	by := map[string]ResultItem{}
	for _, r := range in.Results {
		if _, dup := by[r.ID]; !dup {
			by[r.ID] = r
		}
	}
	out := InstallOut{Mode: p.Mode, Manager: ManagerWUA, RebootRequired: in.RebootRequired}
	for _, id := range p.IDs { // exactly the requested ids, in the requested order
		r, has := by[id]
		switch {
		case !has:
			r = ResultItem{ID: id, Result: "failed", Code: "no_result", Detail: "the script reported nothing for this id"}
		case !allResults[r.Result]:
			r = ResultItem{ID: id, Title: r.Title, Result: "failed", Code: "bad_result", Detail: "unknown result from the script"}
		}
		r.Title, r.Detail, r.Code = clip(r.Title, maxTitle), clip(r.Detail, maxDetail), clip(r.Code, 40)
		out.Results = append(out.Results, r)
	}
	return finishInstall(out, maxOut)
}
