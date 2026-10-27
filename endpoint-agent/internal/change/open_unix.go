//go:build !windows

package change

import (
	"os"
	"syscall"
)

// openNoFollow opens a file read-only without blocking and without following a final symlink: a FIFO swapped in for a regular file cannot hang the poll, and a link
// swapped in after the lstat is refused (the caller also compares the opened file with the looked-at one).
func openNoFollow(path string) (*os.File, error) {
	return os.OpenFile(path, os.O_RDONLY|syscall.O_NONBLOCK|syscall.O_NOFOLLOW, 0)
}
