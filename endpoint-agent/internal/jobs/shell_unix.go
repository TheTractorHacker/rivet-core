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

// pythonPath finds python3 ("" when it is not installed).
func pythonPath() string {
	for _, c := range []string{"/usr/bin/python3", "/usr/local/bin/python3", "/bin/python3"} {
		if fi, err := os.Stat(c); err == nil && fi.Mode().IsRegular() {
			return c
		}
	}
	if p, err := exec.LookPath("python3"); err == nil {
		return p
	}
	return ""
}

func pythonAvailable() bool { return pythonPath() != "" }

// BuildScriptCommand prepares a shell job or script check. The script is
// written to a 0700 file inside a fresh 0700 directory and run as
// `bash <file>`: it never appears in argv (visible to every user through
// /proc), nothing is interpolated into a command line, and the script keeps
// its own stdin (unlike `bash -s`, where apt or ssh would swallow the rest of
// the script). Params travel as RIVETIT_JOB_PARAMS (the JSON) and one
// RIVETIT_PARAM_<name> variable each (environ is owner-only); a few generated
// lines at the top define a shell variable per parameter (see params.go: a string
// is read from the environment, never written into the code).
// The caller must run Cleanup when the process has ended.
func BuildScriptCommand(script string, params []byte) (ScriptCmd, error) {
	sh, err := shellPath()
	if err != nil {
		return ScriptCmd{}, err
	}
	ps := ParseParams(params)
	return writeScript(sh, nil, "job.sh", BashPrelude(ps)+script, params, ps)
}

// BuildJobCommand prepares the interpreter invocation for a script job type
// (shell or python).
func BuildJobCommand(typ, script string, params []byte) (ScriptCmd, error) {
	if typ != TypePython {
		return BuildScriptCommand(script, params)
	}
	py := pythonPath()
	if py == "" {
		return ScriptCmd{}, errors.New("python3 is not installed on this device")
	}
	ps := ParseParams(params)
	// -I: isolated (no PYTHON* variables, no user site, the script's directory is not put on sys.path), -B: no bytecode files, -u: unbuffered output.
	return writeScript(py, []string{"-I", "-B", "-u"}, "job.py", PythonPrelude(ps)+script, params, ps)
}

func writeScript(interp string, flags []string, file, text string, params []byte, ps []Param) (ScriptCmd, error) {
	dir, err := os.MkdirTemp("", "rivetit-job-") // 0700
	if err != nil {
		return ScriptCmd{}, fmt.Errorf("temp dir: %w", err)
	}
	cleanup := func() { _ = os.RemoveAll(dir) }
	path := filepath.Join(dir, file)
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o700)
	if err != nil {
		cleanup()
		return ScriptCmd{}, fmt.Errorf("script file: %w", err)
	}
	if _, err := f.WriteString(text); err != nil {
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
	env := append([]string{"RIVETIT_JOB_PARAMS=" + string(params)}, ParamEnv(ps)...)

	return ScriptCmd{Name: interp, Args: append(append([]string(nil), flags...), path), Env: env, Cleanup: cleanup}, nil
}
