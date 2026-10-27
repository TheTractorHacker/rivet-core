package collect

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Pure parsers for the output of the package tools (and of the Windows
// registry / Get-AppxPackage). They never fail on a bad line: the line is
// skipped. No OS access, so they are unit-tested on fixtures everywhere.

func splitLines(b []byte) []string {
	s := strings.ReplaceAll(string(b), "\r", "")
	return strings.Split(s, "\n")
}

// ParseDpkg reads `dpkg-query -W -f='${binary:Package}\t${Version}\t${Maintainer}\t${db:Status-Abbrev}\n'`.
// Only installed packages are kept: status abbreviation "ii " (and the held
// "hi "); removed-but-configured ("rc "), half-installed and unpacked ones are not.
func ParseDpkg(out []byte) []SoftwareItem {
	var items []SoftwareItem
	for _, line := range splitLines(out) {
		f := strings.Split(line, "\t")
		if len(f) < 4 || f[0] == "" {
			continue
		}
		st := f[3]
		if len(st) < 2 || (st[0] != 'i' && st[0] != 'h') || st[1] != 'i' || (len(st) > 2 && st[2] != ' ') {
			continue
		}
		items = append(items, SoftwareItem{Name: f[0], Version: f[1], Publisher: f[2], Source: SrcDpkg})
	}
	return items
}

// ParseRPM reads `rpm -qa --qf '%{NAME}\t%{VERSION}-%{RELEASE}\t%{VENDOR}\t%{INSTALLTIME}\n'`.
// The epoch is ignored, a vendor of "(none)" is empty and the install time (Unix
// seconds) becomes YYYY-MM-DD (UTC). The gpg-pubkey pseudo packages (imported
// keys) are not software.
func ParseRPM(out []byte) []SoftwareItem {
	var items []SoftwareItem
	for _, line := range splitLines(out) {
		f := strings.Split(line, "\t")
		if len(f) < 3 || f[0] == "" || f[0] == "gpg-pubkey" {
			continue
		}
		it := SoftwareItem{Name: f[0], Version: f[1], Publisher: f[2], Source: SrcRPM}
		if it.Publisher == "(none)" {
			it.Publisher = ""
		}
		if len(f) >= 4 {
			if sec, err := strconv.ParseInt(strings.TrimSpace(f[3]), 10, 64); err == nil && sec > 0 {
				it.Installed = time.Unix(sec, 0).UTC().Format("2006-01-02")
			}
		}
		items = append(items, it)
	}
	return items
}

// ParseSnap reads the table of `snap list`: Name Version Rev Tracking Publisher
// Notes. The header and disabled revisions are skipped; the publisher loses the
// verified mark (a trailing check mark, or "**" for starred publishers).
func ParseSnap(out []byte) []SoftwareItem {
	var items []SoftwareItem
	for i, line := range splitLines(out) {
		f := strings.Fields(line)
		if len(f) < 5 || (i == 0 && f[0] == "Name" && f[1] == "Version") {
			continue
		}
		if len(f) >= 6 && strings.Contains(f[5], "disabled") {
			continue // an old, inactive revision of the same snap
		}
		pub := f[4]
		pub = strings.TrimSuffix(pub, "**")
		pub = strings.TrimSuffix(pub, "✓")
		if pub == "-" {
			pub = ""
		}
		items = append(items, SoftwareItem{Name: f[0], Version: f[1], Publisher: pub, Source: SrcSnap})
	}
	return items
}

// ParseFlatpak reads `flatpak list --columns=application,version,origin`
// (tab separated when piped). The origin remote is the publisher. A line with
// whitespace in the application id is not a package (header or noise).
func ParseFlatpak(out []byte) []SoftwareItem {
	var items []SoftwareItem
	for _, line := range splitLines(out) {
		f := strings.Split(line, "\t")
		name := strings.TrimSpace(f[0])
		if name == "" || strings.ContainsAny(name, " \t") {
			continue
		}
		it := SoftwareItem{Name: name, Source: SrcFlatpak}
		if len(f) > 1 {
			it.Version = strings.TrimSpace(f[1])
		}
		if len(f) > 2 {
			it.Publisher = strings.TrimSpace(f[2])
		}
		items = append(items, it)
	}
	return items
}

// ---- Windows (pure parts, tested on Linux) ----

var skipReleaseTypes = []string{"update", "hotfix", "security update", "service pack"}

// RegistryItem decides whether one Uninstall subkey is a user-visible product
// and builds its item. vals holds the key's values as strings (REG_DWORD as
// decimal): DisplayName, DisplayVersion, Publisher, InstallDate (YYYYMMDD),
// SystemComponent, ParentKeyName, ReleaseType, WindowsInstaller, UninstallString...
// Skipped: no DisplayName, SystemComponent=1 (hidden from Programs and
// Features), ParentKeyName set (it is an update of another product) and
// ReleaseType Update, Hotfix, Security Update or Service Pack.
func RegistryItem(source string, vals map[string]string) (SoftwareItem, bool) {
	name := strings.TrimSpace(vals["DisplayName"])
	if name == "" {
		return SoftwareItem{}, false
	}
	if strings.TrimSpace(vals["SystemComponent"]) == "1" {
		return SoftwareItem{}, false
	}
	if strings.TrimSpace(vals["ParentKeyName"]) != "" {
		return SoftwareItem{}, false
	}
	rt := strings.ToLower(strings.TrimSpace(vals["ReleaseType"]))
	for _, s := range skipReleaseTypes {
		if rt == s {
			return SoftwareItem{}, false
		}
	}
	return SoftwareItem{
		Name: name, Version: vals["DisplayVersion"], Publisher: vals["Publisher"],
		Source: source, Installed: registryDate(vals["InstallDate"]),
	}, true
}

// registryDate turns YYYYMMDD into YYYY-MM-DD; anything else (or an impossible
// date) is "".
func registryDate(s string) string {
	s = strings.TrimSpace(s)
	if len(s) != 8 {
		return ""
	}
	t, err := time.Parse("20060102", s)
	if err != nil {
		return ""
	}
	return t.Format("2006-01-02")
}

// AppxCommand is the fixed PowerShell snippet for Microsoft Store packages
// (-AllUsers needs the SYSTEM/administrator context the service runs in). The
// Version is converted to text because PowerShell 5.1 would serialise the
// System.Version object as {Major,Minor,...}; the console is switched to UTF-8
// so non-ASCII names survive.
const AppxCommand = `[Console]::OutputEncoding=[System.Text.Encoding]::UTF8;` +
	`Get-AppxPackage -AllUsers | Select-Object Name,@{n='Version';e={"$($_.Version)"}},Publisher,IsFramework | ConvertTo-Json -Compress`

// ParseAppxJSON reads the AppxCommand output: one object or an array (what
// ConvertTo-Json produces for one or many packages), empty for none. Framework
// packages (runtimes such as VCLibs) are skipped.
func ParseAppxJSON(b []byte) ([]SoftwareItem, error) {
	b = bytes.TrimPrefix(bytes.TrimSpace(b), []byte("\xef\xbb\xbf"))
	b = bytes.TrimSpace(b)
	if len(b) == 0 || string(b) == "null" {
		return nil, nil
	}
	type pkg struct {
		Name        string          `json:"Name"`
		Version     json.RawMessage `json:"Version"`
		Publisher   string          `json:"Publisher"`
		IsFramework bool            `json:"IsFramework"`
	}
	var list []pkg
	if b[0] == '[' {
		if err := json.Unmarshal(b, &list); err != nil {
			return nil, fmt.Errorf("appx json: %w", err)
		}
	} else {
		var one pkg
		if err := json.Unmarshal(b, &one); err != nil {
			return nil, fmt.Errorf("appx json: %w", err)
		}
		list = []pkg{one}
	}
	var items []SoftwareItem
	for _, p := range list {
		if p.IsFramework || strings.TrimSpace(p.Name) == "" {
			continue
		}
		items = append(items, SoftwareItem{Name: p.Name, Version: appxVersion(p.Version), Publisher: p.Publisher, Source: SrcAppx})
	}
	return items, nil
}

func appxVersion(raw json.RawMessage) string {
	var s string
	if json.Unmarshal(raw, &s) == nil {
		return s
	}
	var v struct{ Major, Minor, Build, Revision int }
	if json.Unmarshal(raw, &v) == nil {
		return fmt.Sprintf("%d.%d.%d.%d", v.Major, v.Minor, v.Build, v.Revision)
	}
	return ""
}
