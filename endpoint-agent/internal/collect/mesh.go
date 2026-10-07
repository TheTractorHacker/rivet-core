package collect

import (
	"context"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"rivetit-agent/internal/jobs"
)

// MeshCentral node ids are url-safe-ish base64 text (e.g. 64 chars from
// [A-Za-z0-9@$]). Only the charset and length are checked here.
var meshIDRe = regexp.MustCompile(`^(node//)?[A-Za-z0-9@$_\-+/=]{20,128}$`)

// MeshNodeID reads (never installs or modifies) an existing MeshAgent's node
// id. Order: explicit config override, a mesh_node_id.txt dropped in the state
// dir by deployment tooling, then asking the installed MeshAgent binary
// (`MeshAgent.exe -nodeid`). The registry/.msh locations of the id are NOT
// documented by MeshCentral, so none is read. See README: unverified.
func (c *Collector) MeshNodeID(ctx context.Context) string {
	cfg := c.Cfg()
	if v := strings.TrimSpace(cfg.MeshNodeID); meshIDRe.MatchString(v) {
		return v
	}
	if dir := c.stateDir(); dir != "" {
		if b, err := os.ReadFile(filepath.Join(dir, "mesh_node_id.txt")); err == nil {
			if v := strings.TrimSpace(string(b)); meshIDRe.MatchString(v) {
				return v
			}
		}
	}
	dir := c.P.MeshAgentDir()
	if dir == "" {
		return ""
	}
	exe := ""
	for _, n := range []string{"MeshAgent.exe", "meshagent.exe", "meshagent"} {
		if fi, err := os.Stat(filepath.Join(dir, n)); err == nil && !fi.IsDir() {
			exe = filepath.Join(dir, n)
			break
		}
	}
	if exe == "" {
		return ""
	}
	res := jobs.RunBounded(ctx, jobs.ExecSpec{Name: exe, Args: []string{"-nodeid"}, Timeout: 10 * time.Second, MaxOutput: 512})
	if res.HaveExit && res.ExitCode == 0 {
		// take the last non-empty line; MeshAgent may print banner text
		lines := strings.Split(strings.TrimSpace(res.Output), "\n")
		if v := strings.TrimSpace(lines[len(lines)-1]); meshIDRe.MatchString(v) {
			return v
		}
	}
	return ""
}

// StateDir lets the caller point mesh_node_id.txt lookups at the state dir.
func (c *Collector) SetStateDir(d string) { c.stateDirV = d }

func (c *Collector) stateDir() string { return c.stateDirV }
