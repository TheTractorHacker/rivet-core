package change

import (
	"fmt"
	"sort"
	"strings"
	"time"
)

// Record is one detected change: the body member of POST agent_changes.
type Record struct {
	TS        string `json:"ts"`
	Kind      string `json:"kind"`
	Watch     string `json:"watch"`
	Change    string `json:"change"` // added, modified, removed
	Target    string `json:"target"`
	Item      string `json:"item"`
	Old       string `json:"old"`
	New       string `json:"new"`
	Summary   string `json:"summary,omitempty"`
	OldHash   string `json:"old_hash,omitempty"`
	NewHash   string `json:"new_hash,omitempty"`
	Actor     string `json:"actor,omitempty"`
	Process   string `json:"process,omitempty"`
	Redacted  bool   `json:"redacted,omitempty"`
	Truncated bool   `json:"truncated,omitempty"`
}

// Item flags.
const (
	FlagRedacted  = 1
	FlagTruncated = 2
)

// Item is one thing a watch observed: a registry value, a file, a group member, a
// task. It is what the snapshot keeps between two polls, so it holds nothing the
// agent would not also send: values are redacted and cut, file lines are
// redacted and cut BEFORE they are stored.
type Item struct {
	Target string   `json:"t"`
	Name   string   `json:"n"`
	Value  string   `json:"v,omitempty"`
	Hash   string   `json:"h"`
	Meta   string   `json:"m,omitempty"`
	Size   int64    `json:"s,omitempty"`
	Flags  int      `json:"f,omitempty"`
	Lines  []string `json:"l,omitempty"`
}

// itemKey is the identity of an item: registry names are not case sensitive.
func itemKey(kind, target, name string) string {
	if kind == "registry" || kind == "task" {
		return strings.ToLower(target) + "\x00" + strings.ToLower(name)
	}
	return target + "\x00" + name
}

// Snapshot is the items of one watch by key.
type Snapshot map[string]Item

// Limits of the diff text.
const (
	maxDiffLinesShown = 5
	maxLines          = 2000
	maxLineBytes      = 200
	maxDiffBytes      = 64 << 10
)

// Diff compares two snapshots of one watch. Records come out sorted by target and
// item, so the same change always produces the same records. (Items under a place
// the new read could not look into are carried over by the caller first, so they
// are never reported as removed.)
func Diff(w Watch, prev, cur Snapshot, at time.Time, maxValue int) []Record {
	keys := make([]string, 0, len(cur)+len(prev))
	seen := map[string]bool{}
	for k := range cur {
		keys = append(keys, k)
		seen[k] = true
	}
	for k := range prev {
		if !seen[k] {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)
	ts := at.UTC().Format(time.RFC3339)
	var out []Record
	for _, k := range keys {
		p, hadPrev := prev[k]
		c, hasCur := cur[k]
		switch {
		case hasCur && !hadPrev:
			out = append(out, record(w, ts, "added", Item{}, c, maxValue))
		case hadPrev && !hasCur:
			out = append(out, record(w, ts, "removed", p, Item{}, maxValue))
		case p.Hash != c.Hash || p.Meta != c.Meta:
			out = append(out, record(w, ts, "modified", p, c, maxValue))
		}
	}
	return out
}

func record(w Watch, ts, change string, old, cur Item, maxValue int) Record {
	it := cur
	if change == "removed" {
		it = old
	}
	r := Record{TS: ts, Kind: w.Kind, Watch: w.ID, Change: change, Target: it.Target, Item: it.Name, OldHash: old.Hash, NewHash: cur.Hash,
		Redacted: (old.Flags|cur.Flags)&FlagRedacted != 0, Truncated: (old.Flags|cur.Flags)&FlagTruncated != 0}
	switch w.Kind {
	case "file":
		r.Summary, r.Old, r.New = fileDelta(change, old, cur, maxValue)
	default:
		r.Old, r.New = old.Value, cur.Value
		if w.Kind == "registry" && change == "added" && cur.Value == "" {
			r.Summary = "key or value added"
		}
	}
	return r
}

// fileDelta describes a file change in words and in the lines that differ.
func fileDelta(change string, old, cur Item, maxValue int) (summary, o, n string) {
	switch change {
	case "added":
		if cur.Lines != nil {
			return fmt.Sprintf("file added (%d lines)", len(cur.Lines)), "", joinLines(cur.Lines[:min(3, len(cur.Lines))], maxValue)
		}
		return fmt.Sprintf("file added (%d bytes)", cur.Size), "", cur.Value
	case "removed":
		if old.Lines != nil {
			return "file removed", joinLines(old.Lines[:min(3, len(old.Lines))], maxValue), ""
		}
		return "file removed", old.Value, ""
	}
	if old.Hash == cur.Hash {
		return fmt.Sprintf("permissions or owner changed: %s -> %s", old.Meta, cur.Meta), "", ""
	}
	if old.Lines != nil && cur.Lines != nil {
		added, removed := lineDiff(old.Lines, cur.Lines)
		s := fmt.Sprintf("+%d -%d lines", len(added), len(removed))
		if old.Meta != cur.Meta {
			s += fmt.Sprintf("; permissions or owner changed: %s -> %s", old.Meta, cur.Meta)
		}
		if len(added) == 0 && len(removed) == 0 {
			s = "content changed (whitespace, ordering or line endings only)"
		}
		return s, joinLines(removed, maxValue), joinLines(added, maxValue)
	}
	return fmt.Sprintf("content changed (%d -> %d bytes; no line detail kept)", old.Size, cur.Size), old.Value, cur.Value
}

// lineDiff returns the lines that are only in cur (added) and only in old
// (removed), as multisets, in order of appearance.
func lineDiff(old, cur []string) (added, removed []string) {
	count := map[string]int{}
	for _, l := range old {
		count[l]++
	}
	for _, l := range cur {
		if count[l] > 0 {
			count[l]--
		} else {
			added = append(added, l)
		}
	}
	count = map[string]int{}
	for _, l := range cur {
		count[l]++
	}
	for _, l := range old {
		if count[l] > 0 {
			count[l]--
		} else {
			removed = append(removed, l)
		}
	}
	return added, removed
}

func joinLines(lines []string, max int) string {
	if len(lines) == 0 {
		return ""
	}
	shown := lines
	more := 0
	if len(shown) > maxDiffLinesShown {
		shown, more = shown[:maxDiffLinesShown], len(shown)-maxDiffLinesShown
	}
	s := strings.Join(shown, " | ")
	if more > 0 {
		s += fmt.Sprintf(" | (+%d more)", more)
	}
	return Truncate(s, max)
}
