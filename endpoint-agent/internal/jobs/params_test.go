package jobs

import (
	"strings"
	"testing"
)

func TestParseParamsScalarsInNameOrder(t *testing.T) {
	ps := ParseParams([]byte(`{"zeta":true,"alpha":"x y","count":42,"neg":-7,"ratio":1.5,"nested":{"a":1},"list":[1],"nothing":null,"bad-name":"v","big":99999999999999999999}`))
	var got []string
	for _, p := range ps {
		got = append(got, p.Name+"="+p.Value)
	}
	if want := "alpha=x y,count=42,neg=-7,zeta=true"; strings.Join(got, ",") != want {
		t.Fatalf("got %v want %s", got, want)
	}
	for _, in := range []string{"", "null", "[]", "3", `"x"`, "{"} {
		if ps := ParseParams([]byte(in)); len(ps) != 0 {
			t.Fatalf("%q gave %v", in, ps)
		}
	}
	// a NUL cannot be an environment value
	if ps := ParseParams([]byte(`{"a":"x\u0000y","b":"ok"}`)); len(ps) != 1 || ps[0].Name != "b" {
		t.Fatalf("%v", ps)
	}
}

func TestParamEnvIsTheValueByteForByte(t *testing.T) {
	hostile := "'; rm -rf / #\n$(id) `id` \"q\" \\ ${x} é中"
	ps := ParseParams([]byte(`{"v":` + jsonString(hostile) + `,"n":5}`))
	env := ParamEnv(ps)
	if len(env) != 2 || env[0] != "RIVETIT_PARAM_n=5" || env[1] != "RIVETIT_PARAM_v="+hostile {
		t.Fatalf("%q", env)
	}
}

func jsonString(s string) string {
	var b strings.Builder
	b.WriteByte('"')
	for _, r := range s {
		switch r {
		case '"':
			b.WriteString(`\"`)
		case '\\':
			b.WriteString(`\\`)
		case '\n':
			b.WriteString(`\n`)
		default:
			b.WriteRune(r)
		}
	}
	b.WriteByte('"')
	return b.String()
}

// A string value is never part of the generated code, whatever it contains.
func TestPreludesNeverContainAStringValue(t *testing.T) {
	marker := "ZZ-SECRET-ZZ'\"$(touch /tmp/pwn)`\n;"
	ps := ParseParams([]byte(`{"name":` + jsonString(marker) + `,"n":12,"on":true,"off":false}`))
	for label, code := range map[string]string{"bash": BashPrelude(ps), "powershell": PowerShellPrelude(ps), "python": PythonPrelude(ps)} {
		if strings.Contains(code, "ZZ-SECRET") || strings.Contains(code, "touch") {
			t.Fatalf("%s prelude carries the value: %q", label, code)
		}
	}
	if got, want := BashPrelude(ps), "n=12\nname=\"${RIVETIT_PARAM_name-}\"\noff=false\non=true\n"; got != want {
		t.Fatalf("bash:\n%q\nwant\n%q", got, want)
	}
	if got, want := PowerShellPrelude(ps), "$n=[long]12;$name=$env:RIVETIT_PARAM_name;$off=$false;$on=$true;"; got != want {
		t.Fatalf("powershell: %q want %q", got, want)
	}
	py := PythonPrelude(ps)
	for _, want := range []string{"n = 12\n", `name = _rivetit_os.environ.get("RIVETIT_PARAM_name", "")` + "\n", "off = False\n", "on = True\n"} {
		if !strings.Contains(py, want) {
			t.Fatalf("python prelude lacks %q: %q", want, py)
		}
	}
}

func TestPreludesSkipNamesThatCouldShadowTheInterpreter(t *testing.T) {
	ps := ParseParams([]byte(`{"path":"/x","home":"h","Error":"e","ifs":"i","if":"c","os":"o","Upper":"u","a;b":"x","ok_1":"fine"}`))
	b := BashPrelude(ps)
	if b != "ok_1=\"${RIVETIT_PARAM_ok_1-}\"\n" {
		t.Fatalf("%q", b)
	}
	if p := PowerShellPrelude(ps); p != "$ok_1=$env:RIVETIT_PARAM_ok_1;" {
		t.Fatalf("%q", p)
	}
	// they are still in the environment for a script that wants them
	var names []string
	for _, e := range ParamEnv(ps) {
		names = append(names, strings.SplitN(e, "=", 2)[0])
	}
	if !strings.Contains(strings.Join(names, ","), "RIVETIT_PARAM_path") {
		t.Fatalf("%v", names)
	}
}

func TestParamCountIsBounded(t *testing.T) {
	var b strings.Builder
	b.WriteString("{")
	for i := 0; i < 40; i++ {
		if i > 0 {
			b.WriteString(",")
		}
		b.WriteString(`"p` + string(rune('a'+i%26)) + string(rune('a'+i/26)) + `":1`)
	}
	b.WriteString("}")
	if n := len(ParseParams([]byte(b.String()))); n != maxParams {
		t.Fatalf("%d", n)
	}
}
