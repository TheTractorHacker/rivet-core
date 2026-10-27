//go:build windows

package change

import (
	"os"
	"path/filepath"
	"strings"
)

// MapPath maps a watch path that names C:\Windows to the real system folder (the
// system drive is not always C:).
func MapPath(p string) string {
	root := os.Getenv("SystemRoot")
	if root != "" && len(p) >= 11 && strings.EqualFold(p[1:11], `:\Windows\`) {
		return filepath.Join(root, p[11:])
	}
	return p
}

// TaskRoot is the folder of the scheduled task definitions.
func TaskRoot() string {
	root := os.Getenv("SystemRoot")
	if root == "" {
		return ""
	}
	return filepath.Join(root, "System32", "Tasks")
}
