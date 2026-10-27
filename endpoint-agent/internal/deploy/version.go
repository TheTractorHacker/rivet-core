package deploy

import (
	"strings"
)

// Version comparison for the "min" check. Deliberately tolerant: it handles dotted numbers (Windows DisplayVersion), Debian and RPM style
// versions with an epoch ("1:2.0"), release suffixes ("2.4.1-3ubuntu1") and '~' pre-releases ("1.0~rc1" is older than "1.0").
//
// A version is cut into pieces of digits or of letters; every other character separates them. Numbers compare as integers (any length), letters
// compare case-insensitively and are older than a number. A version that simply ends earlier is older than one that continues with a non-zero
// number or with letters ("1.0" < "1.0.1", "1.0" < "1.0beta"), but trailing zero pieces change nothing ("1.0" == "1.0.0").
type piece struct {
	num   bool
	text  string // digits without leading zeros, or lower-case letters
	tilde bool   // the separator before it contained '~': older than "nothing"
}

func splitEpoch(v string) (epoch, rest string) {
	if i := strings.IndexByte(v, ':'); i > 0 {
		e := v[:i]
		digits := true
		for _, c := range e {
			if c < '0' || c > '9' {
				digits = false
			}
		}
		if digits {
			return strings.TrimLeft(e, "0"), v[i+1:]
		}
	}
	return "", v
}

func pieces(v string) []piece {
	var out []piece
	tilde := false
	for i := 0; i < len(v); {
		c := v[i]
		switch {
		case c >= '0' && c <= '9':
			j := i
			for j < len(v) && v[j] >= '0' && v[j] <= '9' {
				j++
			}
			out = append(out, piece{num: true, text: strings.TrimLeft(v[i:j], "0"), tilde: tilde})
			tilde, i = false, j
		case (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z'):
			j := i
			for j < len(v) && ((v[j] >= 'a' && v[j] <= 'z') || (v[j] >= 'A' && v[j] <= 'Z')) {
				j++
			}
			out = append(out, piece{text: strings.ToLower(v[i:j]), tilde: tilde})
			tilde, i = false, j
		default:
			if c == '~' {
				tilde = true
			}
			i++
		}
	}
	return out
}

func cmpNum(a, b string) int { // both without leading zeros
	switch {
	case len(a) != len(b):
		if len(a) < len(b) {
			return -1
		}
		return 1
	case a < b:
		return -1
	case a > b:
		return 1
	}
	return 0
}

// CompareVersions returns -1, 0 or 1.
func CompareVersions(a, b string) int {
	ea, ra := splitEpoch(a)
	eb, rb := splitEpoch(b)
	if c := cmpNum(ea, eb); c != 0 {
		return c
	}
	pa, pb := pieces(ra), pieces(rb)
	for i := 0; i < len(pa) || i < len(pb); i++ {
		switch {
		case i >= len(pa): // a ended
			if pb[i].tilde {
				return 1
			}
			if pb[i].num && pb[i].text == "" {
				continue
			}
			return -1
		case i >= len(pb):
			if pa[i].tilde {
				return -1
			}
			if pa[i].num && pa[i].text == "" {
				continue
			}
			return 1
		}
		x, y := pa[i], pb[i]
		if x.tilde != y.tilde {
			if x.tilde {
				return -1
			}
			return 1
		}
		switch {
		case x.num && y.num:
			if c := cmpNum(x.text, y.text); c != 0 {
				return c
			}
		case x.num != y.num:
			if x.num {
				return 1
			}
			return -1
		default:
			if c := strings.Compare(x.text, y.text); c != 0 {
				return c
			}
		}
	}
	return 0
}

// satisfied is the "installed and new enough" verdict.
func satisfied(installed bool, version, min string) bool {
	if !installed {
		return false
	}
	if min == "" {
		return true
	}
	return version != "" && CompareVersions(version, min) >= 0
}
