package collect

import "testing"

// CORE-DS-18: on Windows the agent (SYSTEM) must never resolve powershell/tasklist/ping through PATH.
func TestSystemToolResolvesToSystem32OnWindows(t *testing.T) {
	root := `C:\Windows`
	cases := []struct{ goos, in, want string }{
		{"windows", "powershell.exe", `C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe`},
		{"windows", "PowerShell.EXE", `C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe`},
		{"windows", "tasklist", `C:\Windows\System32\tasklist.exe`},
		{"windows", "ping", `C:\Windows\System32\ping.exe`},
		{"windows", "ping.exe", `C:\Windows\System32\ping.exe`},
		{"windows", `C:\tools\x.exe`, `C:\tools\x.exe`}, // already a path: untouched
		{"windows", "unknowntool", "unknowntool"},
		{"linux", "ping", "ping"},
	}
	for _, c := range cases {
		if got := systemTool(c.goos, root, c.in); got != c.want {
			t.Errorf("%s %q = %q, want %q", c.goos, c.in, got, c.want)
		}
	}
	if got := systemTool("windows", "", "ping"); got != "ping" {
		t.Errorf("without a SystemRoot nothing can be resolved: %q", got)
	}
}
