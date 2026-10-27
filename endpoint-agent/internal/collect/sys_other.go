//go:build !linux && !windows

package collect

import (
	"errors"
	"os/exec"
	"runtime"
)

func goos() string { return runtime.GOOS }

func hideWindow(*exec.Cmd) {}

func statfsInodes(string) (uint64, uint64, error) { return 0, 0, errors.New("unsupported platform") }
