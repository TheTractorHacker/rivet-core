//go:build !windows

package jobs

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
)

// shellPath picks the interpreter: bash where installed, else POSIX sh.
func shellPath() (string, error) {
	for _, c := range []string{"/bin/bash", "/usr/bin/bash", "/bin/sh", "/usr/bin/sh"} {
		if fi, err := os.Stat(c); err == nil && fi.Mode().IsRegular() {
			return c, nil
		}
	}
	if p, err := exec.LookPath("sh"); err == nil {
		return p, nil
	}
	return "", errors.New("no bash or sh found")
}

// BuildScriptCommand prepares a shell job or script check. The script is
// written to a 0700 file inside a fresh 0700 directory and run as
// `bash <file>`: it never appears in argv (visible to every user through
// /proc), nothing is interpolated into a command line, and the script keeps
// its own stdin (unlike `bash -s`, where apt or ssh would swallow the rest of
// the script). Params travel in RIVETIT_JOB_PARAMS (environ is owner-only).
// The caller must run Cleanup when the process has ended.
func BuildScriptCommand(script string, params []byte) (ScriptCmd, error) {
	sh, err := shellPath()
	if err != nil {
		return ScriptCmd{}, err
	}
	dir, err := os.MkdirTemp("", "rivetit-job-") // 0700
	if err != nil {
		return ScriptCmd{}, fmt.Errorf("temp dir: %w", err)
	}
	cleanup := func() { _ = os.RemoveAll(dir) }
	path := filepath.Join(dir, "job.sh")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o700)
	if err != nil {
		cleanup()
		return ScriptCmd{}, fmt.Errorf("script file: %w", err)
	}
	if _, err := f.WriteString(script); err != nil {
		f.Close()
		cleanup()
		return ScriptCmd{}, fmt.Errorf("script file: %w", err)
	}
	if err := f.Close(); err != nil {
		cleanup()
		return ScriptCmd{}, err
	}
	if len(params) == 0 {
		params = []byte("null")
	}
	return ScriptCmd{Name: sh, Args: []string{path}, Env: []string{"RIVETIT_JOB_PARAMS=" + string(params)}, Cleanup: cleanup}, nil
}
