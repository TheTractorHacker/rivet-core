//go:build windows

package remote

import (
	"errors"
	"os"
)

// openNoFollow opens read-only. CROSS-COMPILED AND VETTED ONLY (never executed
// in this repository's tests).
func openNoFollow(path string) (*os.File, error) { return os.Open(path) }

// commitFile moves tmp onto dest; os.Rename replaces an existing file on
// Windows, so without overwrite the destination is checked just before.
func commitFile(tmp, dest string, overwrite bool) error {
	if li, err := os.Lstat(dest); err == nil {
		if !overwrite {
			return errExists
		}
		if !li.Mode().IsRegular() {
			return errors.New("destination is not a regular file")
		}
	}
	return os.Rename(tmp, dest)
}
