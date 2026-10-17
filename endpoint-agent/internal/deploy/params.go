package deploy

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"net/url"
	"regexp"
	"strings"
	"unicode"
	"unicode/utf8"
)

// Every expression is anchored; Go's $ (without the m flag) matches only at the very end of the text, so a trailing newline fails.
var (
	keyRe         = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{0,39}$`)
	pkgRe         = regexp.MustCompile(`^[a-z0-9][a-z0-9+._-]{0,99}$`)
	productCodeRe = regexp.MustCompile(`^\{[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}$`)
	minRe         = regexp.MustCompile(`^[0-9][A-Za-z0-9.+~:_-]{0,63}$`)
	pinRe         = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9+.~:_-]{0,63}$`)
	sha256Re      = regexp.MustCompile(`^[0-9a-f]{64}$`)
	argsRe        = regexp.MustCompile(`^[A-Za-z0-9 /\\_=.:,+-]{0,200}$`)
	labelRe       = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	tldRe         = regexp.MustCompile(`^[a-z][a-z0-9-]*$`)
)

var errParams = errors.New(DetailInvalidParams)

// Item is one detection request.
type Item struct {
	Key, Kind, Value, Min string
}

// InstallParams are the validated parameters of software_install.
type InstallParams struct {
	Action      string // "install" | "uninstall"
	Mode        string // "dry_run" | "run"
	Fmt         string // apt | dnf | msi | exe
	Pkg         string // apt/dnf
	Version     string // apt/dnf install pin
	URL         string // msi/exe install; scheme https, lower-case DNS host
	SHA256      string
	Size        int64
	Args        []string // exe: already split on single spaces
	ProductCode string   // msi
	Detect      Item     // what proves the installation (derived for apt/dnf/msi with a product code)
	MaxMinutes  int
}

// strictObject decodes one JSON object, refusing duplicate keys, trailing data and anything that is not an object.
func strictObject(raw []byte) (map[string]json.RawMessage, error) {
	dec := json.NewDecoder(bytes.NewReader(bytes.TrimSpace(raw)))
	t, err := dec.Token()
	if d, ok := t.(json.Delim); err != nil || !ok || d != '{' {
		return nil, errParams
	}
	m := map[string]json.RawMessage{}
	for dec.More() {
		kt, err := dec.Token()
		if err != nil {
			return nil, errParams
		}
		k, ok := kt.(string)
		if !ok {
			return nil, errParams
		}
		if _, dup := m[k]; dup {
			return nil, errParams
		}
		var v json.RawMessage
		if err := dec.Decode(&v); err != nil {
			return nil, errParams
		}
		m[k] = v
	}
	if _, err := dec.Token(); err != nil { // the closing brace
		return nil, errParams
	}
	if _, err := dec.Token(); err != io.EOF {
		return nil, errParams
	}
	return m, nil
}

func allow(m map[string]json.RawMessage, keys ...string) error {
	ok := map[string]bool{}
	for _, k := range keys {
		ok[k] = true
	}
	for k := range m {
		if !ok[k] {
			return errParams
		}
	}
	return nil
}

// str reads a JSON string member; a number, null, boolean, object or array is refused.
func str(m map[string]json.RawMessage, k string) (string, bool, error) {
	v, has := m[k]
	if !has {
		return "", false, nil
	}
	if t := bytes.TrimSpace(v); len(t) == 0 || t[0] != '"' {
		return "", false, errParams
	}
	var s string
	if err := json.Unmarshal(v, &s); err != nil {
		return "", false, errParams
	}
	return s, true, nil
}

// integer reads a whole number in [lo,hi]. A string, float, exponent, null or out-of-range number is refused (clamping would hide a server bug).
func integer(m map[string]json.RawMessage, k string, lo, hi int64) (int64, bool, error) {
	v, has := m[k]
	if !has {
		return 0, false, nil
	}
	t := bytes.TrimSpace(v)
	if len(t) == 0 || (t[0] != '-' && (t[0] < '0' || t[0] > '9')) {
		return 0, false, errParams
	}
	for _, c := range t {
		if c != '-' && (c < '0' || c > '9') {
			return 0, false, errParams
		}
	}
	var n json.Number
	dec := json.NewDecoder(bytes.NewReader(t))
	dec.UseNumber()
	if err := dec.Decode(&n); err != nil {
		return 0, false, errParams
	}
	i, err := n.Int64()
	if err != nil || i < lo || i > hi {
		return 0, false, errParams
	}
	return i, true, nil
}

// ValidKey reports whether s is a legal item key.
func ValidKey(s string) bool { return keyRe.MatchString(s) }

// ValidatePrintableName checks an Add/Remove Programs name: 1..120 characters, valid UTF-8, printable, no control or format characters.
func ValidatePrintableName(s string) error {
	if s == "" || !utf8.ValidString(s) || utf8.RuneCountInString(s) > MaxNameChars {
		return errParams
	}
	for _, r := range s {
		if r == utf8.RuneError || !unicode.IsPrint(r) {
			return errParams
		}
	}
	return nil
}

// ValidateDetect checks one (kind, value, min) triple by the syntax of its kind.
func ValidateDetect(kind, value, min string) error {
	switch kind {
	case KindPackage:
		if !pkgRe.MatchString(value) {
			return errParams
		}
	case KindProductCode:
		if !productCodeRe.MatchString(value) {
			return errParams
		}
	case KindUninstallNam:
		if err := ValidatePrintableName(value); err != nil {
			return err
		}
	default:
		return errParams
	}
	if min != "" && !minRe.MatchString(min) {
		return errParams
	}
	return nil
}

// ParseDetectParams validates the software_detect parameters.
func ParseDetectParams(raw json.RawMessage) ([]Item, error) {
	m, err := strictObject(raw)
	if err != nil {
		return nil, err
	}
	if err := allow(m, "rules"); err != nil {
		return nil, err
	}
	rules, has, err := str(m, "rules")
	if err != nil || !has || len(rules) < 2 || len(rules) > MaxRulesBytes || !utf8.ValidString(rules) || strings.IndexByte(rules, 0) >= 0 {
		return nil, errParams
	}
	// The rules text is itself a JSON array of flat objects; anything after the array is refused.
	dec := json.NewDecoder(strings.NewReader(rules))
	if t, err := dec.Token(); err != nil || t != json.Delim('[') {
		return nil, errParams
	}
	var elems []json.RawMessage
	for dec.More() {
		var e json.RawMessage
		if err := dec.Decode(&e); err != nil || len(elems) >= MaxItems {
			return nil, errParams
		}
		elems = append(elems, e)
	}
	if _, err := dec.Token(); err != nil { // the closing bracket
		return nil, errParams
	}
	if _, err := dec.Token(); err != io.EOF || len(elems) < 1 {
		return nil, errParams
	}
	seen := map[string]bool{}
	items := make([]Item, 0, len(elems))
	for _, e := range elems {
		o, err := strictObject(e)
		if err != nil {
			return nil, err
		}
		if len(o) != 4 {
			return nil, errParams
		}
		var f [4]string
		for i, k := range []string{"key", "kind", "value", "min"} {
			s, has, err := str(o, k)
			if err != nil || !has {
				return nil, errParams
			}
			f[i] = s
		}
		it := Item{Key: f[0], Kind: f[1], Value: f[2], Min: f[3]}
		if !keyRe.MatchString(it.Key) || seen[it.Key] || ValidateDetect(it.Kind, it.Value, it.Min) != nil {
			return nil, errParams
		}
		seen[it.Key] = true
		items = append(items, it)
	}
	return items, nil
}

// ValidateURL checks a download address and returns it normalised (lower-case host). Only https, no user info, a DNS name host (never an IP
// literal in any notation), port absent or 443, no fragment, no whitespace, control character, backslash or non-ASCII character.
func ValidateURL(s string) (string, error) {
	if s == "" || len(s) > MaxURLLen || !strings.HasPrefix(s, "https://") {
		return "", errParams
	}
	for i := 0; i < len(s); i++ {
		if c := s[i]; c <= 0x20 || c >= 0x7f || c == '\\' || c == '#' {
			return "", errParams
		}
	}
	rest := s[len("https://"):]
	end := strings.IndexAny(rest, "/?")
	auth := rest
	if end >= 0 {
		auth = rest[:end]
	}
	if auth == "" || strings.ContainsAny(auth, "@[]%") || strings.Count(auth, ":") > 1 {
		return "", errParams
	}
	for i := 0; i < len(rest)-len(auth); i++ {
		c := rest[len(auth)+i]
		if !(c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9' || strings.IndexByte("._~!$&'()*+,;=:@%/-?", c) >= 0) {
			return "", errParams
		}
	}
	if strings.Count(rest[len(auth):], "?") > 1 {
		return "", errParams
	}
	host := auth
	if i := strings.IndexByte(auth, ':'); i >= 0 {
		host = auth[:i]
		if auth[i+1:] != "443" {
			return "", errParams
		}
	}
	if host != strings.ToLower(host) {
		return "", errParams // the server sends the host in lower case; anything else is not what it signed
	}
	if !validHost(host) {
		return "", errParams
	}
	u, err := url.Parse(s)
	if err != nil || u.Scheme != "https" || u.User != nil || u.Fragment != "" || u.Opaque != "" || strings.ToLower(u.Hostname()) != host {
		return "", errParams
	}
	return "https://" + host + s[len("https://")+len(auth):], nil
}

// validHost accepts a DNS name with at least two labels whose last label starts with a letter (so no decimal, octal, hex or dotted IP form).
func validHost(h string) bool {
	if h == "" || len(h) > 253 {
		return false
	}
	labels := strings.Split(h, ".")
	if len(labels) < 2 {
		return false
	}
	for _, l := range labels {
		if !labelRe.MatchString(l) {
			return false
		}
	}
	return tldRe.MatchString(labels[len(labels)-1])
}

// ParseArgs validates the exe silent-install arguments and splits them: single spaces only, at most 12 tokens of at most 80 characters.
func ParseArgs(s string) ([]string, error) {
	if len(s) > MaxArgsChars || !argsRe.MatchString(s) {
		return nil, errParams
	}
	if s == "" {
		return nil, nil
	}
	toks := strings.Split(s, " ")
	if len(toks) > MaxArgTokens {
		return nil, errParams
	}
	for _, t := range toks {
		if t == "" || len(t) > MaxArgToken {
			return nil, errParams
		}
	}
	return toks, nil
}

var installKeys = []string{"action", "mode", "fmt", "pkg", "version", "url", "sha256", "size", "args", "product_code", "detect_kind", "detect_value", "detect_min", "max_minutes"}

// ParseInstallParams validates the software_install parameters, including every cross-field rule.
func ParseInstallParams(raw json.RawMessage) (InstallParams, error) {
	var p InstallParams
	m, err := strictObject(raw)
	if err != nil {
		return p, err
	}
	if err := allow(m, installKeys...); err != nil {
		return p, err
	}
	var s [14]string
	var has [14]bool
	for i, k := range installKeys {
		switch k {
		case "size", "max_minutes":
			continue
		}
		if s[i], has[i], err = str(m, k); err != nil {
			return p, err
		}
	}
	idx := map[string]int{}
	for i, k := range installKeys {
		idx[k] = i
	}
	get := func(k string) (string, bool) { return s[idx[k]], has[idx[k]] }
	p.Action, _ = get("action")
	if p.Action != "install" && p.Action != "uninstall" {
		return p, errParams
	}
	p.Mode, _ = get("mode")
	if !has[idx["mode"]] {
		p.Mode = "dry_run"
	}
	if p.Mode != "dry_run" && p.Mode != "run" {
		return p, errParams
	}
	p.Fmt, _ = get("fmt")
	switch p.Fmt {
	case FmtApt, FmtDnf, FmtMsi, FmtExe:
	default:
		return p, errParams
	}
	size, hasSize, err := integer(m, "size", 1, MaxSize)
	if err != nil {
		return p, err
	}
	mins, hasMins, err := integer(m, "max_minutes", 1, MaxMaxMinutes)
	if err != nil {
		return p, err
	}
	p.MaxMinutes = DefaultMaxMinutes
	if hasMins {
		p.MaxMinutes = int(mins)
	}
	_, hasPkg := get("pkg")
	_, hasVer := get("version")
	_, hasURL := get("url")
	_, hasSha := get("sha256")
	_, hasArgs := get("args")
	_, hasPC := get("product_code")
	dk, hasDK := get("detect_kind")
	dv, hasDV := get("detect_value")
	dm, hasDM := get("detect_min")
	forbid := func(bs ...bool) error {
		for _, b := range bs {
			if b {
				return errParams
			}
		}
		return nil
	}
	if hasDK != hasDV {
		return p, errParams
	}
	if p.Action == "uninstall" && (hasDM || hasVer || hasURL || hasSha || hasSize || hasArgs) {
		return p, errParams
	}
	switch p.Fmt {
	case FmtApt, FmtDnf:
		if !hasPkg || forbid(hasURL, hasSha, hasSize, hasArgs, hasPC, hasDK) != nil {
			return p, errParams
		}
		p.Pkg, _ = get("pkg")
		if !pkgRe.MatchString(p.Pkg) {
			return p, errParams
		}
		if hasVer {
			if p.Action != "install" {
				return p, errParams
			}
			p.Version, _ = get("version")
			if !pinRe.MatchString(p.Version) {
				return p, errParams
			}
		}
		p.Detect = Item{Key: "target", Kind: KindPackage, Value: p.Pkg, Min: dm}
	case FmtMsi, FmtExe:
		if forbid(hasPkg, hasVer, hasArgs && p.Fmt == FmtMsi) != nil {
			return p, errParams
		}
		if p.Fmt == FmtExe {
			if p.Action != "install" || hasPC || !hasDK {
				return p, errParams // an exe cannot be uninstalled by this job, and has no product code
			}
		}
		if p.Action == "uninstall" { // msi
			if !hasPC || hasDK {
				return p, errParams
			}
		}
		if hasPC {
			p.ProductCode, _ = get("product_code")
			if !productCodeRe.MatchString(p.ProductCode) {
				return p, errParams
			}
		}
		if p.Action == "install" {
			if !hasURL || !hasSha || !hasSize {
				return p, errParams
			}
			if p.URL, err = ValidateURL(mustStr(get("url"))); err != nil {
				return p, err
			}
			p.SHA256, _ = get("sha256")
			if !sha256Re.MatchString(p.SHA256) {
				return p, errParams
			}
			p.Size = size
			if hasArgs {
				if p.Args, err = ParseArgs(mustStr(get("args"))); err != nil {
					return p, err
				}
			}
		}
		switch {
		case hasPC && hasDK:
			return p, errParams // two ways to prove the same thing
		case hasPC:
			p.Detect = Item{Key: "target", Kind: KindProductCode, Value: p.ProductCode, Min: dm}
		case hasDK:
			if dk != KindProductCode && dk != KindUninstallNam {
				return p, errParams
			}
			p.Detect = Item{Key: "target", Kind: dk, Value: dv, Min: dm}
		default:
			return p, errParams
		}
	}
	if err := ValidateDetect(p.Detect.Kind, p.Detect.Value, p.Detect.Min); err != nil {
		return p, err
	}
	return p, nil
}

func mustStr(s string, _ bool) string { return s }
