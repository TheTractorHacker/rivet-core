package collect

import (
	"context"
	"io"
	"os"
	"os/exec"
	"time"
)

// CmdRunner runs a program and returns its standard output. It is injectable so
// the package listers are tested without the real tools. A program that is not
// installed must yield an error satisfying errors.Is(err, exec.ErrNotFound) or
// fs.ErrNotExist (the listers then skip it silently).
type CmdRunner func(ctx context.Context, name string, args ...string) ([]byte, error)

// maxToolOutput bounds what one package tool may produce (a full dpkg or rpm
// listing is a few MB).
const maxToolOutput = 64 << 20

// execRunner is the production CmdRunner: argv only (no shell), stdout only,
// bounded output, a killable process, no console window on Windows.
func execRunner(ctx context.Context, name string, args ...string) ([]byte, error) {
	path, err := exec.LookPath(name)
	if err != nil {
		return nil, err
	}
	cmd := exec.CommandContext(ctx, path, args...)
	cmd.Env = append(os.Environ(), "LC_ALL=C", "LANG=C")
	out := &limitBuffer{max: maxToolOutput}
	cmd.Stdout, cmd.Stderr = out, io.Discard
	cmd.WaitDelay = 2 * time.Second
	hideProc(cmd)
	err = cmd.Run()
	return out.Bytes(), err
}
