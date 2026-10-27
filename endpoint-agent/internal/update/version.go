// Package update implements verified self-update with automatic rollback.
package update

import (
	"fmt"
	"strconv"
	"strings"
)

type version struct {
	nums [4]int
	pre  string
}

// parseVersion accepts [v]MAJOR[.MINOR[.PATCH[.BUILD]]][-prerelease][+build].
// Anything else (path separators, spaces...) is rejected, so a manifest
// version can never smuggle a path into file names or logs.
func parseVersion(s string) (version, error) {
	var v version
	s = strings.TrimPrefix(strings.TrimSpace(s), "v")
	if i := strings.IndexByte(s, '+'); i >= 0 {
		s = s[:i]
	}
	if i := strings.IndexByte(s, '-'); i >= 0 {
		v.pre = s[i+1:]
		s = s[:i]
		for _, r := range v.pre {
			if !(r >= '0' && r <= '9' || r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r == '.' || r == '-') {
				return v, fmt.Errorf("invalid version %q", s)
			}
		}
		if v.pre == "" {
			return v, fmt.Errorf("invalid version: empty prerelease")
		}
	}
	parts := strings.Split(s, ".")
	if s == "" || len(parts) > 4 {
		return v, fmt.Errorf("invalid version %q", s)
	}
	for i, p := range parts {
		n, err := strconv.Atoi(p)
		if err != nil || n < 0 || len(p) > 9 {
			return v, fmt.Errorf("invalid version component %q", p)
		}
		v.nums[i] = n
	}
	return v, nil
}

// CompareVersions returns -1, 0, 1. A prerelease sorts below its release.
func CompareVersions(a, b string) (int, error) {
	va, err := parseVersion(a)
	if err != nil {
		return 0, err
	}
	vb, err := parseVersion(b)
	if err != nil {
		return 0, err
	}
	for i := range va.nums {
		if va.nums[i] != vb.nums[i] {
			if va.nums[i] < vb.nums[i] {
				return -1, nil
			}
			return 1, nil
		}
	}
	switch {
	case va.pre == vb.pre:
		return 0, nil
	case va.pre == "":
		return 1, nil
	case vb.pre == "":
		return -1, nil
	case va.pre < vb.pre:
		return -1, nil
	}
	return 1, nil
}
