package change

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"hash"
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

// Stream returns a hash writer for a file read in pieces: keyed when keyed is true, plain otherwise. Sum it with hex.EncodeToString(h.Sum(nil)).
func (h Hasher) Stream(keyed bool) hash.Hash {
	if keyed {
		return hmac.New(sha256.New, h.Key)
	}
	return sha256.New()
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

// The rc.31 additions (security review F2), mirrored by Rmm\Change\ChangeProtocol::redact() on the server and checked against the same vectors
// (testdata/vectors/agent_change_monitor_vectors.json, "redaction"). reKeySecret only knows names that CONTAIN a secret word; these add
//   - names with a PASS / PW token (DB_PASS, smtp.pass, REDIS_PW) and names ending in _KEY (ENCRYPTION_KEY, LICENSE_KEY, AUTH_KEY) or in CamelCase Key/Pass/Pw (EncryptionKey),
//   - a secret in the next word of a switch (--pass hunter2, --token abc, -password x),
//   - -p <word> for the tools that take a password that way (mysql, mariadb, sshpass, mongo; not psql, where -p is the port),
//   - the value of any Environment= / export assignment whose name is not on the allow-list of harmless names,
//   - long hex and base64 runs (but never in an SSH public key line, whose blob is the thing a change monitor must show).
var reKeyToken = regexp.MustCompile(`(?i)\b((?:[A-Za-z0-9]+[_.-])*(?:pass|pw)(?:[_.-][A-Za-z0-9_.-]*)?|(?:[A-Za-z0-9]+[_.-])+key)(["']?\s*[:=]\s*["']?)[^\s"',;]+`)
var reKeyCamel = regexp.MustCompile(`\b([A-Za-z0-9_.-]*[a-z0-9](?:Key|Pass|Pw))(["']?\s*[:=]\s*["']?)[^\s"',;]+`)
var reSwitchSecret = regexp.MustCompile(`(?i)(\s--?(?:pass(?:word|wd|phrase)?|pwd|pw|secret|token|api-?key|apikey|auth(?:-?token)?|bearer|client-?secret|access-?key|private-?key|credential|key)\s+)(?:"[^"]*"|'[^']*'|\S+)`)
var rePSwitch = regexp.MustCompile(`(?i)(\b(?:mysql\w*|mariadb\w*|sshpass|mongo\w*)\b[^\n]*?\s-p\s+)\S+`)
var reEnvKeyword = regexp.MustCompile(`(\bEnvironment\s*=\s*|\bexport\s+)((?:"[A-Za-z_][A-Za-z0-9_]*=[^"]*"|'[A-Za-z_][A-Za-z0-9_]*=[^']*'|[A-Za-z_][A-Za-z0-9_]*=\S*)(?:\s+(?:"[A-Za-z_][A-Za-z0-9_]*=[^"]*"|'[A-Za-z_][A-Za-z0-9_]*=[^']*'|[A-Za-z_][A-Za-z0-9_]*=\S*))*)`)
var reEnvAssign = regexp.MustCompile(`"([A-Za-z_][A-Za-z0-9_]*)=([^"]*)"|'([A-Za-z_][A-Za-z0-9_]*)=([^']*)'|([A-Za-z_][A-Za-z0-9_]*)=(\S*)`)
var reHexRun = regexp.MustCompile(`\b[0-9A-Fa-f]{32,}\b`)
var reB64Run = regexp.MustCompile(`(^|[\s"'=:])[A-Za-z0-9+]{40,}={0,2}($|[\s"',;])`)
var reSSHKeyLine = regexp.MustCompile(`(?i)\b(?:ssh-(?:rsa|ed25519|dss)|ecdsa-sha2-\S+|sk-(?:ssh|ecdsa)\S*)\s`)

// envHarmless are environment variable names whose values are shown. Everything else in an Environment= or export assignment is masked (a bare NAME=value line, as in /etc/environment, is only caught by the name rules above: ExecStart= and every other key=value line must stay readable).
var envHarmless = map[string]bool{"PATH": true, "LANG": true, "LANGUAGE": true, "TZ": true, "HOME": true, "USER": true, "LOGNAME": true, "SHELL": true, "PWD": true, "TERM": true,
	"EDITOR": true, "VISUAL": true, "PAGER": true, "MAILTO": true, "NODE_ENV": true, "PYTHONUNBUFFERED": true, "PYTHONPATH": true, "LD_LIBRARY_PATH": true, "LD_PRELOAD": true,
	"JAVA_HOME": true, "GOPATH": true, "GOROOT": true, "DISPLAY": true, "HTTP_PROXY": true, "HTTPS_PROXY": true, "NO_PROXY": true}

func envNameHarmless(name string) bool {
	n := strings.ToUpper(name)
	if envHarmless[n] || strings.HasPrefix(n, "LC_") || strings.HasPrefix(n, "XDG_") {
		return true
	}
	for _, suf := range []string{"_HOME", "_DIR", "_PATH", "_PORT", "_HOST", "_LEVEL", "_ENV", "_MODE", "_USER", "_USERNAME"} {
		if strings.HasSuffix(n, suf) {
			return true
		}
	}
	return false
}

// maskEnvAssignments masks the value of every assignment in Environment=, "export" and leading NAME=value text unless its name is harmless.
func maskEnvAssignments(s string) string {
	return reEnvKeyword.ReplaceAllStringFunc(s, func(m string) string {
		sub := reEnvKeyword.FindStringSubmatch(m)
		return sub[1] + reEnvAssign.ReplaceAllStringFunc(sub[2], func(a string) string {
			p := reEnvAssign.FindStringSubmatch(a)
			switch {
			case p[1] != "" && !envNameHarmless(p[1]):
				return `"` + p[1] + "=" + Mask + `"`
			case p[3] != "" && !envNameHarmless(p[3]):
				return "'" + p[3] + "=" + Mask + "'"
			case p[5] != "" && !envNameHarmless(p[5]):
				return p[5] + "=" + Mask
			}
			return a
		})
	})
}

// hardenRedact applies the rc.31 rules to an already cleaned single-line text.
func hardenRedact(c string) string {
	c = reKeyToken.ReplaceAllString(c, "${1}${2}"+Mask)
	c = reKeyCamel.ReplaceAllString(c, "${1}${2}"+Mask)
	c = reSwitchSecret.ReplaceAllString(c, "${1}"+Mask)
	c = rePSwitch.ReplaceAllString(c, "${1}"+Mask)
	c = maskEnvAssignments(c)
	if !reSSHKeyLine.MatchString(c) {
		c = reHexRun.ReplaceAllString(c, Mask)
		c = reB64Run.ReplaceAllString(c, "${1}"+Mask+"${2}")
	}
	return c
}

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
	if r := hardenRedact(c); r != c {
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
