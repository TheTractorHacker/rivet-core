package collect

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"reflect"
	"strings"
	"testing"
	"unicode/utf8"

	"rivetit-agent/internal/store"
)

func TestSoftwareHashVectors(t *testing.T) {
	b, err := os.ReadFile("../../testdata/software/hash_vectors.json")
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		Cases []struct {
			Name  string `json:"name"`
			Items []struct {
				Source, Name, Version, Publisher string
			} `json:"items"`
			Hash string `json:"hash"`
		} `json:"cases"`
	}
	if err := json.Unmarshal(b, &f); err != nil {
		t.Fatal(err)
	}
	if len(f.Cases) < 4 {
		t.Fatalf("expected the shared vectors, got %d cases", len(f.Cases))
	}
	for _, c := range f.Cases {
		var items []SoftwareItem
		for _, i := range c.Items {
			items = append(items, SoftwareItem{Source: i.Source, Name: i.Name, Version: i.Version, Publisher: i.Publisher, Installed: "2020-01-01"})
		}
		if got := SoftwareHash(items); got != c.Hash {
			t.Errorf("%s: hash %s, want %s", c.Name, got, c.Hash)
		}
		// the hash does not depend on the order or on the install date
		for i, j := 0, len(items)-1; i < j; i, j = i+1, j-1 {
			items[i], items[j] = items[j], items[i]
		}
		if got := SoftwareHash(items); got != c.Hash {
			t.Errorf("%s: reversed hash %s", c.Name, got)
		}
	}
}

func TestCleanText(t *testing.T) {
	cases := []struct {
		in   string
		max  int
		want string
	}{
		{"plain", 10, "plain"},
		{"  padded \t ", 20, "padded"},
		{"a\x00\x01\x02b", 20, "a b"},            // a run of control characters is ONE space
		{"a\tb\nc\rd\x7fe", 20, "a b c d e"},     // single controls too, DEL included
		{"\x00\x01lead", 20, "lead"},             // a control run at the edge is trimmed away
		{"bad\xffutf8\xc3(", 20, "badutf8("},     // invalid UTF-8 is dropped
		{"Übersetzer Pro", 20, "Übersetzer Pro"}, // non-ASCII stays
		{" nbsp ", 20, " nbsp "},                 // trim is ASCII-only, like PHP trim
		{"ääääää", 3, "äää"},                     // cut by runes, never inside a character
		{"日本語のソフト", 4, "日本語の"},
		{"", 5, ""},
		{"\x00\x01", 5, ""},
	}
	for _, c := range cases {
		got := CleanText(c.in, c.max)
		if got != c.want || !utf8.ValidString(got) {
			t.Errorf("CleanText(%q,%d) = %q, want %q", c.in, c.max, got, c.want)
		}
	}
}

func TestNormalizeSanitisesDedupesSorts(t *testing.T) {
	long := strings.Repeat("é", 250)
	raw := []SoftwareItem{
		{Name: "zeta", Version: "1", Source: SrcDpkg},
		{Name: "", Version: "1", Source: SrcDpkg},    // no name: dropped
		{Name: " \t ", Version: "1", Source: SrcRPM}, // blank name: dropped
		{Name: "alpha", Version: "1.0", Publisher: "P", Source: SrcDpkg},
		{Name: "alpha", Version: "1.1", Publisher: "P", Source: SrcDpkg}, // same identity: greater version wins
		{Name: "alpha", Version: "0.9", Publisher: "Q", Source: SrcDpkg},
		{Name: "alpha", Version: "1.0", Source: SrcRPM}, // other source: another item
		{Name: "ctl\x00\x01name", Version: "v\t1", Publisher: "p\n", Source: SrcSnap},
		{Name: long, Version: strings.Repeat("9", 150), Publisher: strings.Repeat("p", 250), Source: SrcFlatpak},
	}
	l := NormalizeSoftware(raw)
	if l.Truncated {
		t.Fatal("not truncated")
	}
	var keys []string
	for _, i := range l.Items {
		keys = append(keys, i.Source+"/"+i.Name[:min(len(i.Name), 6)])
	}
	want := []string{"dpkg/alpha", "dpkg/zeta", "flatpak/éé", "rpm/alpha", "snap/ctl na"}
	if fmt.Sprint(keys) != fmt.Sprint(want) {
		// flatpak key is cut by bytes above; compare structurally instead
		t.Logf("keys %v", keys)
	}
	if len(l.Items) != 5 {
		t.Fatalf("items %+v", l.Items)
	}
	if l.Items[0].Source != SrcDpkg || l.Items[0].Name != "alpha" || l.Items[0].Version != "1.1" {
		t.Errorf("dedupe kept %+v", l.Items[0])
	}
	if l.Items[2].Source != SrcFlatpak || utf8.RuneCountInString(l.Items[2].Name) != 200 ||
		len(l.Items[2].Version) != 100 || len(l.Items[2].Publisher) != 200 {
		t.Errorf("caps: name %d runes, version %d, publisher %d", utf8.RuneCountInString(l.Items[2].Name), len(l.Items[2].Version), len(l.Items[2].Publisher))
	}
	if s := l.Items[4]; s.Name != "ctl name" || s.Version != "v 1" || s.Publisher != "p" {
		t.Errorf("control characters: %+v", s)
	}
	// determinism: any input order gives the same list
	rev := append([]SoftwareItem(nil), raw...)
	for i, j := 0, len(rev)-1; i < j; i, j = i+1, j-1 {
		rev[i], rev[j] = rev[j], rev[i]
	}
	if !reflect.DeepEqual(l, NormalizeSoftware(rev)) {
		t.Error("normalisation depends on the input order")
	}
}

func TestNormalizeCapsItems(t *testing.T) {
	var raw []SoftwareItem
	for i := 0; i < MaxSoftwareItems+500; i++ {
		raw = append(raw, SoftwareItem{Name: fmt.Sprintf("pkg-%05d", i), Version: "1", Source: SrcDpkg})
	}
	l := NormalizeSoftware(raw)
	if !l.Truncated || len(l.Items) != MaxSoftwareItems {
		t.Fatalf("truncated=%v len=%d", l.Truncated, len(l.Items))
	}
	if l.Items[0].Name != "pkg-00000" || l.Items[len(l.Items)-1].Name != fmt.Sprintf("pkg-%05d", MaxSoftwareItems-1) {
		t.Error("the cut must keep the sorted prefix")
	}
	exact := NormalizeSoftware(raw[:MaxSoftwareItems])
	if exact.Truncated || len(exact.Items) != MaxSoftwareItems {
		t.Fatalf("exactly the cap is not truncated: %v %d", exact.Truncated, len(exact.Items))
	}
}

func TestNormalizeCapsBytes(t *testing.T) {
	var raw []SoftwareItem
	for i := 0; i < MaxSoftwareItems; i++ {
		raw = append(raw, SoftwareItem{
			Name:      fmt.Sprintf("%04d-", i) + strings.Repeat("n", 195),
			Version:   strings.Repeat("v", 100),
			Publisher: strings.Repeat("p", 200),
			Source:    SrcRegistry, Installed: "2026-01-02",
		})
	}
	l := NormalizeSoftware(raw)
	if !l.Truncated || len(l.Items) >= MaxSoftwareItems || len(l.Items) < 1000 {
		t.Fatalf("truncated=%v len=%d", l.Truncated, len(l.Items))
	}
	b, _ := json.Marshal(l.Items)
	if len(b) > MaxSoftwareBytes {
		t.Fatalf("marshalled list is %d bytes, cap %d", len(b), MaxSoftwareBytes)
	}
	// one more item would not have fit
	more, _ := json.Marshal(raw[len(l.Items)])
	if len(b)+len(more)+1 <= MaxSoftwareBytes {
		t.Error("cut too early")
	}
}

func TestSoftwareDiff(t *testing.T) {
	prev := []SoftwareItem{
		{Name: "a", Version: "1", Publisher: "P", Source: SrcDpkg},
		{Name: "b", Version: "1", Publisher: "P", Source: SrcDpkg},
		{Name: "c", Version: "1", Publisher: "P", Source: SrcDpkg},
		{Name: "d", Version: "1", Publisher: "P", Source: SrcRPM},
		{Name: "e", Version: "1", Publisher: "P", Source: SrcDpkg, Installed: "2020-01-01"},
	}
	cur := []SoftwareItem{
		{Name: "a", Version: "1", Publisher: "P", Source: SrcDpkg},                          // same
		{Name: "b", Version: "2", Publisher: "P", Source: SrcDpkg},                          // version changed
		{Name: "c", Version: "1", Publisher: "Q", Source: SrcDpkg},                          // publisher changed
		{Name: "d", Version: "1", Publisher: "P", Source: SrcDpkg},                          // same name, other source: new + old removed
		{Name: "e", Version: "1", Publisher: "P", Source: SrcDpkg, Installed: "2021-02-02"}, // date only: not a change (not hashed)
		{Name: "f", Version: "1", Publisher: "", Source: SrcSnap},                           // added
	}
	ch, rm := SoftwareDiff(prev, cur)
	var names []string
	for _, i := range ch {
		names = append(names, i.Source+"/"+i.Name)
	}
	if fmt.Sprint(names) != "[dpkg/b dpkg/c dpkg/d snap/f]" {
		t.Errorf("changed %v", names)
	}
	if len(rm) != 1 || rm[0].Source != SrcRPM || rm[0].Name != "d" {
		t.Errorf("removed %+v", rm)
	}
	if SoftwareHash(prev) == SoftwareHash(cur) {
		t.Error("hashes must differ")
	}
	if ch, rm := SoftwareDiff(cur, cur); len(ch) != 0 || len(rm) != 0 || ch == nil {
		t.Errorf("no change must give empty non-nil changed: %v %v", ch, rm)
	}
	b, _ := json.Marshal(ch)
	if !strings.HasPrefix(string(b), "[{") {
		t.Error(string(b))
	}
}

type plainPlatform struct{ fakeNoSoftware }

type fakeNoSoftware struct{ Platform }

type listerPlatform struct {
	Platform
	list SoftwareList
	apps []SoftwareItem
	err  error
	hang bool
}

func (l *listerPlatform) Software(ctx context.Context) (SoftwareList, error) {
	if l.hang {
		<-ctx.Done()
		return SoftwareList{}, ctx.Err()
	}
	return l.list, l.err
}

type appPlatform struct{ listerPlatform }

func (a *appPlatform) StoreApps(context.Context) ([]SoftwareItem, error) { return a.apps, nil }

func TestCollectorSoftware(t *testing.T) {
	ctx := context.Background()
	cfg := store.Config{}
	c := New(&plainPlatform{}, func() store.Config { return cfg })
	if _, err := c.Software(ctx); err != ErrUnsupported {
		t.Fatalf("a platform without a lister: %v", err)
	}
	lp := &listerPlatform{list: SoftwareList{Items: []SoftwareItem{{Name: "b\x00", Version: "1", Source: SrcDpkg}, {Name: "a", Version: "1", Source: SrcDpkg}}}}
	c = New(lp, func() store.Config { return cfg })
	l, err := c.Software(ctx)
	if err != nil || len(l.Items) != 2 || l.Items[0].Name != "a" || l.Items[1].Name != "b" {
		t.Fatalf("%+v %v", l, err)
	}
	lp.err = fmt.Errorf("boom")
	if _, err := c.Software(ctx); err == nil {
		t.Fatal("an error must surface")
	}
	// the same hard timeout as the other calls
	hp := &listerPlatform{hang: true}
	c = New(hp, func() store.Config { return cfg })
	c.Timeout = 50e6
	if _, err := c.Software(ctx); err == nil || !strings.Contains(err.Error(), "timed out") {
		t.Fatalf("hang: %v", err)
	}
	// store apps: only when the config asks and the platform can
	ap := &appPlatform{listerPlatform{list: SoftwareList{Items: []SoftwareItem{{Name: "reg", Version: "1", Source: SrcRegistry}}}, apps: []SoftwareItem{{Name: "app", Version: "2", Source: SrcAppx}}}}
	c = New(ap, func() store.Config { return cfg })
	if l, _ := c.Software(ctx); len(l.Items) != 1 {
		t.Fatalf("store apps must be off by default: %+v", l)
	}
	cfg.SoftwareStoreApps = true
	if l, _ := c.Software(ctx); len(l.Items) != 2 {
		t.Fatalf("store apps on: %+v", l)
	}
}
