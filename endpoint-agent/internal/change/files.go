package change

import (
	"bytes"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"unicode/utf8"
)

// Limits of the file reader.
const (
	// MaxFileBytes is how much of one file is read and hashed; a bigger file is
	// hashed on its first MaxFileBytes plus its size and flagged truncated.
	MaxFileBytes    = 1 << 20
	maxDirEntries   = 500
	maxFilesPerRead = 2000
)

// readFiles reads one file watch into a snapshot. unreadable lists the logical
// paths it could not look into; notes carry anything worth showing the operator.
func (e *engine) readFiles(w Watch) (Snapshot, []string, []string) {
	// a CUSTOM watch is hash-only: the lines of an arbitrary file an administrator picked are not captured (only the presets name files whose lines are worth keeping)
	fr := &fileReader{e: e, snap: Snapshot{}, keepLines: !strings.HasPrefix(w.ID, "custom.")}
	for _, p := range w.Paths {
		fr.walk(p.Path, p, max(p.Depth, 0), true)
	}
	return fr.snap, fr.unreadable, fr.notes
}

type fileReader struct {
	e          *engine
	snap       Snapshot
	unreadable []string
	notes      []string
	files      int
	skipped    int
	keepLines  bool
	full       bool
}

func (f *fileReader) osPath(logical string) string {
	if f.e.o.MapPath != nil {
		return f.e.o.MapPath(logical)
	}
	return logical
}

func (f *fileReader) join(dir, name string) string {
	if strings.Contains(dir, `\`) && !strings.Contains(dir, "/") {
		return strings.TrimRight(dir, `\`) + `\` + name
	}
	return strings.TrimRight(dir, "/") + "/" + name
}

// walk reads a path. top is true for the path named in the watch; levels is how
// many folder levels below it are still to be read (a folder given as the path is
// read at least one level).
func (f *fileReader) walk(logical string, p PathSpec, levels int, top bool) {
	if f.full {
		return
	}
	if !FileAllowed(logical, f.e.extraRoots()) {
		f.skipped++
		return
	}
	op := f.osPath(logical)
	fi, err := os.Lstat(op)
	if err != nil {
		if !os.IsNotExist(err) {
			f.unreadable = append(f.unreadable, logical)
			f.notes = append(f.notes, "could not read "+Truncate(Clean(err.Error()), 120))
		}
		return
	}
	switch {
	case fi.Mode()&os.ModeSymlink != 0:
		f.add(f.linkItem(logical, op))
	case fi.Mode().IsRegular():
		if top || matchName(p.Match, filepath.Base(op)) {
			if it, ok := f.fileItem(logical, op, fi); ok {
				f.add(it)
			}
		}
	case fi.IsDir():
		if levels < 1 {
			levels = 1
		}
		f.dir(logical, op, p, levels)
	}
}

func (f *fileReader) dir(logical, op string, p PathSpec, levels int) {
	ents, err := os.ReadDir(op)
	if err != nil {
		f.unreadable = append(f.unreadable, logical)
		f.notes = append(f.notes, "could not list a folder: "+Truncate(Clean(err.Error()), 120))
		return
	}
	if len(ents) > maxDirEntries {
		ents = ents[:maxDirEntries]
		f.notes = append(f.notes, fmt.Sprintf("a folder has more than %d entries: the rest are not watched", maxDirEntries))
	}
	for _, de := range ents {
		name := de.Name()
		child := f.join(logical, name)
		if de.IsDir() {
			if levels > 1 {
				f.walk(child, p, levels-1, false)
			}
			continue
		}
		if de.Type()&os.ModeSymlink == 0 && !de.Type().IsRegular() {
			continue // devices, sockets, pipes
		}
		if !matchName(p.Match, name) {
			continue
		}
		f.walk(child, p, 0, false)
	}
}

func (f *fileReader) add(it Item) {
	if f.files >= maxFilesPerRead || len(f.snap) >= f.e.maxItems {
		f.full = true
		f.notes = append(f.notes, "the watch is bigger than the agent's file limit: narrow it")
		return
	}
	f.files++
	f.snap[itemKey("file", it.Target, it.Name)] = it
}

func (f *fileReader) linkItem(logical, op string) Item {
	target, err := os.Readlink(op)
	if err != nil {
		target = "?"
	}
	text, red, cut := Display("-> "+target, f.e.maxValue, f.e.o.Redact)
	it := Item{Target: logical, Value: text, Hash: f.e.hasher.Plain([]byte("link\x00" + target)), Meta: "link"}
	if red {
		it.Flags |= FlagRedacted
	}
	if cut {
		it.Flags |= FlagTruncated
	}
	return it
}

func (f *fileReader) fileItem(logical, op string, lfi os.FileInfo) (Item, bool) {
	it := Item{Target: logical}
	unreadable := func(why string) (Item, bool) {
		f.unreadable = append(f.unreadable, logical)
		f.notes = append(f.notes, why)
		return it, false // the caller keeps what it knew about this file
	}
	file, err := os.Open(op)
	if err != nil {
		return unreadable("could not open a file: " + Truncate(Clean(err.Error()), 120))
	}
	defer file.Close()
	// the file we opened must be the file we looked at (a symlink swapped in between is refused)
	if ofi, err := file.Stat(); err != nil || !os.SameFile(lfi, ofi) {
		return unreadable("a file changed identity while it was being read")
	}
	data, err := io.ReadAll(io.LimitReader(file, MaxFileBytes+1))
	if err != nil {
		return unreadable("could not read a file: " + Truncate(Clean(err.Error()), 120))
	}
	size := lfi.Size()
	it.Size = size
	it.Meta = fileMeta(lfi)
	if len(data) > MaxFileBytes {
		data = data[:MaxFileBytes]
		it.Flags |= FlagTruncated
		it.Meta += fmt.Sprintf(" size=%d", size)
		it.Hash = f.e.hasher.Plain(append(data, []byte(fmt.Sprintf("\x00%d", size))...))
		it.Value = fmt.Sprintf("%d bytes (only the first %d read)", size, MaxFileBytes)
		return it, true
	}
	it.Hash = f.e.hasher.Plain(data)
	if lines, red, ok := f.e.snapshotLines(op, data); ok && f.keepLines {
		it.Lines = lines
		if red {
			it.Flags |= FlagRedacted
		}
	} else {
		it.Value = fmt.Sprintf("%d bytes", size)
	}
	return it, true
}

// snapshotLines keeps the lines of a small text file for the diff summary. Each
// line is cut and redacted BEFORE it is stored, so the snapshot never holds a
// secret the file held; a /etc/passwd style password field is dropped whatever it
// looks like. A binary or big file keeps no lines.
func (e *engine) snapshotLines(path string, data []byte) (lines []string, redacted, ok bool) {
	if len(data) > maxDiffBytes || bytes.IndexByte(data[:min(len(data), 8192)], 0) >= 0 || !utf8.Valid(data) {
		return nil, false, false
	}
	base := strings.ToLower(filepath.Base(path))
	raw := strings.Split(strings.ReplaceAll(string(data), "\r\n", "\n"), "\n")
	if len(raw) > 0 && raw[len(raw)-1] == "" {
		raw = raw[:len(raw)-1]
	}
	if len(raw) > maxLines {
		return nil, false, false
	}
	sudoers := base == "sudoers" || strings.EqualFold(filepath.Base(filepath.Dir(path)), "sudoers.d")
	out := make([]string, 0, len(raw))
	inPEM := false
	for _, l := range raw {
		l = Clean(l)
		// a PEM block is masked from BEGIN to END (not only its first line), and a long run of base64 on a line of its own is never kept
		if inPEM {
			if strings.Contains(l, "-----END ") {
				inPEM = false
			}
			redacted = true
			continue
		}
		if strings.Contains(l, "-----BEGIN ") && strings.Contains(l, "PRIVATE KEY") && !strings.Contains(l, "-----END ") {
			inPEM, redacted = true, true
			out = append(out, Mask)
			continue
		}
		if reBase64Line.MatchString(l) {
			redacted = true
			out = append(out, Mask)
			continue
		}
		if base == "passwd" || base == "group" {
			l = maskPasswdField(l)
		}
		if sudoers {
			// NOPASSWD: and PASSWD: are sudoers tags, not secrets: the redactor must not take "NOPASSWD: ALL" for a password
			l = sudoTags.Replace(l)
		}
		s, red, _ := Display(l, maxLineBytes, e.o.Redact)
		if sudoers {
			s = strings.ReplaceAll(s, "\uE000", ":")
		}
		redacted = redacted || red || strings.Contains(l, Mask)
		out = append(out, s)
	}
	return out, redacted, true
}

var reBase64Line = regexp.MustCompile(`^[A-Za-z0-9+/=]{40,}$`)

var sudoTags = strings.NewReplacer("NOPASSWD:", "NOPASSWD\uE000", "PASSWD:", "PASSWD\uE000")

// maskPasswdField replaces the second field of an /etc/passwd or /etc/group line
// unless it is the usual placeholder: a hash that landed there is never kept.
func maskPasswdField(line string) string {
	f := strings.SplitN(line, ":", 3)
	if len(f) < 3 {
		return line
	}
	switch f[1] {
	case "", "x", "*", "!", "!!":
		return line
	}
	return f[0] + ":" + Mask + ":" + f[2]
}

// sortedKeys is used by tests and the engine for stable iteration.
func sortedKeys(m Snapshot) []string {
	k := make([]string, 0, len(m))
	for key := range m {
		k = append(k, key)
	}
	sort.Strings(k)
	return k
}
