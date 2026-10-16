package patch

import (
	"bytes"
	"encoding/json"
	"errors"
	"regexp"
	"strings"
)

// Id syntax per manager, exactly the contract's. Go's $ (without the m flag) matches only at the very end of the text, so a trailing newline fails.
var (
	winIDRe   = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)
	linuxIDRe = regexp.MustCompile(`^[a-z0-9][a-z0-9+._-]{0,99}(:[a-z0-9-]{1,20})?$`)
	// zypperNameRe is the syntax of a patch name as zypper itself prints it (mixed case: openSUSE-SLE-15.5-2024-1234). It is only applied to text
	// that came out of zypper, never to what the server sent (the server's ids are the lower-case forms).
	zypperNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9+._-]{0,99}$`)
)

// ValidWindowsID reports whether s is a lower-case UpdateID GUID.
func ValidWindowsID(s string) bool { return winIDRe.MatchString(s) }

// ValidLinuxID reports whether s is a package id (name, name:arch, or the dnf form name.arch).
func ValidLinuxID(s string) bool { return linuxIDRe.MatchString(s) }

var scanCategories = map[string]bool{"security": true, "critical": true, "important": true, "definition": true, "feature": true, "driver": true, "optional": true, "other": true}

// ScanParams are the validated parameters of patch_scan.
type ScanParams struct {
	Categories []string // empty = all
	MaxItems   int
}

// InstallParams are the validated parameters of patch_install.
type InstallParams struct {
	IDs        []string
	Mode       string // "dry_run" | "install"
	MaxMinutes int
}

var errParams = errors.New(DetailInvalidParams)

// flatParams decodes the params object, refusing any key outside allowed. Absent, null and {} are the empty object.
func flatParams(raw json.RawMessage, allowed ...string) (map[string]json.RawMessage, error) {
	raw = bytes.TrimSpace(raw)
	m := map[string]json.RawMessage{}
	if len(raw) == 0 || string(raw) == "null" {
		return m, nil
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	if err := dec.Decode(&m); err != nil {
		return nil, errParams
	}
	if dec.More() {
		return nil, errParams
	}
	ok := map[string]bool{}
	for _, k := range allowed {
		ok[k] = true
	}
	for k := range m {
		if !ok[k] {
			return nil, errParams
		}
	}
	return m, nil
}

func paramString(m map[string]json.RawMessage, k string) (string, bool, error) {
	v, has := m[k]
	if !has {
		return "", false, nil
	}
	var s string
	if err := json.Unmarshal(v, &s); err != nil {
		return "", false, errParams
	}
	return s, true, nil
}

// paramInt reads a whole number in [lo,hi]; a missing key gives def. A float, a string or a number out of range is refused (the safest reading of
// "int 1..1000": clamping would hide a server bug).
func paramInt(m map[string]json.RawMessage, k string, lo, hi, def int) (int, error) {
	v, has := m[k]
	if !has {
		return def, nil
	}
	var n json.Number
	dec := json.NewDecoder(bytes.NewReader(v))
	dec.UseNumber()
	if err := dec.Decode(&n); err != nil {
		return 0, errParams
	}
	i, err := n.Int64()
	if err != nil || i < int64(lo) || i > int64(hi) {
		return 0, errParams
	}
	return int(i), nil
}

// ParseCategories validates the comma separated category filter ("" = all). Tokens are exact lower-case words of the allow-list; a duplicate is
// collapsed, an empty or unknown token refuses the whole value.
func ParseCategories(s string) ([]string, error) {
	if s == "" {
		return nil, nil
	}
	if len(s) > 200 {
		return nil, errParams
	}
	seen := map[string]bool{}
	var out []string
	for _, t := range strings.Split(s, ",") {
		if !scanCategories[t] {
			return nil, errParams
		}
		if !seen[t] {
			seen[t] = true
			out = append(out, t)
		}
	}
	return out, nil
}

// ParseScanParams validates the patch_scan parameters.
func ParseScanParams(raw json.RawMessage) (ScanParams, error) {
	m, err := flatParams(raw, "categories", "max_items")
	if err != nil {
		return ScanParams{}, err
	}
	s, _, err := paramString(m, "categories")
	if err != nil {
		return ScanParams{}, err
	}
	cats, err := ParseCategories(s)
	if err != nil {
		return ScanParams{}, err
	}
	n, err := paramInt(m, "max_items", 1, MaxMaxItems, DefaultMaxItems)
	if err != nil {
		return ScanParams{}, err
	}
	return ScanParams{Categories: cats, MaxItems: n}, nil
}

// ParseIDs validates the id list for goos: 1..MaxIDs ids, no duplicates, the whole text at most MaxIDsChars, each id in the syntax of the OS.
func ParseIDs(goos, s string) ([]string, error) {
	if s == "" || len(s) > MaxIDsChars {
		return nil, errParams
	}
	parts := strings.Split(s, ",")
	if len(parts) > MaxIDs {
		return nil, errParams
	}
	valid := ValidLinuxID
	if goos == "windows" {
		valid = ValidWindowsID
	}
	seen := map[string]bool{}
	for _, id := range parts {
		if !valid(id) || seen[id] {
			return nil, errParams
		}
		seen[id] = true
	}
	return parts, nil
}

// ParseInstallParams validates the patch_install parameters. The mode defaults to dry_run when absent.
func ParseInstallParams(goos string, raw json.RawMessage) (InstallParams, error) {
	m, err := flatParams(raw, "ids", "mode", "max_minutes")
	if err != nil {
		return InstallParams{}, err
	}
	ids, _, err := paramString(m, "ids")
	if err != nil {
		return InstallParams{}, err
	}
	list, err := ParseIDs(goos, ids)
	if err != nil {
		return InstallParams{}, err
	}
	mode, has, err := paramString(m, "mode")
	if err != nil {
		return InstallParams{}, err
	}
	if !has {
		mode = "dry_run"
	}
	if mode != "dry_run" && mode != "install" {
		return InstallParams{}, errParams
	}
	n, err := paramInt(m, "max_minutes", 1, MaxMaxMinutes, DefaultMaxMinutes)
	if err != nil {
		return InstallParams{}, err
	}
	return InstallParams{IDs: list, Mode: mode, MaxMinutes: n}, nil
}
