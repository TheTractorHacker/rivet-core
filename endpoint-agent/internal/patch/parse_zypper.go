package patch

import "strings"

// zypperPatch is one needed patch of `zypper list-patches`.
type zypperPatch struct {
	Name, Category, Severity, Interactive, Summary, Since string
}

// parseZypperPatches parses the table of `zypper --non-interactive --quiet list-patches`:
//
//	Repository | Name | Category | Severity | Interactive | Status | Since | Summary
//
// Columns are found by their header names (their order differs between zypper versions); only rows whose Status is "needed" count.
func parseZypperPatches(out string) []zypperPatch {
	var res []zypperPatch
	var col map[string]int
	for _, l := range strings.Split(out, "\n") {
		l = strings.TrimRight(l, "\r")
		if !strings.Contains(l, "|") {
			continue
		}
		cells := strings.Split(l, "|")
		for i := range cells {
			cells[i] = strings.TrimSpace(cells[i])
		}
		if col == nil {
			c := map[string]int{}
			for i, h := range cells {
				c[strings.ToLower(h)] = i
			}
			if _, a := c["name"]; a {
				if _, b := c["category"]; b {
					col = c
				}
			}
			continue
		}
		get := func(k string) string {
			if i, ok := col[k]; ok && i < len(cells) {
				return cells[i]
			}
			return ""
		}
		if st := get("status"); st != "" && st != "needed" {
			continue
		}
		if !zypperNameRe.MatchString(get("name")) {
			continue
		}
		res = append(res, zypperPatch{Name: get("name"), Category: strings.ToLower(get("category")), Severity: strings.ToLower(get("severity")),
			Interactive: strings.ToLower(get("interactive")), Summary: get("summary"), Since: get("since")})
	}
	return res
}

// zypperWouldRemove detects "The following N packages are going to be REMOVED:" in a dry run.
func zypperWouldRemove(out string) bool {
	return strings.Contains(out, "going to be REMOVED")
}
