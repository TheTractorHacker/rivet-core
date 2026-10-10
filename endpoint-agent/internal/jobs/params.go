package jobs

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Script parameters.
//
// A job carries its parameters as a signed JSON object (`params`). They reach the
// script in three ways, none of which puts server-controlled text into a command
// line or into the script's own source:
//
//   - RIVETIT_JOB_PARAMS: the whole object as JSON (every job, as before);
//   - RIVETIT_PARAM_<name>: one environment variable per scalar parameter, the
//     value byte for byte;
//   - a few lines of generated code at the top of the script that read those
//     environment variables into script variables of the same name. A string is
//     NEVER written into that code (it is read from the environment at run time,
//     so no quoting rule can be got wrong); a whole number or a boolean is, but
//     only after being parsed and re-formatted here, so the text that reaches the
//     interpreter is digits, `true` or `false` and nothing else.
//
// Only a name that is a lower case identifier (what the server's library issues)
// gets a script variable; any other name is still in the JSON and, when it is a
// valid environment variable name, in its RIVETIT_PARAM_ variable.

// ParamKind is the JSON type of a parameter.
type ParamKind int

const (
	KindString ParamKind = iota
	KindInt
	KindBool
)

// Param is one scalar parameter. Value is the string itself, the decimal digits of
// an integer ("-12"), or "true"/"false".
type Param struct {
	Name  string
	Kind  ParamKind
	Value string
}

// maxParams bounds how many parameters are exposed (the server allows 20).
const maxParams = 20

var (
	varNameRE = regexp.MustCompile(`^[a-z][a-z0-9_]{0,31}$`)
	envNameRE = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,63}$`)
	// reserved names would shadow something the interpreter needs; the server's
	// library refuses them too, this is the agent's own check on signed input.
	reservedVars = map[string]bool{
		"args": true, "error": true, "home": true, "host": true, "input": true, "matches": true, "pid": true, "ppid": true, "profile": true, "pwd": true,
		"oldpwd": true, "this": true, "true": true, "false": true, "null": true, "psitem": true, "shellid": true, "env": true, "path": true, "ifs": true,
		"uid": true, "euid": true, "random": true, "lineno": true, "seconds": true, "shell": true, "term": true, "user": true, "hostname": true,
		"optarg": true, "optind": true, "reply": true, "status": true, "params": true, "foreach": true, "consolehost": true, "executioncontext": true,
		"lastexitcode": true, "myinvocation": true, "psboundparameters": true, "pscmdlet": true, "psscriptroot": true, "pscommandpath": true,
		"stacktrace": true, "sender": true,
		"then": true, "fi": true, "esac": true, "select": true, "until": true, "do": true, "done": true, "function": true, "time": true, "coproc": true,
		"and": true, "as": true, "assert": true, "async": true, "await": true, "break": true, "class": true, "continue": true, "def": true, "del": true,
		"elif": true, "else": true, "except": true, "finally": true, "for": true, "from": true, "global": true, "if": true, "import": true, "in": true,
		"is": true, "lambda": true, "nonlocal": true, "not": true, "or": true, "pass": true, "raise": true, "return": true, "try": true, "while": true,
		"with": true, "yield": true, "os": true, "sys": true, "json": true,
	}
)

// ParseParams reads the scalar members of a params object, in name order. A
// non-object, a null and an empty object give no parameters. Nested values and
// non-integer numbers are ignored (they stay in the JSON).
func ParseParams(raw []byte) []Param {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 || string(raw) == "null" {
		return nil
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var m map[string]any
	if err := dec.Decode(&m); err != nil {
		return nil
	}
	names := make([]string, 0, len(m))
	for k := range m {
		names = append(names, k)
	}
	sort.Strings(names)
	var out []Param
	for _, k := range names {
		if len(out) >= maxParams {
			break
		}
		if !envNameRE.MatchString(k) {
			continue
		}
		switch v := m[k].(type) {
		case string:
			if strings.ContainsRune(v, 0) {
				continue // cannot be an environment value
			}
			out = append(out, Param{Name: k, Kind: KindString, Value: v})
		case bool:
			out = append(out, Param{Name: k, Kind: KindBool, Value: strconv.FormatBool(v)})
		case json.Number:
			if n, err := strconv.ParseInt(v.String(), 10, 64); err == nil {
				out = append(out, Param{Name: k, Kind: KindInt, Value: strconv.FormatInt(n, 10)})
			}
		}
	}
	return out
}

// ParamEnv is the RIVETIT_PARAM_<name> environment of the parameters.
func ParamEnv(ps []Param) []string {
	out := make([]string, 0, len(ps))
	for _, p := range ps {
		out = append(out, "RIVETIT_PARAM_"+p.Name+"="+p.Value)
	}
	return out
}

func scriptVar(p Param) bool { return varNameRE.MatchString(p.Name) && !reservedVars[p.Name] }

// BashPrelude is the code that defines a shell variable per parameter. A string
// is read from the environment inside double quotes (one expansion, no word
// splitting, no globbing, nothing re-evaluated); an integer or boolean is a
// literal of digits, `true` or `false`.
func BashPrelude(ps []Param) string {
	var b strings.Builder
	for _, p := range ps {
		if !scriptVar(p) {
			continue
		}
		if p.Kind == KindString {
			fmt.Fprintf(&b, "%s=\"${RIVETIT_PARAM_%s-}\"\n", p.Name, p.Name)
		} else {
			fmt.Fprintf(&b, "%s=%s\n", p.Name, p.Value)
		}
	}
	return b.String()
}

// PowerShellPrelude defines a variable per parameter: a string from the process
// environment, an integer as [long], a boolean as $true or $false.
func PowerShellPrelude(ps []Param) string {
	var b strings.Builder
	for _, p := range ps {
		if !scriptVar(p) {
			continue
		}
		switch p.Kind {
		case KindString:
			fmt.Fprintf(&b, "$%s=$env:RIVETIT_PARAM_%s;", p.Name, p.Name)
		case KindInt:
			fmt.Fprintf(&b, "$%s=[long]%s;", p.Name, strings.TrimPrefix(p.Value, "+"))
		default:
			fmt.Fprintf(&b, "$%s=$%s;", p.Name, p.Value)
		}
	}
	return b.String()
}

// PythonPrelude defines a variable per parameter: a string from os.environ, an
// integer or boolean as a literal.
func PythonPrelude(ps []Param) string {
	var b strings.Builder
	any := false
	for _, p := range ps {
		if scriptVar(p) {
			any = true
		}
	}
	if !any {
		return ""
	}
	b.WriteString("import os as _rivetit_os\n")
	for _, p := range ps {
		if !scriptVar(p) {
			continue
		}
		switch p.Kind {
		case KindString:
			fmt.Fprintf(&b, "%s = _rivetit_os.environ.get(\"RIVETIT_PARAM_%s\", \"\")\n", p.Name, p.Name)
		case KindInt:
			fmt.Fprintf(&b, "%s = %s\n", p.Name, p.Value)
		default:
			fmt.Fprintf(&b, "%s = %s\n", p.Name, map[bool]string{true: "True", false: "False"}[p.Value == "true"])
		}
	}
	return b.String()
}
