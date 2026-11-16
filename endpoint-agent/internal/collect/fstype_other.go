//go:build !linux

package collect

import "os"

func onPseudoFS(*os.File) bool { return false }
