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

func TestMeshJobTypesAreRemoteTypesOnEveryOS(t *testing.T) {
	for _, typ := range []string{TypeMeshInstall, TypeMeshUninstall} {
		if !IsRemoteType(typ) {
			t.Errorf("%s must be withdrawn by disable_remote like the other remote tools", typ)
		}
		for _, goos := range []string{"windows", "linux"} {
			if !supportedOn(goos, typ) {
				t.Errorf("%s must run on %s", typ, goos)
			}
		}
		if !strings.Contains(strings.Join(JobTypes(), ","), typ) {
			t.Errorf("%s not announced", typ)
		}
	}
	if TypeMeshInstall != "mesh_install" || TypeMeshUninstall != "mesh_uninstall" {
		t.Fatal("the wire names are part of the protocol")
	}
}

func TestPatchJobTypes(t *testing.T) {
	if TypePatchScan != "patch_scan" || TypePatchInstall != "patch_install" {
		t.Fatal("the wire names are part of the protocol")
	}
	for _, typ := range PatchTypes {
		if !IsPatchType(typ) || IsRemoteType(typ) {
			t.Errorf("%s is a patch type with its own switch, not a remote tool", typ)
		}
		for goos, want := range map[string]bool{"windows": true, "linux": true, "darwin": false} {
			if supportedOn(goos, typ) != want {
				t.Errorf("supportedOn(%s,%s)", goos, typ)
			}
		}
		if !strings.Contains(strings.Join(JobTypes(), ","), typ) {
			t.Errorf("%s not announced", typ)
		}
	}
	if IsPatchType("svc_list") || IsPatchType("patch") {
		t.Error("IsPatchType")
	}
}
