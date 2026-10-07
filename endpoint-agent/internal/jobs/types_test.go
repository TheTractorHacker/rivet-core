package jobs

import (
	"strings"
	"testing"
)

func TestSupportedOnTable(t *testing.T) {
	for _, c := range []struct {
		goos, typ string
		want      bool
	}{
		{"windows", "powershell", true}, {"windows", "shell", false}, {"windows", "collect", true}, {"windows", "reboot", true},
		{"linux", "powershell", false}, {"linux", "shell", true}, {"linux", "collect", true}, {"linux", "reboot", true},
		{"darwin", "powershell", false}, {"darwin", "shell", true},
		{"linux", "", false}, {"linux", "format-disk", false}, {"windows", "PowerShell", false},
	} {
		if got := supportedOn(c.goos, c.typ); got != c.want {
			t.Errorf("supportedOn(%s,%q)=%v", c.goos, c.typ, got)
		}
	}
}

func TestJobTypesAndScriptTypeAgree(t *testing.T) {
	if !SupportsType(ScriptType()) {
		t.Fatalf("native script type %q not supported", ScriptType())
	}
	got := strings.Join(JobTypes(), ",")
	if !strings.Contains(got, "collect") || !strings.Contains(got, "reboot") || !strings.Contains(got, ScriptType()) {
		t.Fatalf("%s", got)
	}
	other := TypeShell
	if ScriptType() == TypeShell {
		other = TypePowerShell
	}
	if SupportsType(other) || strings.Contains(got, other) {
		t.Fatalf("the foreign script type %q must not be listed or supported: %s", other, got)
	}
}
