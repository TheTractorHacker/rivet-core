package patch

import (
	"encoding/base64"
	"regexp"
	"strings"
	"testing"
	"unicode/utf16"
)

// words that must never appear in scan.ps1: it is strictly read-only.
var scanForbidden = []string{
	"Invoke-Expression", "iex ", "Start-Process", "Restart-Computer", "Stop-Computer", "Start-Service", "Stop-Service", "Restart-Service", "Set-Service",
	"UsoClient", "wuauclt", "CreateUpdateInstaller", "CreateUpdateDownloader", ".Install(", ".Download(", "AcceptEula", "Remove-Item", "Set-ItemProperty",
	"New-ItemProperty", "New-Item", "Out-File", "Set-Content", "Add-Content", "Invoke-WebRequest", "Invoke-RestMethod", "Invoke-Command", "shutdown",
	"Add-Type", "[System.Diagnostics.Process]", "cmd.exe", "reg add", "sc.exe", "Hide(", "Remove-Variable", "Disable-", "Enable-", "Set-",
}

var universalForbidden = []string{"Invoke-Expression", "iex ", "Start-Process", "Restart-Computer", "Stop-Computer", "shutdown", "UsoClient", "wuauclt", "AcceptEula", "Invoke-WebRequest",
	"Invoke-RestMethod", "Add-Type", "Remove-Item", "-EncodedCommand", "RebootIfNeeded", "Reboot(", "Start-Service", "Stop-Service"}

func TestScanScriptIsReadOnly(t *testing.T) {
	s := ScanScript()
	low := strings.ToLower(s)
	for _, w := range scanForbidden {
		if strings.Contains(low, strings.ToLower(w)) {
			t.Errorf("scan.ps1 contains %q", w)
		}
	}
	for _, need := range []string{"Microsoft.Update.Session", "CreateUpdateSearcher", "IsInstalled=0 and IsHidden=0", "QueryHistory", "Get-Service", "wu_unavailable"} {
		if !strings.Contains(s, need) {
			t.Errorf("scan.ps1 lacks %q", need)
		}
	}
	// every cmdlet it uses is on a short allow-list of read-only ones
	allowed := map[string]bool{"New-Object": true, "Get-Service": true, "Test-Path": true, "Write-Output": true, "ConvertTo-Json": true, "ForEach-Object": true, "Where-Object": true, "Sort-Object": true}
	for _, m := range regexp.MustCompile(`\b([A-Z][a-z]+-[A-Z][A-Za-z]+)\b`).FindAllString(s, -1) {
		if !allowed[m] {
			t.Errorf("scan.ps1 uses cmdlet %q that is not on the read-only allow-list", m)
		}
	}
}

func TestInstallScriptInvariants(t *testing.T) {
	s := InstallScript()
	for _, w := range universalForbidden {
		if strings.Contains(strings.ToLower(s), strings.ToLower(w)) {
			t.Errorf("install.ps1 contains %q", w)
		}
	}
	// the EULA is never accepted, nothing reboots, prompts are off
	for _, need := range []string{"EulaAccepted", "eula_required", "AllowSourcePrompts = $false", "ForceQuiet = $true", "dry_run", "RIVETIT_PATCH_IDS", "RIVETIT_PATCH_MODE", "RIVETIT_PATCH_MAX_MINUTES"} {
		if !strings.Contains(s, need) {
			t.Errorf("install.ps1 lacks %q", need)
		}
	}
	// the dry run decision comes before any download or install call
	dry := strings.Index(s, "would_install")
	dl := strings.Index(s, ".Download()")
	in := strings.Index(s, ".Install()")
	if dry < 0 || dl < 0 || in < 0 || !(dry < dl && dl < in) {
		t.Errorf("order dry_run=%d download=%d install=%d", dry, dl, in)
	}
	if strings.Count(s, ".Install()") != 1 || strings.Count(s, ".Download()") != 1 {
		t.Error("exactly one download and one install call are expected")
	}
}

func TestBothScriptsAreSelfContained(t *testing.T) {
	for name, s := range map[string]string{"scan.ps1": ScanScript(), "install.ps1": InstallScript()} {
		for _, w := range universalForbidden {
			if strings.Contains(strings.ToLower(s), strings.ToLower(w)) {
				t.Errorf("%s contains %q", name, w)
			}
		}
		if !strings.Contains(s, "$ProgressPreference = 'SilentlyContinue'") {
			t.Errorf("%s must silence progress", name)
		}
		if strings.ContainsRune(s, '\r') {
			t.Errorf("%s must use LF line ends (the text is also embedded in a PHP runner)", name)
		}
		for _, r := range s {
			if r > 0x7e || (r < 0x20 && r != '\n') {
				t.Errorf("%s holds the non-ASCII or control character %U", name, r)
				break
			}
		}
		if strings.Count(s, "Write-Output") != 2 && name == "scan.ps1" {
			t.Errorf("%s: expected exactly two places that print (failure and result)", name)
		}
		// no environment variable but the documented ones is read
		for _, m := range regexp.MustCompile(`\$env:([A-Za-z_]+)`).FindAllStringSubmatch(s, -1) {
			if !strings.HasPrefix(m[1], "RIVETIT_PATCH_") {
				t.Errorf("%s reads $env:%s", name, m[1])
			}
		}
		// the script must fit a -EncodedCommand command line (30000 base64 characters) with room for the job prelude
		u := utf16.Encode([]rune(s))
		raw := make([]byte, 0, len(u)*2)
		for _, c := range u {
			raw = append(raw, byte(c), byte(c>>8))
		}
		if n := len(base64.StdEncoding.EncodeToString(raw)); n > 24000 {
			t.Errorf("%s encodes to %d characters; the command line limit is 30000 including the prelude", name, n)
		}
		// balanced braces and parentheses (a cheap guard against a broken edit; no script text is ever generated)
		for _, p := range [][2]string{{"{", "}"}, {"(", ")"}} {
			if strings.Count(s, p[0]) != strings.Count(s, p[1]) {
				t.Errorf("%s: unbalanced %s%s", name, p[0], p[1])
			}
		}
	}
}
