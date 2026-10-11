package jobs

import (
	"bytes"
	"encoding/json"
	"regexp"
	"strings"
)

var redactPatterns = []*regexp.Regexp{
	regexp.MustCompile(`(?i)(bearer|basic)\s+[A-Za-z0-9\-._~+/]{8,}=*`),
	regexp.MustCompile(`(?i)\b(pass(?:word|wd)?|pwd|secret|token|api[_-]?key|apikey|client[_-]?secret|authorization|private[_-]?key)(["']?\s*[:=]\s*["']?)[^\s"',;&]+`),
	regexp.MustCompile(`eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]*`), // JWT
	regexp.MustCompile(`\bgh[pousr]_[A-Za-z0-9]{20,}\b`),
	regexp.MustCompile(`\bgithub_pat_[A-Za-z0-9_]{20,}\b`),
	regexp.MustCompile(`\bxox[abprs]-[A-Za-z0-9-]{10,}\b`),
	regexp.MustCompile(`\bAKIA[0-9A-Z]{16}\b`),
	regexp.MustCompile(`\bsk-[A-Za-z0-9_\-]{20,}\b`),
	regexp.MustCompile(`(?s)-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)`),
	regexp.MustCompile(`(?i)(://[^/\s:@]+:)[^/\s@]+(@)`), // user:pass@ in URLs
}

const redacted = "[REDACTED]"

// Redact removes bearer tokens, password/secret assignments, well-known token
// formats and any of the literal secrets supplied (device/enrollment token).
func Redact(s string, literals ...string) string {
	for _, l := range literals {
		if len(l) >= 6 {
			s = strings.ReplaceAll(s, l, redacted)
		}
	}
	for i, re := range redactPatterns {
		switch i {
		case 1:
			s = re.ReplaceAllString(s, "${1}${2}"+redacted)
		case 9:
			s = re.ReplaceAllString(s, "${1}"+redacted+"${2}")
		default:
			s = re.ReplaceAllString(s, redacted)
		}
	}
	return s
}

var jsonStringRE = regexp.MustCompile(`"(?:[^"\\]|\\.)*"`)

// RedactJSONStrings applies Redact to the contents of every string literal of a
// JSON document, so the result stays valid JSON and keeps its member order. A
// remote-tool result (service names, process names, file paths) is a JSON
// document: redacting the whole text could cut a quote, redacting each literal
// cannot.
func RedactJSONStrings(doc string, literals ...string) string {
	return jsonStringRE.ReplaceAllStringFunc(doc, func(lit string) string {
		var s string
		if json.Unmarshal([]byte(lit), &s) != nil {
			return lit
		}
		r := Redact(s, literals...)
		if r == s {
			return lit
		}
		var buf bytes.Buffer
		enc := json.NewEncoder(&buf)
		enc.SetEscapeHTML(false)
		if enc.Encode(r) != nil {
			return lit
		}
		return strings.TrimRight(buf.String(), "\n")
	})
}
