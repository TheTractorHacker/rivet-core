package patch

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestWindowsIDSyntax(t *testing.T) {
	good := []string{"11111111-2222-3333-4444-555555555555", "abcdef01-2345-6789-abcd-ef0123456789"}
	bad := []string{"", "ABCDEF01-2345-6789-ABCD-EF0123456789", "11111111-2222-3333-4444-55555555555", "11111111-2222-3333-4444-5555555555555",
		"{11111111-2222-3333-4444-555555555555}", "11111111-2222-3333-4444-555555555555\n", " 11111111-2222-3333-4444-555555555555",
		"11111111222233334444555555555555", "1111111g-2222-3333-4444-555555555555", "11111111-2222-3333-4444-555555555555;x"}
	for _, s := range good {
		if !ValidWindowsID(s) {
			t.Errorf("%q must be valid", s)
		}
	}
	for _, s := range bad {
		if ValidWindowsID(s) {
			t.Errorf("%q must be invalid", s)
		}
	}
}

func TestLinuxIDSyntax(t *testing.T) {
	good := []string{"openssl", "libssl3", "g++", "libstdc++6", "python3.11", "libc6:i386", "libc6:amd64", "openssl.x86_64", "kernel-core.x86_64", "a", "0ad",
		strings.Repeat("a", 100), "suse-sle-module-basesystem-15-sp5-2024-1234"}
	bad := []string{"", "-oAPT::Foo", "--force-yes", "a b", "x;rm", "$(x)", "`id`", "a\nb", "a\n", "\nabc", strings.Repeat("a", 101), strings.Repeat("a", 5000),
		"Openssl", "openssl:", ":amd64", "libc6:i386:x", "libc6:" + strings.Repeat("a", 21), "libc6:AMD64", "naïve", "名前", "a|b", "a&b", "a>b", "a<b", "a'b", `a"b`,
		"a\tb", "a\x00b", "../etc/passwd", "/bin/sh", "a/b", "~root", "a*", "a?", "a=b", "@x", "+a", ".a", "_a", "a\\b", "a,b"}
	for _, s := range good {
		if !ValidLinuxID(s) {
			t.Errorf("%q must be valid", s)
		}
	}
	for _, s := range bad {
		if ValidLinuxID(s) {
			t.Errorf("%q must be invalid", s)
		}
	}
}

func TestParseIDsHostileTable(t *testing.T) {
	guid := "11111111-2222-3333-4444-555555555555"
	many := func(n int) string {
		var p []string
		for i := 0; i < n; i++ {
			p = append(p, "pkg"+strings.Repeat("a", i%3)+strings.Repeat("b", i/3+1))
		}
		return strings.Join(p, ",")
	}
	cases := []struct {
		name, goos, in string
		ok             bool
	}{
		{"one", "linux", "openssl", true},
		{"two", "linux", "openssl,libssl3:i386", true},
		{"win one", "windows", guid, true},
		{"empty", "linux", "", false},
		{"empty token", "linux", "a,,b", false},
		{"trailing comma", "linux", "a,", false},
		{"leading dash", "linux", "-oAPT::Foo", false},
		{"option after good", "linux", "openssl,--allow-downgrades", false},
		{"space", "linux", "a b", false},
		{"space after comma", "linux", "a, b", false},
		{"semicolon", "linux", "x;rm", false},
		{"subshell", "linux", "$(x)", false},
		{"newline", "linux", "a\nb", false},
		{"duplicates", "linux", "a,a", false},
		{"unicode", "linux", "pâquet", false},
		{"5000 chars", "linux", strings.Repeat("a", 5000), false},
		{"40 ids", "linux", many(40), true},
		{"41 ids", "linux", many(41), false},
		{"1001 chars", "linux", strings.Repeat("a", 1001), false},
		{"win: linux name", "windows", "openssl", false},
		{"win: upper", "windows", "ABCDEF01-2345-6789-ABCD-EF0123456789", false},
		{"win: dup", "windows", guid + "," + guid, false},
		{"win: braces", "windows", "{" + guid + "}", false},
		{"linux: guid is a valid package-shaped id", "linux", guid, true},
	}
	for _, c := range cases {
		ids, err := ParseIDs(c.goos, c.in)
		if (err == nil) != c.ok {
			t.Errorf("%s: ParseIDs(%q) err=%v want ok=%v", c.name, c.in, err, c.ok)
		}
		if err == nil && len(ids) == 0 {
			t.Errorf("%s: ok but no ids", c.name)
		}
	}
	// the longest allowed list is within the character cap
	if s := many(40); len(s) > MaxIDsChars {
		t.Fatalf("test list too long: %d", len(s))
	}
}

func TestParseInstallParams(t *testing.T) {
	type c struct {
		name, in string
		ok       bool
		mode     string
		min      int
	}
	for _, tc := range []c{
		{"defaults to dry_run", `{"ids":"openssl"}`, true, "dry_run", 60},
		{"install", `{"ids":"openssl","mode":"install","max_minutes":240}`, true, "install", 240},
		{"dry explicit", `{"ids":"a,b","mode":"dry_run","max_minutes":1}`, true, "dry_run", 1},
		{"no params", ``, false, "", 0},
		{"null params", `null`, false, "", 0},
		{"no ids", `{"mode":"install"}`, false, "", 0},
		{"unknown key", `{"ids":"a","reboot":true}`, false, "", 0},
		{"reboot flag", `{"ids":"a","mode":"install","reboot":"yes"}`, false, "", 0},
		{"unknown mode", `{"ids":"a","mode":"force"}`, false, "", 0},
		{"mode case", `{"ids":"a","mode":"Install"}`, false, "", 0},
		{"mode not string", `{"ids":"a","mode":true}`, false, "", 0},
		{"ids not string", `{"ids":["a"]}`, false, "", 0},
		{"minutes 0", `{"ids":"a","max_minutes":0}`, false, "", 0},
		{"minutes 241", `{"ids":"a","max_minutes":241}`, false, "", 0},
		{"minutes float", `{"ids":"a","max_minutes":1.5}`, false, "", 0},
		{"minutes string", `{"ids":"a","max_minutes":"5"}`, false, "", 0},
		{"minutes null", `{"ids":"a","max_minutes":null}`, false, "", 0},
		{"not an object", `["a"]`, false, "", 0},
		{"trailing data", `{"ids":"a"} {"x":1}`, false, "", 0},
		{"hostile id", `{"ids":"-oAPT::Foo"}`, false, "", 0},
	} {
		p, err := ParseInstallParams("linux", json.RawMessage(tc.in))
		if (err == nil) != tc.ok {
			t.Errorf("%s: err=%v", tc.name, err)
			continue
		}
		if err == nil && (p.Mode != tc.mode || p.MaxMinutes != tc.min) {
			t.Errorf("%s: %+v", tc.name, p)
		}
	}
}

func TestParseScanParams(t *testing.T) {
	for _, tc := range []struct {
		name, in string
		ok       bool
		cats     string
		max      int
	}{
		{"absent", ``, true, "", 500},
		{"empty object", `{}`, true, "", 500},
		{"null", `null`, true, "", 500},
		{"all fields", `{"categories":"security,critical","max_items":10}`, true, "security,critical", 10},
		{"empty categories = all", `{"categories":""}`, true, "", 500},
		{"every category", `{"categories":"security,critical,important,definition,feature,driver,optional,other"}`, true, "security,critical,important,definition,feature,driver,optional,other", 500},
		{"duplicate collapses", `{"categories":"security,security"}`, true, "security", 500},
		{"unknown category", `{"categories":"security,all"}`, false, "", 0},
		{"upper case", `{"categories":"Security"}`, false, "", 0},
		{"space", `{"categories":"security, critical"}`, false, "", 0},
		{"empty token", `{"categories":"security,"}`, false, "", 0},
		{"injection", `{"categories":"security;calc"}`, false, "", 0},
		{"long", `{"categories":"` + strings.Repeat("security,", 40) + `"}`, false, "", 0},
		{"max 0", `{"max_items":0}`, false, "", 0},
		{"max 1000", `{"max_items":1000}`, true, "", 1000},
		{"max 1001", `{"max_items":1001}`, false, "", 0},
		{"max negative", `{"max_items":-1}`, false, "", 0},
		{"max big", `{"max_items":99999999999999999999}`, false, "", 0},
		{"unknown key", `{"max_items":5,"cmd":"x"}`, false, "", 0},
	} {
		p, err := ParseScanParams(json.RawMessage(tc.in))
		if (err == nil) != tc.ok {
			t.Errorf("%s: err=%v", tc.name, err)
			continue
		}
		if err == nil && (strings.Join(p.Categories, ",") != tc.cats || p.MaxItems != tc.max) {
			t.Errorf("%s: %+v", tc.name, p)
		}
	}
}

func TestWireNamesAndCapabilities(t *testing.T) {
	if JobScan != "patch_scan" || JobInstall != "patch_install" || CapScan != "patch:scan" || CapInstall != "patch:install" {
		t.Fatal("the wire names are part of the protocol")
	}
}
