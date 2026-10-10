//go:build !linux && !windows

package collect

import "os/exec"

// SoftwareSupported: no software inventory on other operating systems.
func SoftwareSupported() bool { return false }

func hideProc(*exec.Cmd) {}
