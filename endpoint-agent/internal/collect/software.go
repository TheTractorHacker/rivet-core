package collect

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"sort"
	"strings"
	"unicode/utf8"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/store"
)

// The installed-software inventory (docs/rmm/PROTOCOL.md 3.2.1). This file is
// platform independent: sanitising, de-duplication, caps, the list hash and the
// delta are pure functions, unit-tested against testdata/software/hash_vectors.json
// (the same vectors the PHP server asserts).

// SoftwareItem is one installed product; its identity is (Source, Name).
type SoftwareItem = api.SoftwareItem

// SoftwareRef names a product that vanished.
type SoftwareRef = api.SoftwareRef

// Limits. The server accepts 5000 items; the agent stays well under it and under
// the 1 MiB check-in body cap (a 600 KiB list leaves room for the metrics, the
// buffered samples and the inventory).
const (
	MaxSoftwareItems     = 3000
	MaxSoftwareBytes     = 600 << 10
	maxSoftwareName      = 200
	maxSoftwareVersion   = 100
	maxSoftwarePublisher = 200
)

// Source names (the closed set the server accepts).
const (
	SrcRegistry   = "registry"
	SrcRegistry32 = "registry32"
	SrcAppx       = "appx"
	SrcDpkg       = "dpkg"
	SrcRPM        = "rpm"
	SrcSnap       = "snap"
	SrcFlatpak    = "flatpak"
)

// SoftwareList is a normalised list: sanitised, de-duplicated, sorted by
// (Source, Name) and within the caps. Truncated is true when it was cut.
type SoftwareList struct {
	Items     []SoftwareItem
	Truncated bool
}

// SoftwareLister is optionally implemented by a Platform. It is deliberately not
// part of the Platform interface so existing fakes keep compiling.
type SoftwareLister interface {
	Software(ctx context.Context) (SoftwareList, error)
}

// StoreAppLister is optionally implemented by a Platform that can also list
// Microsoft Store (appx) packages; the Collector asks only when the operator
// enabled store.Config.SoftwareStoreApps.
type StoreAppLister interface {
	StoreApps(ctx context.Context) ([]SoftwareItem, error)
}

// CleanText is the server's cleanText: runs of control characters
// ([\x00-\x1f\x7f]) become ONE space, invalid UTF-8 is dropped, the text is
// trimmed (spaces only, like PHP trim after the replacement) and cut to max
// characters (runes).
func CleanText(s string, max int) string {
	s = strings.ToValidUTF8(s, "")
	var b strings.Builder
	b.Grow(len(s))
	inCtl := false
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c < 0x20 || c == 0x7f {
			if !inCtl {
				b.WriteByte(' ')
				inCtl = true
			}
			continue
		}
		inCtl = false
		b.WriteByte(c)
	}
	out := strings.Trim(b.String(), " ")
	if utf8.RuneCountInString(out) > max {
		// like the server: trim first, cut last (a cut may leave a trailing space)
		out = string([]rune(out)[:max])
	}
	return out
}

// sanitizeSoftware cleans one item; ok is false when it has no name.
func sanitizeSoftware(it SoftwareItem) (SoftwareItem, bool) {
	it.Name = CleanText(it.Name, maxSoftwareName)
	it.Version = CleanText(it.Version, maxSoftwareVersion)
	it.Publisher = CleanText(it.Publisher, maxSoftwarePublisher)
	it.Source = CleanText(it.Source, 20)
	it.Installed = CleanText(it.Installed, 10)
	return it, it.Name != "" && it.Source != ""
}

func softwareLess(a, b SoftwareItem) bool {
	if a.Source != b.Source {
		return a.Source < b.Source
	}
	return a.Name < b.Name
}

// better reports whether a should win over b when both have the same identity:
// the greater version by byte comparison, then publisher and install date so
// the outcome never depends on the input order.
func better(a, b SoftwareItem) bool {
	if a.Version != b.Version {
		return a.Version > b.Version
	}
	if a.Publisher != b.Publisher {
		return a.Publisher > b.Publisher
	}
	return a.Installed > b.Installed
}

// NormalizeSoftware sanitises, de-duplicates, sorts and caps raw items. A
// previous Truncated flag (an already-capped source) is carried by the caller.
func NormalizeSoftware(raw []SoftwareItem) SoftwareList {
	type key struct{ src, name string }
	best := make(map[key]SoftwareItem, len(raw))
	for _, it := range raw {
		it, ok := sanitizeSoftware(it)
		if !ok {
			continue
		}
		k := key{it.Source, it.Name}
		if cur, have := best[k]; !have || better(it, cur) {
			best[k] = it
		}
	}
	items := make([]SoftwareItem, 0, len(best))
	for _, it := range best {
		items = append(items, it)
	}
	sort.Slice(items, func(i, j int) bool { return softwareLess(items[i], items[j]) })
	var out SoftwareList
	size := 2 // the enclosing []
	for i, it := range items {
		b, err := json.Marshal(it)
		n := len(b) + 1
		if err != nil || i >= MaxSoftwareItems || size+n > MaxSoftwareBytes {
			out.Truncated = true
			items = items[:i]
			break
		}
		size += n
	}
	out.Items = items
	return out
}

// SoftwareHash is the change-detection hash shared byte for byte with the
// server (SoftwareHash::of): sha256 over the bytewise-sorted lines
// "source TAB name TAB version TAB publisher LF", lower-case hex. Install dates
// are not part of it.
func SoftwareHash(items []SoftwareItem) string {
	lines := make([]string, len(items))
	for i, it := range items {
		lines[i] = it.Source + "\t" + it.Name + "\t" + it.Version + "\t" + it.Publisher + "\n"
	}
	sort.Strings(lines)
	h := sha256.New()
	for _, l := range lines {
		h.Write([]byte(l))
	}
	return hex.EncodeToString(h.Sum(nil))
}

// SoftwareDiff compares two normalised lists: changed holds the items that are
// new or whose version/publisher differ in cur (what a delta sends as "items"),
// removed the identities present in prev but not in cur.
func SoftwareDiff(prev, cur []SoftwareItem) (changed []SoftwareItem, removed []SoftwareRef) {
	type key struct{ src, name string }
	old := make(map[key]SoftwareItem, len(prev))
	for _, it := range prev {
		old[key{it.Source, it.Name}] = it
	}
	seen := make(map[key]bool, len(cur))
	changed = []SoftwareItem{}
	for _, it := range cur {
		k := key{it.Source, it.Name}
		seen[k] = true
		if o, ok := old[k]; !ok || o.Version != it.Version || o.Publisher != it.Publisher {
			changed = append(changed, it)
		}
	}
	for _, it := range prev {
		if !seen[key{it.Source, it.Name}] {
			removed = append(removed, SoftwareRef{Source: it.Source, Name: it.Name})
		}
	}
	sort.Slice(removed, func(i, j int) bool {
		if removed[i].Source != removed[j].Source {
			return removed[i].Source < removed[j].Source
		}
		return removed[i].Name < removed[j].Name
	})
	return changed, removed
}

// Software collects the installed software through the platform (when it
// implements SoftwareLister) under the same hard timeout as every other call,
// and returns the normalised list. ErrUnsupported when the platform cannot.
func (c *Collector) Software(ctx context.Context) (SoftwareList, error) {
	l, ok := c.P.(SoftwareLister)
	if !ok {
		return SoftwareList{}, ErrUnsupported
	}
	var cfg store.Config
	if c.Cfg != nil {
		cfg = c.Cfg()
	}
	r, err := call(c, ctx, "software", 0, func(ctx context.Context) (SoftwareList, error) {
		res, err := l.Software(ctx)
		if err != nil {
			return SoftwareList{}, err
		}
		if cfg.SoftwareStoreApps {
			if sa, ok := c.P.(StoreAppLister); ok {
				if apps, aerr := sa.StoreApps(ctx); aerr == nil {
					res.Items = append(append([]SoftwareItem(nil), res.Items...), apps...)
				}
			}
		}
		return res, nil
	})
	if err != nil {
		return SoftwareList{}, err
	}
	n := NormalizeSoftware(r.Items)
	n.Truncated = n.Truncated || r.Truncated
	return n, nil
}

// limitBuffer collects at most max bytes and silently drops the rest.
type limitBuffer struct {
	bytes.Buffer
	max int
}

func (b *limitBuffer) Write(p []byte) (int, error) {
	if room := b.max - b.Len(); room > 0 {
		if len(p) > room {
			b.Buffer.Write(p[:room])
		} else {
			b.Buffer.Write(p)
		}
	}
	return len(p), nil
}
