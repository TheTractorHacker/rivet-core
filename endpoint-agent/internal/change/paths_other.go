//go:build !windows

package change

// MapPath is the identity off Windows.
func MapPath(p string) string { return p }

// TaskRoot is empty off Windows (there are no scheduled-task watches).
func TaskRoot() string { return "" }
