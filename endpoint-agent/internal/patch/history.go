package patch

import (
	"regexp"
	"strings"
	"time"
)

// Parsers of the "when was something last installed" logs. Every one returns the zero time when it finds nothing.

// aptHistoryAt reads the tail of /var/log/apt/history.log: blocks separated by blank lines, each with "Start-Date:" / "Commandline:" /
// "Install:" or "Upgrade:" / "End-Date: 2024-05-02  06:25:12" (local time without a zone). The last block that installed or upgraded wins.
func aptHistoryAt(text string, loc *time.Location) time.Time {
	var best time.Time
	for _, block := range strings.Split(strings.ReplaceAll(text, "\r", ""), "\n\n") {
		did := false
		var end string
		for _, l := range strings.Split(block, "\n") {
			switch {
			case strings.HasPrefix(l, "Install:"), strings.HasPrefix(l, "Upgrade:"):
				did = true
			case strings.HasPrefix(l, "End-Date:"):
				end = strings.Join(strings.Fields(strings.TrimPrefix(l, "End-Date:")), " ")
			}
		}
		if !did || end == "" {
			continue
		}
		if t, err := time.ParseInLocation("2006-01-02 15:04:05", end, loc); err == nil && t.After(best) {
			best = t
		}
	}
	return best
}

var dpkgLogRe = regexp.MustCompile(`^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) status installed `)

// dpkgLogAt is the fallback: the last "status installed" line of /var/log/dpkg.log (local time).
func dpkgLogAt(text string, loc *time.Location) time.Time {
	var best time.Time
	for _, l := range strings.Split(text, "\n") {
		if m := dpkgLogRe.FindStringSubmatch(l); m != nil {
			if t, err := time.ParseInLocation("2006-01-02 15:04:05", m[1], loc); err == nil && t.After(best) {
				best = t
			}
		}
	}
	return best
}

// dnfLogAt reads /var/log/dnf.rpm.log: "2024-05-02T06:25:12Z SUBDEBUG Upgraded: pkg" (dnf 4) or "2024-05-02T06:25:12+0000 INFO ..." (dnf 5). Only
// Installed/Upgraded/Downgraded/Reinstalled lines count.
func dnfLogAt(text string) time.Time {
	var best time.Time
	for _, l := range strings.Split(text, "\n") {
		if !strings.Contains(l, "Installed:") && !strings.Contains(l, "Upgraded:") && !strings.Contains(l, "Reinstalled:") && !strings.Contains(l, "Downgraded:") {
			continue
		}
		f := strings.Fields(l)
		if len(f) < 2 {
			continue
		}
		for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05Z0700"} {
			if t, err := time.Parse(layout, f[0]); err == nil {
				if t.After(best) {
					best = t
				}
				break
			}
		}
	}
	return best
}

// zyppHistoryAt reads /var/log/zypp/history: "2024-05-02 06:25:12|install|pkg|1.2-3|x86_64|root@host|repo|hash|" (local time).
func zyppHistoryAt(text string, loc *time.Location) time.Time {
	var best time.Time
	for _, l := range strings.Split(text, "\n") {
		f := strings.SplitN(l, "|", 3)
		if len(f) < 3 || f[1] != "install" {
			continue
		}
		if t, err := time.ParseInLocation("2006-01-02 15:04:05", f[0], loc); err == nil && t.After(best) {
			best = t
		}
	}
	return best
}
