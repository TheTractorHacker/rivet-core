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
	}
	return comparePre(va.pre, vb.pre), nil
}

// comparePre orders two non-empty pre-release strings by SemVer 2.0 section 11: dot-separated identifiers from the left, numeric ones as numbers, a
// numeric identifier below an alphanumeric one, and a shorter list below a longer one that starts the same way (CORE-DS-27: "rc.10" is above "rc.9").
func comparePre(a, b string) int {
	as, bs := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < len(as) && i < len(bs); i++ {
		x, y := as[i], bs[i]
		if x == y {
			continue
		}
		xn, xerr := strconv.ParseUint(x, 10, 64)
		yn, yerr := strconv.ParseUint(y, 10, 64)
		switch {
		case xerr == nil && yerr == nil:
			if xn != yn {
				if xn < yn {
					return -1
				}
				return 1
			}
			// equal values with different spelling (007 vs 7): fall back to text so the order stays total
			if x < y {
				return -1
			}
			return 1
		case xerr == nil:
			return -1
		case yerr == nil:
			return 1
		case x < y:
			return -1
		default:
			return 1
		}
	}
	switch {
	case len(as) < len(bs):
		return -1
	case len(as) > len(bs):
		return 1
	}
	return 0
}
