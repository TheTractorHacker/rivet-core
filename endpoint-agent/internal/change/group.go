package change

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"
)

const (
	netTimeout  = 30 * time.Second
	netMaxBytes = 1 << 20
	maxMembers  = 2000
)

var reDashes = regexp.MustCompile(`^-{10,}\s*$`)

// ParseNetLocalgroup reads the members from the output of `net localgroup <name>`.
// The output is localised, so nothing in it is matched by wording: the members are
// the lines between the line of dashes and the final line (the "command completed"
// message, in whatever language).
func ParseNetLocalgroup(out []byte) ([]string, error) {
	text := strings.ReplaceAll(string(out), "\r\n", "\n")
	text = strings.ReplaceAll(text, "\r", "\n")
	lines := strings.Split(text, "\n")
	start := -1
	for i, l := range lines {
		if reDashes.MatchString(strings.TrimSpace(l)) {
			start = i
			break
		}
	}
	if start < 0 {
		return nil, errors.New("unexpected output of net localgroup")
	}
	var members []string
	rest := lines[start+1:]
	// drop trailing blank lines, then the closing message line
	for len(rest) > 0 && strings.TrimSpace(rest[len(rest)-1]) == "" {
		rest = rest[:len(rest)-1]
	}
	if len(rest) == 0 {
		return nil, nil // an empty group still ends with the closing message... or not: no members
	}
	rest = rest[:len(rest)-1]
	for _, l := range rest {
		m := strings.TrimSpace(l)
		if m == "" || !utf8.ValidString(m) {
			continue
		}
		members = append(members, m)
		if len(members) >= maxMembers {
			break
		}
	}
	return members, nil
}

// readGroup reads the members of a local group. The name given by the server is
// tried first; if that group does not exist under that name (a localised
// Windows), the well-known Administrators group is resolved by its SID.
func (e *engine) readGroup(ctx context.Context, w Watch) (Snapshot, []string, []string) {
	snap := Snapshot{}
	members, err := e.groupMembers(ctx, w.Name)
	if err != nil && strings.EqualFold(w.Name, "Administrators") {
		if alt := e.localAdminsName(ctx); alt != "" && !strings.EqualFold(alt, w.Name) {
			members, err = e.groupMembers(ctx, alt)
		}
	}
	if err != nil {
		return snap, []string{"group:" + w.Name}, []string{"could not read the group: " + Truncate(Clean(err.Error()), 120)}
	}
	target := "group:" + w.Name
	for _, m := range members {
		name := Truncate(Clean(m), 200)
		snap[itemKey("group", target, name)] = Item{Target: target, Name: name, Hash: e.hasher.Plain([]byte(strings.ToLower(name)))}
	}
	return snap, nil, nil
}

func (e *engine) groupMembers(ctx context.Context, name string) ([]string, error) {
	if !reGroup.MatchString(name) || e.o.Runner == nil {
		return nil, errors.New("not a group name this agent will use")
	}
	res, err := e.o.Runner.Run(ctx, systemExe("net.exe"), []string{"localgroup", name}, netMaxBytes, netTimeout)
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("net localgroup exited %d", res.ExitCode)
	}
	return ParseNetLocalgroup(res.Stdout)
}

// localAdminsName resolves the name of the Administrators group by its SID
// S-1-5-32-544, for a Windows in another language.
func (e *engine) localAdminsName(ctx context.Context) string {
	const script = "(New-Object System.Security.Principal.SecurityIdentifier 'S-1-5-32-544').Translate([System.Security.Principal.NTAccount]).Value"
	res, err := e.o.Runner.Run(ctx, systemExe(`WindowsPowerShell\v1.0\powershell.exe`), []string{"-NoProfile", "-NonInteractive", "-Command", script}, 4096, netTimeout)
	if err != nil || res.ExitCode != 0 {
		return ""
	}
	v := strings.TrimSpace(string(res.Stdout))
	if i := strings.LastIndex(v, `\`); i >= 0 {
		v = v[i+1:]
	}
	if reGroup.MatchString(v) {
		return v
	}
	return ""
}
