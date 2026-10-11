package logs

import (
	"bytes"
	"context"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/remote"
)

const (
	wevtTimeout  = 30 * time.Second
	wevtMaxBytes = 8 << 20
)

var channelRE = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 _./%-]{0,99}$`)

// ValidChannel reports whether an event log channel name may reach wevtutil. The
// first character is alphanumeric so it can never look like a switch.
func ValidChannel(s string) bool { return channelRE.MatchString(s) }

// LevelNumber maps a level name to the Windows event Level value.
func LevelNumber(name string) (int, bool) {
	switch strings.ToLower(name) {
	case "critical":
		return 1, true
	case "error":
		return 2, true
	case "warning":
		return 3, true
	case "information", "info":
		return 4, true
	case "verbose", "debug":
		return 5, true
	}
	return 0, false
}

// EventLogXPath builds the wevtutil /q: query ONLY from validated integers:
//
//	*[System[(Level=1 or Level=2) and EventRecordID>123 and (EventID=7036 or EventID=7040)]]
func EventLogXPath(levels []string, eventIDs []int, afterID int64) (string, error) {
	if afterID < 0 {
		return "", errors.New("negative record id")
	}
	var conds []string
	seen := map[int]bool{}
	var lv []int
	for _, l := range levels {
		n, ok := LevelNumber(l)
		if !ok {
			return "", fmt.Errorf("invalid level %q", l)
		}
		if !seen[n] {
			seen[n] = true
			lv = append(lv, n)
		}
	}
	sort.Ints(lv)
	if len(lv) > 0 {
		parts := make([]string, len(lv))
		for i, n := range lv {
			parts[i] = "Level=" + strconv.Itoa(n)
		}
		conds = append(conds, "("+strings.Join(parts, " or ")+")")
	}
	conds = append(conds, "EventRecordID>"+strconv.FormatInt(afterID, 10))
	if len(eventIDs) > 0 {
		if len(eventIDs) > 50 {
			return "", errors.New("too many event ids")
		}
		ids := append([]int(nil), eventIDs...)
		sort.Ints(ids)
		var parts []string
		for i, id := range ids {
			if id < 0 || id > 65535 {
				return "", fmt.Errorf("invalid event id %d", id)
			}
			if i > 0 && ids[i-1] == id {
				continue
			}
			parts = append(parts, "EventID="+strconv.Itoa(id))
		}
		conds = append(conds, "("+strings.Join(parts, " or ")+")")
	}
	return "*[System[" + strings.Join(conds, " and ") + "]]", nil
}

// EventLogArgv is the wevtutil argument list for reading after a record id.
func EventLogArgv(src api.LogSource, limit int, afterID int64) ([]string, error) {
	if !ValidChannel(src.Channel) {
		return nil, fmt.Errorf("invalid channel %q", src.Channel)
	}
	q, err := EventLogXPath(src.Levels, src.EventIDs, afterID)
	if err != nil {
		return nil, err
	}
	if limit <= 0 {
		limit = readBatch
	}
	return []string{"qe", src.Channel, "/f:xml", "/rd:false", "/c:" + strconv.Itoa(limit), "/q:" + q}, nil
}

// EventLogNewestArgv reads only the newest record of a channel (the baseline of
// the first run, which never backfills).
func EventLogNewestArgv(channel string) ([]string, error) {
	if !ValidChannel(channel) {
		return nil, fmt.Errorf("invalid channel %q", channel)
	}
	return []string{"qe", channel, "/f:xml", "/rd:true", "/c:1"}, nil
}

type xmlData struct {
	Name  string `xml:"Name,attr"`
	Value string `xml:",chardata"`
}

type xmlEvent struct {
	System struct {
		Provider struct {
			Name string `xml:"Name,attr"`
		} `xml:"Provider"`
		EventID     string `xml:"EventID"`
		Level       string `xml:"Level"`
		TimeCreated struct {
			SystemTime string `xml:"SystemTime,attr"`
		} `xml:"TimeCreated"`
		EventRecordID string `xml:"EventRecordID"`
	} `xml:"System"`
	EventData struct {
		Data []xmlData `xml:"Data"`
	} `xml:"EventData"`
	UserData struct {
		Inner string `xml:",innerxml"`
	} `xml:"UserData"`
}

// userDataText returns the text values of a UserData element (arbitrary nested
// elements; only character data is kept, never element or attribute names).
func userDataText(inner string) []string {
	if strings.TrimSpace(inner) == "" {
		return nil
	}
	dec := xml.NewDecoder(strings.NewReader("<u>" + inner + "</u>"))
	dec.Strict = false
	var out []string
	for len(out) < 32 {
		tok, err := dec.Token()
		if err != nil {
			break
		}
		if cd, ok := tok.(xml.CharData); ok {
			if t := strings.TrimSpace(string(cd)); t != "" {
				out = append(out, t)
			}
		}
	}
	return out
}

// EventLogRecord is one parsed event.
type EventLogRecord struct {
	RecordID int64
	Event    api.LogEvent
}

func eventLevelName(n int) string {
	switch n {
	case 1:
		return "critical"
	case 2:
		return "error"
	case 3:
		return "warning"
	case 5:
		return "debug"
	}
	return "info"
}

// ParseEventLogXML parses wevtutil /f:xml output (concatenated <Event> elements
// without a root). The message is built from the EventData values joined by
// " | "; the localised rendered message is NOT used. Malformed events are skipped.
func ParseEventLogXML(out []byte) ([]EventLogRecord, error) {
	dec := xml.NewDecoder(io.MultiReader(strings.NewReader("<Events>"), bytes.NewReader(out), strings.NewReader("</Events>")))
	dec.Strict = false
	var recs []EventLogRecord
	for {
		tok, err := dec.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			if len(recs) > 0 {
				return recs, nil // a cut-off tail: keep what parsed
			}
			return nil, err
		}
		se, ok := tok.(xml.StartElement)
		if !ok || se.Name.Local != "Event" {
			continue
		}
		var e xmlEvent
		if err := dec.DecodeElement(&e, &se); err != nil {
			if len(recs) > 0 {
				return recs, nil
			}
			return nil, err
		}
		id, err := strconv.ParseInt(strings.TrimSpace(e.System.EventRecordID), 10, 64)
		if err != nil || id <= 0 {
			continue
		}
		ts, err := time.Parse(time.RFC3339Nano, strings.TrimSpace(e.System.TimeCreated.SystemTime))
		if err != nil {
			continue
		}
		lv, _ := strconv.Atoi(strings.TrimSpace(e.System.Level))
		var eid *int
		if n, err := strconv.Atoi(strings.TrimSpace(e.System.EventID)); err == nil {
			eid = &n
		}
		var vals []string
		for _, d := range e.EventData.Data {
			if v := CleanMessage(d.Value); v != "" {
				vals = append(vals, v)
			}
		}
		for _, v := range userDataText(e.UserData.Inner) {
			if v = CleanMessage(v); v != "" {
				vals = append(vals, v)
			}
		}
		msg := strings.Join(vals, " | ")
		if msg == "" {
			msg = "event " + strings.TrimSpace(e.System.EventID)
		}
		src := TruncateUTF8(CleanMessage(e.System.Provider.Name), 200)
		if src == "" {
			src = "unknown"
		}
		recs = append(recs, EventLogRecord{RecordID: id, Event: api.LogEvent{
			TS: ts.UTC().Format(time.RFC3339), Level: eventLevelName(lv), Source: src, EventID: eid, Message: msg,
		}})
	}
	return recs, nil
}

// EventLogReader reads the Windows event log through wevtutil.exe. The reader is
// platform neutral (it only needs a Runner); it is selected on Windows only.
type EventLogReader struct {
	R   remote.Runner
	Bin string // default "wevtutil.exe"
}

func (r EventLogReader) run(ctx context.Context, argv []string) ([]EventLogRecord, error) {
	bin := r.Bin
	if bin == "" {
		bin = "wevtutil.exe"
	}
	res, err := r.R.Run(ctx, bin, argv, wevtMaxBytes, wevtTimeout)
	if err != nil {
		return nil, fmt.Errorf("wevtutil: %w", err)
	}
	if res.ExitCode != 0 {
		msg := strings.TrimSpace(string(res.Stderr))
		if i := strings.IndexByte(msg, '\n'); i >= 0 {
			msg = msg[:i]
		}
		return nil, fmt.Errorf("wevtutil exited %d: %s", res.ExitCode, TruncateUTF8(msg, 160))
	}
	out := res.Stdout
	if res.Truncated { // drop a cut-off final event
		if i := bytes.LastIndex(out, []byte("</Event>")); i >= 0 {
			out = out[:i+len("</Event>")]
		} else {
			out = nil
		}
	}
	return ParseEventLogXML(out)
}

// Read implements Reader. The bookmark is the highest EventRecordID examined.
func (r EventLogReader) Read(ctx context.Context, req ReadRequest) (ReadResult, error) {
	if req.From == "" { // first run: start at the newest record, no backfill
		argv, err := EventLogNewestArgv(req.Src.Channel)
		if err != nil {
			return ReadResult{}, err
		}
		recs, err := r.run(ctx, argv)
		if err != nil {
			return ReadResult{}, err
		}
		var top int64
		for _, rec := range recs {
			top = max(top, rec.RecordID)
		}
		if top == 0 {
			return ReadResult{}, nil // an empty channel: try again next interval
		}
		return ReadResult{LastPos: strconv.FormatInt(top, 10)}, nil
	}
	after, err := strconv.ParseInt(req.From, 10, 64)
	if err != nil || after < 0 {
		return ReadResult{}, errors.New("invalid bookmark")
	}
	argv, err := EventLogArgv(req.Src, req.Limit, after)
	if err != nil {
		return ReadResult{}, err
	}
	recs, err := r.run(ctx, argv)
	if err != nil {
		return ReadResult{}, err
	}
	sort.SliceStable(recs, func(i, j int) bool { return recs[i].RecordID < recs[j].RecordID })
	var res ReadResult
	for _, rec := range recs {
		if rec.RecordID <= after {
			continue
		}
		res.LastPos = strconv.FormatInt(rec.RecordID, 10)
		if !MatchesContains(rec.Event.Message, req.Src.Contains) {
			continue
		}
		res.Items = append(res.Items, Item{Event: rec.Event, Pos: res.LastPos})
	}
	return res, nil
}
