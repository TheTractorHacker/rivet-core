package logs

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/remote"
)

const (
	journalTimeout  = 20 * time.Second
	journalMaxBytes = 8 << 20
	firstRunWindow  = 5 * time.Minute
)

var (
	journalNameRE   = regexp.MustCompile(`^[A-Za-z0-9:_.@-]{1,200}$`)
	journalCursorRE = regexp.MustCompile(`^[A-Za-z0-9=;_-]{1,512}$`)
)

// ValidJournalName reports whether a unit or identifier may reach journalctl.
func ValidJournalName(s string) bool {
	return journalNameRE.MatchString(s) && !strings.HasPrefix(s, "-")
}

// ValidCursor reports whether a stored cursor is safe to pass as an argument.
func ValidCursor(s string) bool { return journalCursorRE.MatchString(s) }

// JournalPriority maps min_level to the numeric journalctl --priority.
func JournalPriority(level string) (int, bool) {
	switch level {
	case "critical":
		return 2, true
	case "error":
		return 3, true
	case "warning":
		return 4, true
	case "info":
		return 6, true
	case "debug":
		return 7, true
	}
	return 0, false
}

// JournalArgv builds the journalctl argument list (never a shell string).
// Different fields AND, repeated values of one field OR, which is what the
// source filter means. The first run (no cursor) starts firstRunWindow ago.
func JournalArgv(src api.LogSource, limit int, cursor string, now time.Time) ([]string, error) {
	if limit <= 0 {
		limit = readBatch
	}
	args := []string{"--output=json", "--no-pager", "--quiet", "--lines=" + strconv.Itoa(limit)}
	if cursor != "" {
		if !ValidCursor(cursor) {
			return nil, errors.New("invalid cursor")
		}
		args = append(args, "--after-cursor="+cursor)
	} else {
		args = append(args, "--since="+now.Add(-firstRunWindow).UTC().Format("2006-01-02 15:04:05")+" UTC")
	}
	if src.MinLevel != "" {
		p, ok := JournalPriority(src.MinLevel)
		if !ok {
			return nil, fmt.Errorf("invalid min_level %q", src.MinLevel)
		}
		args = append(args, "--priority="+strconv.Itoa(p))
	}
	for _, u := range src.Units {
		if !ValidJournalName(u) {
			return nil, fmt.Errorf("invalid unit %q", u)
		}
		args = append(args, "_SYSTEMD_UNIT="+u)
	}
	for _, id := range src.Identifiers {
		if !ValidJournalName(id) {
			return nil, fmt.Errorf("invalid identifier %q", id)
		}
		args = append(args, "SYSLOG_IDENTIFIER="+id)
	}
	return args, nil
}

// fieldText reads a journal JSON field: a string, a byte array (journald encodes
// non-UTF-8 or binary values that way), an array of strings (repeated field) or a
// number.
func fieldText(raw json.RawMessage) (string, bool) {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 || string(raw) == "null" {
		return "", false
	}
	switch raw[0] {
	case '"':
		var s string
		if json.Unmarshal(raw, &s) != nil {
			return "", false
		}
		return s, true
	case '[':
		var nums []int
		if json.Unmarshal(raw, &nums) == nil {
			if len(nums) > 64<<10 {
				nums = nums[:64<<10]
			}
			b := make([]byte, 0, len(nums))
			for _, n := range nums {
				if n < 0 || n > 255 {
					return "", false
				}
				b = append(b, byte(n))
			}
			return string(b), true
		}
		var strs []string
		if json.Unmarshal(raw, &strs) == nil && len(strs) > 0 {
			return strs[0], true
		}
		return "", false
	}
	var n json.Number
	if json.Unmarshal(raw, &n) == nil {
		return n.String(), true
	}
	return "", false
}

// ParseJournalLine turns one `journalctl --output=json` line into an event.
// ok is false for a line that carries no usable event (cursor is still returned
// when the line parsed, so a skipped record advances the bookmark).
func ParseJournalLine(line []byte) (ev api.LogEvent, cursor string, ok bool) {
	var m map[string]json.RawMessage
	if err := json.Unmarshal(bytes.TrimSpace(line), &m); err != nil {
		return ev, "", false
	}
	cursor, _ = fieldText(m["__CURSOR"])
	if !ValidCursor(cursor) {
		cursor = ""
	}
	tsText, tok := fieldText(m["__REALTIME_TIMESTAMP"])
	us, err := strconv.ParseInt(tsText, 10, 64)
	if !tok || err != nil || us <= 0 {
		return ev, cursor, false
	}
	msg, mok := fieldText(m["MESSAGE"])
	if !mok {
		return ev, cursor, false
	}
	prio := 6
	if p, ok := fieldText(m["PRIORITY"]); ok {
		if n, err := strconv.Atoi(strings.TrimSpace(p)); err == nil && n >= 0 && n <= 7 {
			prio = n
		}
	}
	source, _ := fieldText(m["_SYSTEMD_UNIT"])
	if source == "" {
		source, _ = fieldText(m["SYSLOG_IDENTIFIER"])
	}
	source = TruncateUTF8(CleanMessage(source), 200)
	if source == "" {
		source = "unknown"
	}
	ev = api.LogEvent{
		TS:      time.UnixMicro(us).UTC().Format(time.RFC3339),
		Level:   LevelName(prio),
		Source:  source,
		Message: CleanMessage(msg),
	}
	return ev, cursor, true
}

// ParseJournalOutput parses a whole journalctl output. When truncated, the last
// (possibly partial) line is ignored.
func ParseJournalOutput(out []byte, truncated bool, contains []string) ReadResult {
	if truncated {
		if i := bytes.LastIndexByte(out, '\n'); i >= 0 {
			out = out[:i+1]
		} else {
			out = nil
		}
	}
	var res ReadResult
	for _, line := range bytes.Split(out, []byte{'\n'}) {
		if len(bytes.TrimSpace(line)) == 0 {
			continue
		}
		ev, cur, ok := ParseJournalLine(line)
		if cur != "" {
			res.LastPos = cur
		}
		if !ok || !MatchesContains(ev.Message, contains) {
			continue
		}
		res.Items = append(res.Items, Item{Event: ev, Pos: cur})
	}
	return res
}

// JournalReader reads systemd-journald through journalctl.
type JournalReader struct {
	R   remote.Runner
	Now func() time.Time
	Bin string // default "journalctl"
}

// Read implements Reader.
func (j JournalReader) Read(ctx context.Context, req ReadRequest) (ReadResult, error) {
	now := time.Now
	if j.Now != nil {
		now = j.Now
	}
	argv, err := JournalArgv(req.Src, req.Limit, req.From, now())
	if err != nil {
		return ReadResult{}, err
	}
	bin := j.Bin
	if bin == "" {
		bin = "journalctl"
	}
	res, err := j.R.Run(ctx, bin, argv, journalMaxBytes, journalTimeout)
	if err != nil {
		return ReadResult{}, fmt.Errorf("journalctl: %w", err)
	}
	if res.ExitCode != 0 {
		msg := strings.TrimSpace(string(res.Stderr))
		if i := strings.IndexByte(msg, '\n'); i >= 0 {
			msg = msg[:i]
		}
		return ReadResult{}, fmt.Errorf("journalctl exited %d: %s", res.ExitCode, TruncateUTF8(msg, 160))
	}
	return ParseJournalOutput(res.Stdout, res.Truncated, req.Src.Contains), nil
}
