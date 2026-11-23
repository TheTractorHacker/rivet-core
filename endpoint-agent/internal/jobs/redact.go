package jobs

import (
	"bytes"
	"encoding/json"
	"regexp"
	"strings"
)

const redacted = "[REDACTED]"

// redactRule is one pattern and its replacement (a template with ${n} groups). fn, when set, replaces each match instead.
type redactRule struct {
	re   *regexp.Regexp
	repl string
	fn   func(m string) string
}

// pPassSwitch masks the value of a -p switch for the tools that take a password that way (mysql, mariadb, sshpass, mongo; never psql, where -p is the
// port). The switch is case-sensitive: mysql's -P is the port. A value spelled "-password" is a different switch and is left alone.
var pPassSwitch = regexp.MustCompile(`((?i:\b(?:mysql\w*|mariadb\w*|sshpass|mongo\w*))\b[^\r\n]{0,200}?\s-p[ \t]?)(\S+)`)

// redactRules are applied in order. Best effort by nature: a secret printed with no marker around it stays visible. Every shape here is pinned by the
// shared vector file src/Rmm/Crypto/redaction-vectors.json, which the server's Rmm\Crypto\Redactor checks too (CORE-DATA-2, NEW-3, Chain E). The rules are
// deliberately narrow (judge's ruling): each needs the marker that makes the text a credential.
var redactRules = []redactRule{
	{re: regexp.MustCompile(`(?i)(bearer|basic)\s+[A-Za-z0-9\-._~+/]{8,}=*`), repl: redacted},
	// An UNQUOTED value after a password-like name (password, passwd, passw0rd, passphrase, with or without a DB_ / smtp. prefix) is masked to the end of
	// the line: "password: correct horse battery staple" leaves no word behind. Not after a command-line switch (--password=x --verbose: the shell already
	// ended the value at the space), not for other names (token, secret, api_key: they still stop at the first space), and a quoted value is left to the
	// next rule, which stops at its closing quote.
	{re: regexp.MustCompile(`(?i)(^|[^-A-Za-z0-9_.])((?:[A-Za-z0-9]+[_.-])*(?:passw[o0]rd|passwd|passphrase))(["']?[ \t]*[:=][ \t]*)[^\s"'][^\r\n]*`), repl: "${1}${2}${3}" + redacted},
	// name=value / name: value. A quoted value is masked up to its closing quote (pass="a b c" leaves nothing of the passphrase); groups: 2 separator,
	// 3/5 opening quote, 4/6 closing quote. The name may carry a prefix (DB_PASSWORD, smtp.pass, x-api-key), as on the server.
	{re: regexp.MustCompile(`(?i)\b((?:[A-Za-z0-9]+[_.-])*(?:pass(?:word|wd)?|passw[o0]rd|pwd|secret|token|api[_-]?key|apikey|client[_-]?secret|authorization|private[_-]?key|psk))(["']?\s*[:=]\s*)(?:(")(?:[^"\\\r\n]|\\.)*("?)|(')(?:[^'\\\r\n]|\\.)*('?)|[^\s"',;&]+)`), repl: "${1}${2}${3}${5}" + redacted + "${4}${6}"},
	{re: regexp.MustCompile(`eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]*`), repl: redacted}, // JWT
	{re: regexp.MustCompile(`\bgh[pousr]_[A-Za-z0-9]{20,}\b`), repl: redacted},
	{re: regexp.MustCompile(`\bgithub_pat_[A-Za-z0-9_]{20,}\b`), repl: redacted},
	{re: regexp.MustCompile(`\bxox[abprs]-[A-Za-z0-9-]{10,}\b`), repl: redacted},
	{re: regexp.MustCompile(`\bAKIA[0-9A-Z]{16}\b`), repl: redacted},
	{re: regexp.MustCompile(`\bsk-[A-Za-z0-9_\-]{20,}\b`), repl: redacted},
	// Stripe secret and restricted keys (sk_live_, sk_test_, rk_live_, rk_test_).
	{re: regexp.MustCompile(`\b[rs]k_(?:live|test)_[0-9A-Za-z]{10,}`), repl: redacted},
	{re: regexp.MustCompile(`(?s)-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)`), repl: redacted},
	// The password of scheme://user:<password>@host. The user name may be empty (scheme://:<password>@host, Chain E); it never holds a colon, so a
	// password that contains one is masked whole.
	{re: regexp.MustCompile(`(?i)(://[^/\s:@]*:)[^/\s@]+(@)`), repl: "${1}" + redacted + "${2}"},
	// Cookie / Set-Cookie: the whole value, on a header line only (optionally indented or behind curl -v's "< " / "> " marker).
	{re: regexp.MustCompile(`(?im)^([ \t]*(?:[<>][ \t]*)?(?:set-)?cookie[ \t]*:[ \t]*)[^\s][^\r\n]*`), repl: "${1}" + redacted},
	// Azure SAS and similar signed URLs: the sig= member of a URL query string only.
	{re: regexp.MustCompile(`(?i)([?&]sig=)[^&\s"'#<>]+`), repl: "${1}" + redacted},
	// Slack incoming webhook: the path after /services/ is the secret.
	{re: regexp.MustCompile(`(?i)(hooks\.slack\.com/services/)[A-Za-z0-9/_-]+`), repl: "${1}" + redacted},
	// net use: the password positional after \\host\share, and the one after /user:name. A token starting with / (a switch) or * (prompt) is not one.
	{re: regexp.MustCompile(`(?i)(\bnet[ \t]+use\b[^\r\n]*?[ \t](?:"\\\\[^"\r\n]+"|\\\\[^\s\\]+\\\S*)[ \t]+)("[^"\r\n]*"|[^\s/"*]\S*)`), repl: "${1}" + redacted},
	{re: regexp.MustCompile(`(?i)(\bnet[ \t]+use\b[^\r\n]*?[ \t]/user:\S+[ \t]+)("[^"\r\n]*"|[^\s/"*]\S*)`), repl: "${1}" + redacted},
	// --pass / --password / -Password <value> (a value in the next word).
	{re: regexp.MustCompile(`(?im)((?:^|\s)--?pass(?:word|wd)?[ \t]+)("[^"\r\n]*"|'[^'\r\n]*'|[^\s"']\S*)`), repl: "${1}" + redacted},
	{re: pPassSwitch, fn: func(m string) string {
		p := pPassSwitch.FindStringSubmatch(m)
		if strings.HasPrefix(strings.ToLower(p[2]), "assword") {
			return m
		}
		return p[1] + redacted
	}},
	// This product's own credentials (rc.38): the RivetPanel device credential, the panel and agent enrollment tokens.
	{re: regexp.MustCompile(`\brvpd_[0-9A-Za-z]{16,}`), repl: redacted},
	{re: regexp.MustCompile(`\brvtp1\.[0-9A-Za-z]{6,}\.[0-9A-Za-z_-]{8,}`), repl: redacted},
	{re: regexp.MustCompile(`\brvte1\.[0-9A-Za-z]{6,}\.[0-9A-Za-z_-]{8,}`), repl: redacted},
}

// Redact removes bearer tokens, password/secret assignments, well-known token
// formats and any of the literal secrets supplied (device/enrollment token).
func Redact(s string, literals ...string) string {
	for _, l := range literals {
		if len(l) >= 6 {
			s = strings.ReplaceAll(s, l, redacted)
		}
	}
	for _, r := range redactRules {
		if r.fn != nil {
			s = r.re.ReplaceAllStringFunc(s, r.fn)
			continue
		}
		s = r.re.ReplaceAllString(s, r.repl)
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
