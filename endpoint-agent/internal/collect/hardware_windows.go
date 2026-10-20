//go:build windows

package collect

import (
	"context"
)

// Hardware asks one FIXED PowerShell snippet (no server-controlled text) for the chassis type, battery presence and the roles' services.
func (p *WinPlatform) Hardware(ctx context.Context) (Hardware, error) {
	id, _ := p.Identity(ctx)
	deref := func(s *string) string {
		if s == nil {
			return ""
		}
		return *s
	}
	out, err := runPS(ctx, `$ErrorActionPreference='SilentlyContinue';`+
		`$ch=@((Get-CimInstance Win32_SystemEnclosure).ChassisTypes|ForEach-Object{[int]$_});`+
		`$bt=@(Get-CimInstance Win32_Battery).Count;`+
		`$sv=@(Get-Service -Name vmms,NTDS|ForEach-Object{$_.Name});`+
		`$hv=Test-Path 'HKLM:\SOFTWARE\Microsoft\Virtual Machine\Guest\Parameters';`+
		`[pscustomobject]@{chassis=$ch;battery=$bt;services=$sv;hvguest=$hv}|ConvertTo-Json -Compress`)
	if err != nil {
		return Hardware{}, err
	}
	h, ok := ParseWinHardware(out, deref(id.Manufacturer), deref(id.Model))
	if !ok {
		return Hardware{}, errBadHardware
	}
	return h, nil
}
