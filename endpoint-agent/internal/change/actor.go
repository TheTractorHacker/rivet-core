package change

import (
	"context"
	"fmt"
	"strings"
	"time"

	"rivetit-agent/internal/logs"
)

const (
	actorLookback = 20 * time.Minute
	actorCooldown = time.Hour
	actorMaxEvts  = 300
)

// objectToKey maps the registry path Windows writes in an audit event
// (\REGISTRY\MACHINE\SOFTWARE\...) to the form the watches use.
func objectToKey(obj string) string {
	up := strings.ToUpper(obj)
	switch {
	case strings.HasPrefix(up, `\REGISTRY\MACHINE\`):
		return `HKLM\` + obj[len(`\REGISTRY\MACHINE\`):]
	case strings.HasPrefix(up, `\REGISTRY\USER\`):
		return `HKU\` + obj[len(`\REGISTRY\USER\`):]
	}
	return ""
}

// findActors fills Actor and Process on registry records from the Security log's
// 4657 events (a registry value was modified), when auditing recorded them. Best
// effort by design: Windows writes those events only for keys that have an audit
// entry in their ACL, so on most machines the answer is "unknown" and the fields
// stay empty. One query per poll, only when registry changes were detected, and a
// failure (no rights, no log) switches the lookup off for an hour.
func (e *engine) findActors(ctx context.Context, recs []Record) {
	if e.o.GOOS != "windows" || e.o.Runner == nil || e.now().Before(e.actorOffUntil) {
		return
	}
	any := false
	for _, r := range recs {
		if r.Kind == "registry" {
			any = true
			break
		}
	}
	if !any {
		return
	}
	ms := int(actorLookback / time.Millisecond)
	q := fmt.Sprintf("*[System[(EventID=4657) and TimeCreated[timediff(@SystemTime) <= %d]]]", ms)
	res, err := e.o.Runner.Run(ctx, "wevtutil.exe", []string{"qe", "Security", "/f:xml", "/rd:true", fmt.Sprintf("/c:%d", actorMaxEvts), "/q:" + q}, 8<<20, 30*time.Second)
	if err != nil || res.ExitCode != 0 {
		e.actorOffUntil = e.now().Add(actorCooldown)
		return
	}
	evs, err := logs.ParseEventLogXML(res.Stdout)
	if err != nil {
		return
	}
	type who struct{ actor, proc string }
	latest := map[string]who{} // lower(key) + "\x00" + lower(value name) -> newest first (the query is newest first)
	for _, ev := range evs {
		key := objectToKey(ev.Fields["ObjectName"])
		if key == "" {
			continue
		}
		id := strings.ToLower(key) + "\x00" + strings.ToLower(ev.Fields["ObjectValueName"])
		if _, ok := latest[id]; ok {
			continue
		}
		user := ev.Fields["SubjectUserName"]
		if d := ev.Fields["SubjectDomainName"]; d != "" && user != "" {
			user = d + `\` + user
		}
		latest[id] = who{actor: Truncate(Clean(user), 120), proc: Truncate(Clean(ev.Fields["ProcessName"]), 200)}
	}
	for i := range recs {
		if recs[i].Kind != "registry" {
			continue
		}
		item := recs[i].Item
		if item == "(Default)" {
			item = ""
		}
		if w, ok := latest[strings.ToLower(recs[i].Target)+"\x00"+strings.ToLower(item)]; ok {
			recs[i].Actor, recs[i].Process = w.actor, e.redactText(w.proc)
		}
	}
}

func (e *engine) redactText(s string) string {
	if e.o.Redact != nil {
		return e.o.Redact(s)
	}
	return s
}
