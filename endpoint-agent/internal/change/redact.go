package change

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"regexp"
	"strings"
	"unicode/utf8"
)

// Mask replaces a secret in a value.
const Mask = "[REDACTED]"

var reSecretName = regexp.MustCompile(`(?i)(pass(word|wd|phrase)?|pwd|secret|token|api[_-]?key|apikey|private[_-]?key|credential|connectionstring|authkey|accesskey|sharedkey|sas[_-]?token|bearer|cookie)`)

// SecretName reports whether a value name looks like it holds a secret. Such a
// value is never shown or sent; its change is detected with a keyed hash only
// (see Hasher), so the hash cannot be used to guess a weak password.
func SecretName(name string) bool { return reSecretName.MatchString(name) }

// Hasher produces the hashes the agent keeps and sends. A secret value is hashed
// with HMAC-SHA256 under a key that never leaves the device, any other value
// with plain SHA-256 (which is what the server compares and displays).
type Hasher struct{ Key []byte }

func (h Hasher) Plain(b []byte) string {
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:])
}

func (h Hasher) Keyed(b []byte) string {
	m := hmac.New(sha256.New, h.Key)
	m.Write(b)
	return hex.EncodeToString(m.Sum(nil))
}

// Clean removes control characters (newlines and tabs become spaces) and invalid
// UTF-8, and trims.
func Clean(s string) string {
	s = strings.ToValidUTF8(s, "\uFFFD")
	s = strings.Map(func(r rune) rune {
		switch {
		case r == '\n' || r == '\r' || r == '\t':
			return ' '
		case r < 0x20 || r == 0x7f || (r >= 0x80 && r < 0xa0):
			return -1
		}
		return r
	}, s)
	return strings.TrimSpace(s)
}

// Truncate cuts s to at most max bytes without splitting a rune.
func Truncate(s string, max int) string {
	if max <= 0 || len(s) <= max {
		return s
	}
	cut := max
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut]
}

// reKeySecret is the key=value rule of the server's Redactor (Rmm\Crypto\Redactor): a name that CONTAINS password, secret, token, key and the like (db_password,
// ClientSecret, my-api-key) followed by : or = hides what follows. The agent's job redactor only knows the bare words, so this one is added here: a secret must be
// masked on the device BEFORE it is stored in a snapshot, not only when the server sees it.
var reKeySecret = regexp.MustCompile(`(?i)\b([A-Za-z0-9_.-]*(?:password|passwd|pwd|passphrase|secret|token|api[_-]?key|apikey|access[_-]?key|private[_-]?key|client[_-]?secret|credential)[A-Za-z0-9_.-]*)(["']?\s*[:=]\s*["']?)[^\s"',;]+`)

// reCmdSecrets are command-line shapes that carry a password after a switch: curl -u user:pw, mysql -pSECRET, net use /user:x pw, schtasks /RP pw, /p:pw, sshpass -p pw.
// Best effort by nature (see CHANGE_MONITOR.md section 7): a secret in free text with no marker stays visible.
var reCmdSecrets = []*regexp.Regexp{
	regexp.MustCompile(`(?i)(\s-u\s+[^\s:]+:)\S+`),
	regexp.MustCompile(`(?i)(\bmysql\w*\b[^\n]*?\s-p)[^\s=]\S*`),
	regexp.MustCompile(`(?i)(/user:\S+\s+)\S+`),
	regexp.MustCompile(`(?i)(\s/RP\s+)\S+`),
	regexp.MustCompile(`(?i)(\s/p(?:assword)?:)\S+`),
	regexp.MustCompile(`(?i)(\bsshpass\b[^\n]*?\s-p\s*)\S+`),
	regexp.MustCompile(`(?i)(://[^/\s:@]+:)[^/\s@]+(@)`),
}

// Display turns a raw text value into what may be kept and shown: cleaned,
// redacted with the agent's redactor (patterns for keys, tokens and passwords)
// and cut to max bytes. redacted reports a pattern hit, truncated a cut.
func Display(raw string, max int, redact func(string) string) (text string, redacted, truncated bool) {
	c := Clean(raw)
	if redact != nil {
		if r := redact(c); r != c {
			c, redacted = r, true
		}
	}
	if r := reKeySecret.ReplaceAllString(c, "${1}${2}"+Mask); r != c {
		c, redacted = r, true
	}
	for i, re := range reCmdSecrets {
		repl := "${1}" + Mask
		if i == len(reCmdSecrets)-1 {
			repl = "${1}" + Mask + "${2}"
		}
		if r := re.ReplaceAllString(c, repl); r != c {
			c, redacted = r, true
		}
	}
	if len(c) > max {
		c, truncated = Truncate(c, max), true
	}
	return c, redacted, truncated
}
