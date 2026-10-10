package collect

import (
	"os"
	"strings"
	"testing"
)

func fixture(t *testing.T, name string) []byte {
	t.Helper()
	b, err := os.ReadFile("testdata/software/" + name)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func find(items []SoftwareItem, name string) (SoftwareItem, bool) {
	for _, i := range items {
		if i.Name == name {
			return i, true
		}
	}
	return SoftwareItem{}, false
}

func TestParseDpkg(t *testing.T) {
	items := ParseDpkg(fixture(t, "dpkg.txt"))
	for _, gone := range []string{"oldconf", "halfinst", "unpacked", "reinst", "notabs-line-here", "two"} {
		if _, ok := find(items, gone); ok {
			t.Errorf("%s must not be listed", gone)
		}
	}
	bash, ok := find(items, "curl")
	if !ok || bash.Version != "8.5.0-2ubuntu10.6" || bash.Source != SrcDpkg || !strings.HasPrefix(bash.Publisher, "Ubuntu Developers") {
		t.Errorf("curl: %+v", bash)
	}
	for _, want := range []string{"held-pkg", "libc6:amd64", "libc6:i386", "fonts-übung", "no-maintainer", "crlf-pkg"} {
		if _, ok := find(items, want); !ok {
			t.Errorf("%s missing", want)
		}
	}
	if nm, _ := find(items, "no-maintainer"); nm.Publisher != "" || nm.Version != "0.1" {
		t.Errorf("%+v", nm)
	}
	if len(items) != 9 { // bash curl held libc6x2 fonts nomaint long crlf
		t.Errorf("%d items", len(items))
	}
	// the very long name survives parsing and is cut by the normaliser
	l := NormalizeSoftware(items)
	for _, i := range l.Items {
		if len(i.Name) > 200*4 {
			t.Errorf("name not cut: %d", len(i.Name))
		}
	}
	if len(l.Items) != 9 {
		t.Errorf("normalised %d", len(l.Items))
	}
	if ParseDpkg(nil) != nil || len(ParseDpkg([]byte("\n\n"))) != 0 {
		t.Error("empty output must give no items")
	}
}

func TestParseRPM(t *testing.T) {
	items := ParseRPM(fixture(t, "rpm.txt"))
	if _, ok := find(items, "gpg-pubkey"); ok {
		t.Error("gpg-pubkey is not software")
	}
	if _, ok := find(items, "short"); ok {
		t.Error("a line without tabs is skipped")
	}
	b, _ := find(items, "bash")
	if b.Version != "5.2.26-3.el10" || b.Publisher != "Red Hat, Inc." || b.Installed != "2024-09-02" || b.Source != SrcRPM {
		t.Errorf("bash %+v", b)
	}
	if h, _ := find(items, "homebuilt"); h.Publisher != "" || h.Installed != "" {
		t.Errorf("(none) vendor and install time: %+v", h)
	}
	if n, ok := find(items, "nodate"); !ok || n.Installed != "" || n.Publisher != "Vendor" {
		t.Errorf("three fields: %+v", n)
	}
	if u, ok := find(items, "Ünïcode"); !ok || u.Publisher != "Äcme" || u.Installed != "" {
		t.Errorf("unicode / zero time: %+v", u)
	}
	// two kernels installed side by side: the normaliser keeps the greater version
	l := NormalizeSoftware(items)
	k, _ := find(l.Items, "kernel")
	if k.Version != "6.12.0-5.el10" {
		t.Errorf("kernel %+v", k)
	}
	n := 0
	for _, i := range l.Items {
		if i.Name == "kernel" {
			n++
		}
	}
	if n != 1 {
		t.Errorf("%d kernel entries", n)
	}
}

func TestParseSnap(t *testing.T) {
	items := ParseSnap(fixture(t, "snap.txt"))
	if _, ok := find(items, "Name"); ok {
		t.Error("header parsed as a snap")
	}
	if len(items) != 5 { // bare core22 firefox(active) hello-world my-local
		t.Fatalf("%d items: %+v", len(items), items)
	}
	ff, _ := find(items, "firefox")
	if ff.Version != "131.0-1" || ff.Publisher != "mozilla" || ff.Source != SrcSnap {
		t.Errorf("firefox (the disabled revision must be skipped): %+v", ff)
	}
	if h, _ := find(items, "hello-world"); h.Publisher != "starcraft" {
		t.Errorf("starred publisher: %+v", h)
	}
	if c, _ := find(items, "core22"); c.Publisher != "canonical" {
		t.Errorf("verified mark: %+v", c)
	}
	if l, _ := find(items, "my-local"); l.Publisher != "" {
		t.Errorf("local snap has no publisher: %+v", l)
	}
	if len(ParseSnap([]byte(""))) != 0 {
		t.Error("empty")
	}
}

func TestParseFlatpak(t *testing.T) {
	items := ParseFlatpak(fixture(t, "flatpak.txt"))
	if _, ok := find(items, "Application"); ok {
		t.Error("header")
	}
	ff, _ := find(items, "org.mozilla.firefox")
	if ff.Version != "131.0.1" || ff.Publisher != "flathub" || ff.Source != SrcFlatpak {
		t.Errorf("%+v", ff)
	}
	if p, _ := find(items, "org.gnome.Platform"); p.Version != "" || p.Publisher != "flathub" {
		t.Errorf("empty version column: %+v", p)
	}
	if n, ok := find(items, "org.example.NoOrigin"); !ok || n.Publisher != "" {
		t.Errorf("missing origin column: %+v", n)
	}
	if _, ok := find(items, "com.example.Üni"); !ok {
		t.Error("unicode id")
	}
	if len(items) != 5 { // firefox platform noorigin uni just-a-name
		t.Errorf("%d items", len(items))
	}
}

func TestRegistryItem(t *testing.T) {
	ok := map[string]string{"DisplayName": "7-Zip 23.01 (x64)", "DisplayVersion": "23.01", "Publisher": "Igor Pavlov",
		"InstallDate": "20240115", "WindowsInstaller": "1", "UninstallString": `MsiExec.exe /X{...}`}
	it, good := RegistryItem(SrcRegistry, ok)
	if !good || it.Name != "7-Zip 23.01 (x64)" || it.Version != "23.01" || it.Publisher != "Igor Pavlov" ||
		it.Installed != "2024-01-15" || it.Source != SrcRegistry {
		t.Fatalf("%+v %v", it, good)
	}
	if it, _ := RegistryItem(SrcRegistry32, ok); it.Source != SrcRegistry32 {
		t.Error("source")
	}
	skips := map[string]map[string]string{
		"no name":           {"DisplayVersion": "1"},
		"blank name":        {"DisplayName": "  "},
		"system component":  {"DisplayName": "X", "SystemComponent": "1"},
		"update of product": {"DisplayName": "X Update", "ParentKeyName": "OperatingSystem"},
		"hotfix":            {"DisplayName": "Hotfix for X", "ReleaseType": "Hotfix"},
		"update":            {"DisplayName": "X", "ReleaseType": "Update"},
		"security update":   {"DisplayName": "X", "ReleaseType": "Security Update"},
		"service pack":      {"DisplayName": "X", "ReleaseType": "Service Pack"},
		"case":              {"DisplayName": "X", "ReleaseType": "service pack"},
	}
	for n, v := range skips {
		if _, good := RegistryItem(SrcRegistry, v); good {
			t.Errorf("%s must be skipped", n)
		}
	}
	// SystemComponent=0 and an ordinary ReleaseType stay
	for n, v := range map[string]map[string]string{
		"syscomp 0":    {"DisplayName": "Y", "SystemComponent": "0"},
		"release type": {"DisplayName": "Y", "ReleaseType": "Application"},
		"empty parent": {"DisplayName": "Y", "ParentKeyName": ""},
	} {
		if _, good := RegistryItem(SrcRegistry, v); !good {
			t.Errorf("%s must be listed", n)
		}
	}
	for in, want := range map[string]string{"20240115": "2024-01-15", "": "", "2024-01-15": "", "20241315": "", "2024011": "", "abcdefgh": "", "20240230": ""} {
		if got := registryDate(in); got != want {
			t.Errorf("registryDate(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestParseAppxJSON(t *testing.T) {
	items, err := ParseAppxJSON(fixture(t, "appx.json"))
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := find(items, "Microsoft.VCLibs.140.00"); ok {
		t.Error("framework packages are skipped")
	}
	if _, ok := find(items, ""); ok {
		t.Error("nameless")
	}
	calc, ok := find(items, "Microsoft.WindowsCalculator")
	if !ok || calc.Version != "11.2407.1.0" || calc.Source != SrcAppx || !strings.HasPrefix(calc.Publisher, "CN=Microsoft") {
		t.Errorf("%+v", calc)
	}
	if o, _ := find(items, "Obj.Version"); o.Version != "1.2.3.4" {
		t.Errorf("a System.Version serialised as an object: %+v", o)
	}
	if l := NormalizeSoftware(items); len(l.Items) != 2 { // calculator (deduped across users) + Obj.Version
		t.Errorf("normalised %+v", l.Items)
	}
	one, err := ParseAppxJSON(fixture(t, "appx_single.json"))
	if err != nil || len(one) != 1 || one[0].Name != "Only.One" {
		t.Errorf("single object with BOM: %+v %v", one, err)
	}
	for _, empty := range []string{"", "  \r\n", "null"} {
		if got, err := ParseAppxJSON([]byte(empty)); err != nil || len(got) != 0 {
			t.Errorf("%q: %v %v", empty, got, err)
		}
	}
	if _, err := ParseAppxJSON([]byte("{not json")); err == nil {
		t.Error("garbage must be an error")
	}
	if _, err := ParseAppxJSON([]byte("[1,2]")); err == nil {
		t.Error("wrong shape must be an error")
	}
}
