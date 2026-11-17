//go:build windows

package collect

import "errors"

func mkfifo(string) error { return errors.New("no FIFOs on Windows") }
