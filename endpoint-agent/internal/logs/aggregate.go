package logs

import (
	"fmt"
	"regexp"
	"sort"
	"strings"
	"time"

	"rivetit-agent/internal/api"
)

// Bounds of an aggregated source (security presets): the server asks for a window
// and a minimum count; the agent clamps both.
const (
	minAggWindow   = 60
	maxAggWindow   = 3600
	maxAggMin      = 10000
	maxAggGroups   = 200
	maxAggBy       = 4
	aggFlushGrace  = 5 * time.Second
	aggOtherGroup  = "(other)"
	maxBucketsKept = 8
)

var reAggField = regexp.MustCompile(`^[A-Za-z0-9]{1,40}$`)

type aggGroup struct {
	count  int
	values []string
}

type aggBucket struct {
	groups map[string]*aggGroup
}

// aggregator sums up the events of one source: one summary entry per window and per
// combination of the By fields, only when the count reaches Min. It keeps counts in
// memory only (an agent restart loses the open window, at most one window of counts).
type aggregator struct {
	window  time.Duration
	min     int
	by      []string
	buckets map[int64]*aggBucket
	source  string
	eventID *int
}

// newAggregator validates the request of the server; nil when it cannot be honoured
// (such a source is skipped, never read raw).
func newAggregator(a *api.LogAggregate) *aggregator {
	if a == nil || a.WindowS < minAggWindow || a.WindowS > maxAggWindow || a.Min < 1 || a.Min > maxAggMin || len(a.By) > maxAggBy {
		return nil
	}
	for _, f := range a.By {
		if !reAggField.MatchString(f) {
			return nil
		}
	}
	return &aggregator{window: time.Duration(a.WindowS) * time.Second, min: a.Min, by: append([]string(nil), a.By...), buckets: map[int64]*aggBucket{}}
}

func (g *aggregator) add(it Item, now time.Time) {
	t, err := time.Parse(time.RFC3339, it.Event.TS)
	if err != nil {
		t = now
	}
	id := t.Unix() / int64(g.window/time.Second)
	b := g.buckets[id]
	if b == nil {
		if len(g.buckets) >= maxBucketsKept {
			g.dropOldest()
		}
		b = &aggBucket{groups: map[string]*aggGroup{}}
		g.buckets[id] = b
	}
	vals := make([]string, len(g.by))
	for i, f := range g.by {
		vals[i] = CleanMessage(it.Fields[f])
		if vals[i] == "" || vals[i] == "-" {
			vals[i] = "?"
		}
	}
	key := strings.Join(vals, "\x00")
	grp := b.groups[key]
	if grp == nil {
		if len(b.groups) >= maxAggGroups {
			key, vals = aggOtherGroup, []string{aggOtherGroup}
			grp = b.groups[key]
		}
		if grp == nil {
			grp = &aggGroup{values: vals}
			b.groups[key] = grp
		}
	}
	grp.count++
	if g.source == "" {
		g.source, g.eventID = it.Event.Source, it.Event.EventID
	}
}

func (g *aggregator) dropOldest() {
	var oldest int64 = -1
	for id := range g.buckets {
		if oldest < 0 || id < oldest {
			oldest = id
		}
	}
	delete(g.buckets, oldest)
}

// flush returns the summary entries of every window that has ended.
func (g *aggregator) flush(now time.Time, redact func(string) string) []api.LogEvent {
	var out []api.LogEvent
	ids := make([]int64, 0, len(g.buckets))
	for id := range g.buckets {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })
	secs := int64(g.window / time.Second)
	for _, id := range ids {
		end := time.Unix((id+1)*secs, 0).UTC()
		if now.Before(end.Add(aggFlushGrace)) {
			continue
		}
		b := g.buckets[id]
		delete(g.buckets, id)
		keys := make([]string, 0, len(b.groups))
		for k := range b.groups {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			grp := b.groups[k]
			if grp.count < g.min {
				continue
			}
			var parts []string
			for i, v := range grp.values {
				name := aggOtherGroup
				if i < len(g.by) {
					name = g.by[i]
				}
				parts = append(parts, name+"="+v)
			}
			msg := fmt.Sprintf("%d events in %ds: %s", grp.count, secs, strings.Join(parts, " "))
			if redact != nil {
				msg = redact(msg)
			}
			out = append(out, api.LogEvent{TS: end.Format(time.RFC3339), Level: "warning", Source: g.source, EventID: g.eventID, Message: msg})
		}
	}
	return out
}
