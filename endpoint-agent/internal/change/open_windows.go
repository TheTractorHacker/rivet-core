//go:build windows

package change

import "os"

func openNoFollow(path string) (*os.File, error) { return os.Open(path) }
