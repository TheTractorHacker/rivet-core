package change

import (
	"encoding/binary"
	"errors"
	"fmt"
	"strings"
	"unicode/utf16"
)

// Registry errors the reader maps its API errors to.
var (
	ErrNotExist = errors.New("registry key not found")
	ErrAccess   = errors.New("registry access denied")
)

// RegValue is one registry value as the reader hands it over. A value whose name
// is a SecretName is NEVER read: Secret is set, Raw is nil and Size is the only
// thing known.
type RegValue struct {
	Name   string
	Type   uint32
	Raw    []byte // at most RegReadCap bytes of the data
	Size   int    // the full size of the data
	Secret bool
}

// RegReadCap is the most data bytes the agent reads of one value.
const RegReadCap = 4096

// Registry is the seam to the Windows registry; a fake stands in on Linux.
type Registry interface {
	// SubKeys lists the names of the subkeys of a key.
	SubKeys(path string) ([]string, error)
	// Values lists the values of a key whose name want accepts. It reads the data
	// of a value only when it is not a SecretName; for a secret it reports the
	// name, type and size.
	Values(path string, want func(name string) bool) ([]RegValue, error)
	// Users lists the SIDs of the user hives that are loaded (without _Classes).
	Users() ([]string, error)
}

// Registry value types (winnt.h).
const (
	regNone     = 0
	regSZ       = 1
	regExpandSZ = 2
	regBinary   = 3
	regDWord    = 4
	regDWordBE  = 5
	regLink     = 6
	regMultiSZ  = 7
	regQWord    = 11
)

// RegTypeName names a registry value type.
func RegTypeName(t uint32) string {
	switch t {
	case regNone:
		return "REG_NONE"
	case regSZ:
		return "REG_SZ"
	case regExpandSZ:
		return "REG_EXPAND_SZ"
	case regBinary:
		return "REG_BINARY"
	case regDWord:
		return "REG_DWORD"
	case regDWordBE:
		return "REG_DWORD_BIG_ENDIAN"
	case regLink:
		return "REG_LINK"
	case regMultiSZ:
		return "REG_MULTI_SZ"
	case regQWord:
		return "REG_QWORD"
	}
	return fmt.Sprintf("REG_%d", t)
}

func utf16Text(b []byte) string {
	n := len(b) / 2
	u := make([]uint16, 0, n)
	for i := 0; i < n; i++ {
		u = append(u, binary.LittleEndian.Uint16(b[i*2:]))
	}
	return string(utf16.Decode(u))
}

// RenderValue turns a value's data into the text the agent shows. Binary data is
// described, never printed.
func RenderValue(typ uint32, raw []byte, size int) string {
	switch typ {
	case regSZ, regExpandSZ:
		return strings.TrimRight(utf16Text(raw), "\x00")
	case regMultiSZ:
		parts := strings.Split(strings.TrimRight(utf16Text(raw), "\x00"), "\x00")
		return strings.Join(parts, " | ")
	case regDWord:
		if len(raw) >= 4 {
			return fmt.Sprintf("%d", binary.LittleEndian.Uint32(raw))
		}
	case regDWordBE:
		if len(raw) >= 4 {
			return fmt.Sprintf("%d", binary.BigEndian.Uint32(raw))
		}
	case regQWord:
		if len(raw) >= 8 {
			return fmt.Sprintf("%d", binary.LittleEndian.Uint64(raw))
		}
	}
	return fmt.Sprintf("%s (%d bytes)", RegTypeName(typ), size)
}

// readRegistry reads one registry watch into a snapshot. unreadable lists the key
// prefixes it could not look into (access denied, or a read error): the caller
// keeps what it knew under them instead of reporting it removed.
func (e *engine) readRegistry(w Watch) (Snapshot, []string, []string) {
	snap := Snapshot{}
	var unreadable, notes []string
	rd := &regReader{e: e, w: w, snap: snap}
	for _, p := range w.Paths {
		for _, key := range e.expandKey(p.Key) {
			rd.walk(key, p, p.Depth)
		}
	}
	unreadable, notes = rd.unreadable, rd.notes
	return snap, unreadable, notes
}

// expandKey expands HKU\* to every loaded user hive.
func (e *engine) expandKey(key string) []string {
	up := strings.ToUpper(key)
	if !(strings.HasPrefix(up, `HKU\*\`) || strings.HasPrefix(up, `HKEY_USERS\*\`)) {
		return []string{key}
	}
	rest := key[strings.Index(key, `*`)+1:]
	users, err := e.o.Registry.Users()
	if err != nil {
		return nil
	}
	var out []string
	for i, sid := range users {
		if i >= maxUserHives {
			break
		}
		if reUserSID.MatchString(sid) {
			out = append(out, `HKU\`+sid+rest)
		}
	}
	return out
}

const (
	maxUserHives   = 64
	maxKeysPerRead = 20000
)

type regReader struct {
	e          *engine
	w          Watch
	snap       Snapshot
	unreadable []string
	notes      []string
	keys       int
	full       bool
}

func (r *regReader) walk(path string, p PathSpec, depth int) {
	if r.full {
		return
	}
	// every key visited is checked again: recursion must never step into a credential key
	if !RegistryKeyAllowed(path) {
		r.notes = append(r.notes, "a key was skipped by this agent's policy")
		return
	}
	r.keys++
	if r.keys > maxKeysPerRead || len(r.snap) >= r.e.maxItems {
		r.full = true
		r.notes = append(r.notes, fmt.Sprintf("the watch is bigger than the agent's limit (%d keys, %d items): narrow it", maxKeysPerRead, r.e.maxItems))
		return
	}
	want := func(name string) bool { return len(p.Values) == 0 || containsFold(p.Values, name) }
	vals, err := r.e.o.Registry.Values(path, want)
	switch {
	case errors.Is(err, ErrNotExist):
		return
	case err != nil:
		r.unreadable = append(r.unreadable, path)
		r.notes = append(r.notes, "could not read a key: "+Truncate(Clean(err.Error()), 120))
		return
	}
	for _, v := range vals {
		if len(r.snap) >= r.e.maxItems {
			r.full = true
			r.notes = append(r.notes, "the watch is bigger than the agent's item limit: narrow it")
			return
		}
		it := r.e.regItem(path, v)
		r.snap[itemKey("registry", it.Target, it.Name)] = it
	}
	if depth <= 0 {
		return
	}
	subs, err := r.e.o.Registry.SubKeys(path)
	switch {
	case errors.Is(err, ErrNotExist):
		return
	case err != nil:
		r.unreadable = append(r.unreadable, path)
		r.notes = append(r.notes, "could not list the subkeys of a key: "+Truncate(Clean(err.Error()), 120))
		return
	}
	for _, s := range subs {
		r.walk(path+`\`+s, p, depth-1)
	}
}

func containsFold(list []string, name string) bool {
	for _, x := range list {
		if strings.EqualFold(x, name) {
			return true
		}
	}
	return false
}

// regItem turns a value into an item: the name "(Default)" for the unnamed value,
// a masked text and a keyed hash for a secret, a description for binary data,
// otherwise the redacted, cut text with a hash of the whole text that was read.
func (e *engine) regItem(key string, v RegValue) Item {
	name := v.Name
	if name == "" {
		name = "(Default)"
	}
	it := Item{Target: key, Name: name}
	typ := RegTypeName(v.Type)
	if v.Secret || SecretName(v.Name) {
		it.Value, it.Flags = Mask, FlagRedacted
		it.Hash = e.hasher.Keyed([]byte(fmt.Sprintf("%s\x00%s\x00%d", strings.ToLower(v.Name), typ, v.Size)))
		return it
	}
	text := RenderValue(v.Type, v.Raw, v.Size)
	shown, red, cut := Display(text, e.maxValue, e.o.Redact)
	it.Value = shown
	if cut || v.Size > len(v.Raw) {
		it.Flags |= FlagTruncated
	}
	body := append([]byte(typ+"\x00"), v.Raw...)
	if red {
		it.Flags |= FlagRedacted
		it.Hash = e.hasher.Keyed(body) // a value that held a secret pattern is never hashed in the clear
	} else {
		it.Hash = e.hasher.Plain(append(body, []byte(fmt.Sprintf("\x00%d", v.Size))...))
	}
	return it
}

// NotifyRoot is a key to listen on with RegNotifyChangeKeyValue (Windows only; the
// poll is always the source of truth).
type NotifyRoot struct {
	Key     string
	Subtree bool
}
