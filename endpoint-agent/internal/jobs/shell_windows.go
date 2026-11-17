//go:build windows

package jobs

import (
	"encoding/base64"
	"errors"
	"os"
	"path/filepath"
	"unicode/utf16"
)

const maxEncodedCommandChars = 30000 // CreateProcess command line limit is 32767

// BuildScriptCommand builds the PowerShell invocation. The script travels in a
// base64 -EncodedCommand; the params travel ONLY in the environment (see
// PowerShellParamsSetup), so no server-controlled value is ever interpolated
// into a command line or shell string, or recorded with the command text.
func BuildScriptCommand(script string, params []byte) (ScriptCmd, error) {
	prelude := "$ProgressPreference='SilentlyContinue';[Console]::OutputEncoding=[Text.Encoding]::UTF8;"
	pp, env, err := PowerShellParamsSetup(params)
	if err != nil {
		return ScriptCmd{}, err
	}
	prelude += pp
	u16 := utf16.Encode([]rune(prelude + script))
	raw := make([]byte, 0, len(u16)*2)
	for _, c := range u16 {
		raw = append(raw, byte(c), byte(c>>8))
	}
	enc := base64.StdEncoding.EncodeToString(raw)
	if len(enc) > maxEncodedCommandChars {
		return ScriptCmd{}, errors.New("script too large for -EncodedCommand")
	}
	ps := filepath.Join(os.Getenv("SystemRoot"), "System32", "WindowsPowerShell", "v1.0", "powershell.exe")
	return ScriptCmd{Name: ps, Args: []string{"-NoProfile", "-NonInteractive", "-OutputFormat", "Text", "-ExecutionPolicy", "Bypass", "-EncodedCommand", enc}, Env: env}, nil
}

// BuildJobCommand is BuildScriptCommand on Windows: PowerShell is the only script runner.
func BuildJobCommand(typ, script string, params []byte) (ScriptCmd, error) {
	return BuildScriptCommand(script, params)
}

func pythonAvailable() bool { return false }
