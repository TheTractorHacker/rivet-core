package change

import (
	"bytes"
	"encoding/binary"
	"encoding/xml"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"unicode/utf16"
	"unicode/utf8"
)

const (
	maxTaskFiles    = 5000
	maxTaskFileSize = 256 << 10
	maxTaskDepth    = 6
)

var reXMLDecl = regexp.MustCompile(`^\s*<\?xml[^>]*\?>`)

type taskXML struct {
	URI      string   `xml:"RegistrationInfo>URI"`
	Author   string   `xml:"RegistrationInfo>Author"`
	UserID   string   `xml:"Principals>Principal>UserId"`
	RunLevel string   `xml:"Principals>Principal>RunLevel"`
	LogonTyp string   `xml:"Principals>Principal>LogonType"`
	Enabled  string   `xml:"Settings>Enabled"`
	Execs    []taskEx `xml:"Actions>Exec"`
	Triggers struct {
		Inner string `xml:",innerxml"`
	} `xml:"Triggers"`
}

type taskEx struct {
	Command   string `xml:"Command"`
	Arguments string `xml:"Arguments"`
}

// decodeTaskFile turns the bytes of a task definition file into UTF-8. Windows
// writes them as UTF-16 with a byte order mark; UTF-8 is accepted too.
func decodeTaskFile(b []byte) string {
	if len(b) >= 2 && ((b[0] == 0xFF && b[1] == 0xFE) || (b[0] == 0xFE && b[1] == 0xFF)) {
		big := b[0] == 0xFE
		b = b[2:]
		u := make([]uint16, 0, len(b)/2)
		for i := 0; i+1 < len(b); i += 2 {
			if big {
				u = append(u, binary.BigEndian.Uint16(b[i:]))
			} else {
				u = append(u, binary.LittleEndian.Uint16(b[i:]))
			}
		}
		return string(utf16.Decode(u))
	}
	return strings.TrimPrefix(string(b), "\uFEFF")
}

// triggerKinds lists the trigger element names inside a <Triggers> block.
func triggerKinds(inner string) []string {
	dec := xml.NewDecoder(strings.NewReader("<t>" + inner + "</t>"))
	dec.Strict = false
	depth := 0
	var out []string
	for len(out) < 8 {
		tok, err := dec.Token()
		if err != nil {
			break
		}
		switch t := tok.(type) {
		case xml.StartElement:
			depth++
			if depth == 2 {
				out = append(out, t.Name.Local)
			}
		case xml.EndElement:
			depth--
		}
	}
	return out
}

// ParseTask reduces a task definition to one line: what runs, as whom, whether it
// is enabled and what starts it.
func ParseTask(text string) (string, bool) {
	var t taskXML
	// the text is UTF-8 already: the declaration (which says UTF-16) is dropped so the decoder does not look for another charset
	dec := xml.NewDecoder(strings.NewReader(reXMLDecl.ReplaceAllString(text, "")))
	dec.Strict = false
	if err := dec.Decode(&t); err != nil {
		return "", false
	}
	var cmds []string
	for _, ex := range t.Execs {
		c := strings.TrimSpace(ex.Command)
		if a := strings.TrimSpace(ex.Arguments); a != "" {
			c += " " + a
		}
		cmds = append(cmds, c)
	}
	enabled := "true"
	if strings.EqualFold(strings.TrimSpace(t.Enabled), "false") {
		enabled = "false"
	}
	who := strings.TrimSpace(t.UserID)
	if r := strings.TrimSpace(t.RunLevel); r != "" {
		who += " " + r
	}
	return fmt.Sprintf("run: %s | as: %s | enabled: %s | triggers: %s", strings.Join(cmds, " ; "), who, enabled, strings.Join(triggerKinds(t.Triggers.Inner), ",")), true
}

// readTasks reads the scheduled task definitions below root (Windows:
// %SystemRoot%\System32\Tasks). The task name is the path relative to root.
func (e *engine) readTasks(root string) (Snapshot, []string, []string) {
	snap := Snapshot{}
	var unreadable, notes []string
	files := 0
	// unreadable entries are task names (relative paths, backslash first), so the caller can keep what it knew under them
	nameOf := func(p string) string {
		rel, err := filepath.Rel(root, p)
		if err != nil || rel == "." {
			return `\`
		}
		return `\` + strings.ReplaceAll(rel, "/", `\`)
	}
	var walk func(dir string, depth int)
	walk = func(dir string, depth int) {
		ents, err := os.ReadDir(dir)
		if err != nil {
			unreadable = append(unreadable, nameOf(dir))
			notes = append(notes, "could not list the task folder: "+Truncate(Clean(err.Error()), 120))
			return
		}
		sort.Slice(ents, func(i, j int) bool { return ents[i].Name() < ents[j].Name() })
		for _, de := range ents {
			p := filepath.Join(dir, de.Name())
			if de.IsDir() {
				if depth < maxTaskDepth {
					walk(p, depth+1)
				}
				continue
			}
			if !de.Type().IsRegular() {
				continue
			}
			if files >= maxTaskFiles || len(snap) >= e.maxItems {
				notes = append(notes, "more tasks than the agent's limit: the rest are not watched")
				return
			}
			files++
			fi, err := de.Info()
			if err != nil || fi.Size() > maxTaskFileSize {
				notes = append(notes, "a task definition was too big to read")
				continue
			}
			b, err := os.ReadFile(p)
			if err != nil {
				unreadable = append(unreadable, nameOf(p))
				continue
			}
			name := Truncate(Clean(nameOf(p)), 200)
			text := decodeTaskFile(b)
			summary, ok := ParseTask(text)
			if !ok || !utf8.ValidString(summary) {
				summary = fmt.Sprintf("definition (%d bytes)", len(b))
			}
			shown, red, cut := Display(summary, e.maxValue, e.o.Redact)
			it := Item{Target: "scheduled-tasks", Name: name, Value: shown, Hash: e.hasher.Plain(bytes.TrimSpace(b))}
			if red {
				it.Flags |= FlagRedacted
				it.Hash = e.hasher.Keyed(bytes.TrimSpace(b))
			}
			if cut {
				it.Flags |= FlagTruncated
			}
			snap[itemKey("task", it.Target, it.Name)] = it
		}
	}
	walk(root, 0)
	return snap, unreadable, notes
}
