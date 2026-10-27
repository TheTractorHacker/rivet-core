package jobs

import (
	"strings"
	"testing"
)

func envMap(env []string) map[string]string {
	m := map[string]string{}
	for _, kv := range env {
		if i := strings.IndexByte(kv, '='); i > 0 {
			m[kv[:i]] = kv[i+1:]
		}
	}
	return m
}

func lookupOf(m map[string]string) func(string) (string, bool) {
	return func(k string) (string, bool) { v, ok := m[k]; return v, ok }
}

// A service started with only the bare minimum (what the first Windows run saw) still yields a usable environment: PATHEXT and ComSpec are
// defaulted, PATH holds System32, Wbem and the PowerShell folder.
func TestBuildWindowsEnvDefaults(t *testing.T) {
	e := envMap(buildWindowsEnv(lookupOf(map[string]string{"SystemRoot": `C:\Windows`, "ProgramData": `C:\ProgramData`})))
	if !strings.Contains(e["PATHEXT"], ".EXE") {
		t.Fatalf("PATHEXT missing .EXE: %q", e["PATHEXT"])
	}
	if e["ComSpec"] != `C:\Windows\System32\cmd.exe` && !strings.HasSuffix(e["ComSpec"], "cmd.exe") {
		t.Fatalf("ComSpec: %q", e["ComSpec"])
	}
	for _, want := range []string{`C:\Windows\System32`, `C:\Windows\System32\Wbem`, `C:\Windows\System32\WindowsPowerShell\v1.0`} {
		if !strings.Contains(";"+e["PATH"]+";", ";"+want+";") {
			t.Errorf("PATH lacks %s: %s", want, e["PATH"])
		}
	}
	if e["ProgramData"] != `C:\ProgramData` || e["windir"] != `C:\Windows` {
		t.Errorf("base variables: %v", e)
	}
}

// The machine variables a script reads are passed through; the agent's own PATH, proxy settings and anything else are not.
func TestBuildWindowsEnvPassThroughAndIsolation(t *testing.T) {
	e := envMap(buildWindowsEnv(lookupOf(map[string]string{
		"SystemRoot": `C:\Windows`, "COMPUTERNAME": "PC1", "PATHEXT": ".EXE;.CMD", "PROCESSOR_ARCHITECTURE": "AMD64", "NUMBER_OF_PROCESSORS": "12",
		"PSModulePath": `C:\Windows\system32\WindowsPowerShell\v1.0\Modules`, "ProgramFiles(x86)": `C:\Program Files (x86)`,
		"PATH": `C:\Users\someone\bin`, "HTTPS_PROXY": "http://user:secret@proxy:3128", "RIVETIT_TOKEN": "tok",
	})))
	if e["COMPUTERNAME"] != "PC1" || e["PATHEXT"] != ".EXE;.CMD" || e["PROCESSOR_ARCHITECTURE"] != "AMD64" || e["NUMBER_OF_PROCESSORS"] != "12" || e["ProgramFiles(x86)"] == "" {
		t.Errorf("pass-through lost: %v", e)
	}
	if strings.Contains(e["PATH"], "someone") {
		t.Errorf("the agent's PATH leaked: %s", e["PATH"])
	}
	for k := range e {
		if k == "HTTPS_PROXY" || k == "RIVETIT_TOKEN" {
			t.Errorf("%s must not be passed to a job", k)
		}
	}
}

func TestBuildWindowsEnvNoSystemRoot(t *testing.T) {
	e := envMap(buildWindowsEnv(lookupOf(map[string]string{})))
	if e["SystemRoot"] != `C:\Windows` || !strings.HasPrefix(e["PATH"], `C:\Windows\System32;`) {
		t.Errorf("fallback: %v", e)
	}
}
