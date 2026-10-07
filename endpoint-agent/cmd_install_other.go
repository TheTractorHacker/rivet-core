//go:build !windows && !linux

package main

import (
	"fmt"
	"os"
)

func cmdInstall(args []string) int {
	fmt.Fprintln(os.Stderr, "install is supported on Windows and Linux only. Elsewhere use: rivetit-agent enroll --state-dir DIR ... && rivetit-agent run --state-dir DIR")
	return 1
}

func cmdUninstall(args []string) int {
	fmt.Fprintln(os.Stderr, "uninstall is supported on Windows and Linux only; delete the state directory by hand")
	return 1
}
