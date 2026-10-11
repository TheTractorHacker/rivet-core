package mesh

import (
	"bufio"
	"context"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

var (
	nodeTokenRe = regexp.MustCompile(`^(?:node//)?([A-Za-z0-9@$_-]{16,100})$`)
	labelRe     = regexp.MustCompile(`(?i)^(?:mesh\s*)?node\s*-?id\s*[:=]\s*`)
	regLineRe   = regexp.MustCompile(`(?i)^\s*(?:Mesh)?Node-?ID\s+REG_\w+\s+(\S+)\s*$`)
)

// NormalizeNodeID turns what the agent prints into the form the server maps:
// `node//<id>`. It returns false for anything that is not a single id token.
func NormalizeNodeID(s string) (string, bool) {
	s = strings.TrimSpace(s)
	m := nodeTokenRe.FindStringSubmatch(s)
	if m == nil {
		return "", false
	}
	return "node//" + m[1], true
}

// ParseNodeIDOutput reads the output of `MeshAgent -nodeid`: the id on a line
// of its own, optionally after a "NodeID:" label. Log chatter around it is
// ignored; the first line that is exactly one id token wins.
func ParseNodeIDOutput(out string) (string, bool) {
	sc := bufio.NewScanner(strings.NewReader(out))
	sc.Buffer(make([]byte, 0, 4096), 64*1024)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		line = labelRe.ReplaceAllString(line, "")
		line = strings.Trim(line, `"'`)
		if id, ok := NormalizeNodeID(line); ok {
			return id, true
		}
	}
	return "", false
}

// ParseRegistryText reads `reg query <key>` output for a NodeId value:
//
//	NodeId    REG_SZ    <id>
func ParseRegistryText(out string) (string, bool) {
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		if m := regLineRe.FindStringSubmatch(sc.Text()); m != nil {
			if id, ok := NormalizeNodeID(m[1]); ok {
				return id, true
			}
		}
	}
	return "", false
}

// MSH is the parsed MeshAgent.msh settings file (key=value lines). A standard
// .msh holds the SERVER and GROUP, not the node id; NodeID is filled only when a
// deployment's file carries one.
type MSH struct {
	Values map[string]string
	NodeID string // normalised, empty when the file has none
	MeshID string // lower-case hex of MeshID (without 0x), empty when absent
	Server string // MeshServer value
}

// ParseMSH parses the text of a .msh file. Unknown keys are kept in Values.
func ParseMSH(text string) MSH {
	m := MSH{Values: map[string]string{}}
	sc := bufio.NewScanner(strings.NewReader(text))
	for sc.Scan() {
		line := strings.TrimSpace(strings.TrimPrefix(sc.Text(), "\xef\xbb\xbf"))
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k, v = strings.TrimSpace(k), strings.Trim(strings.TrimSpace(v), `"`)
		if len(m.Values) < 64 {
			m.Values[k] = v
		}
		switch strings.ToLower(k) {
		case "nodeid":
			if id, ok := NormalizeNodeID(v); ok {
				m.NodeID = id
			}
		case "meshid":
			h := strings.ToLower(strings.TrimPrefix(strings.TrimPrefix(v, "0x"), "0X"))
			if hexRe.MatchString(h) {
				m.MeshID = h
			}
		case "meshserver":
			m.Server = v
		}
	}
	return m
}

// Probe finds the installed Mesh agent and reads its node id. Every field is a
// hook so the platform logic can be tested with fixtures on any OS.
type Probe struct {
	GOOS string
	// Paths are the candidate agent executables, in order (defaults per OS).
	Paths []string
	// Run runs a program (default: the real runner).
	Run RunFunc
	// ReadFile reads a small file (default os.ReadFile).
	ReadFile func(string) ([]byte, error)
	// Exists reports whether a file exists (default os.Stat).
	Exists func(string) bool
	// Timeout for each program run (default 10 s).
	Timeout time.Duration
}

// DefaultPaths are the usual install locations. Windows: `%ProgramFiles%\Mesh
// Agent\MeshAgent.exe` (also what `--remove-meshagent` uses). Linux: the
// install path of MeshCentral's own script, `/usr/local/mesh_services/meshagent`.
func DefaultPaths(goos string) []string {
	if goos == "windows" {
		var out []string
		for _, e := range []string{"ProgramFiles", "ProgramW6432", "ProgramFiles(x86)"} {
			if r := os.Getenv(e); r != "" {
				out = append(out, filepath.Join(r, "Mesh Agent", "MeshAgent.exe"))
			}
		}
		return out
	}
	return []string{"/usr/local/mesh_services/meshagent/meshagent", "/opt/meshagent/meshagent", "/usr/local/mesh/meshagent"}
}

// registryKeys are queried (Windows) when running the agent yields nothing.
// UNVERIFIED: whether a MeshAgent version stores its node id there.
var registryKeys = []string{`HKLM\SOFTWARE\Open Source\MeshAgent2`}

func (p *Probe) defaults() {
	if p.GOOS == "" {
		p.GOOS = "linux"
	}
	if p.Paths == nil {
		p.Paths = DefaultPaths(p.GOOS)
	}
	if p.Run == nil {
		p.Run = RunProgram
	}
	if p.ReadFile == nil {
		p.ReadFile = os.ReadFile
	}
	if p.Exists == nil {
		p.Exists = func(s string) bool { st, err := os.Stat(s); return err == nil && !st.IsDir() }
	}
	if p.Timeout <= 0 {
		p.Timeout = 10 * time.Second
	}
}

// Found returns the path of the installed Mesh agent, or "".
func (p Probe) Found() string {
	p.defaults()
	for _, c := range p.Paths {
		if c != "" && p.Exists(c) {
			return c
		}
	}
	return ""
}

// NodeID returns the local Mesh node id and where it came from ("nodeid" when
// the agent printed it, "msh", "registry"), or "" when it cannot be read.
func (p Probe) NodeID(ctx context.Context) (id, source string) {
	p.defaults()
	exe := p.Found()
	if exe == "" {
		return "", ""
	}
	rctx, cancel := context.WithTimeout(ctx, p.Timeout)
	exit, out, err := p.Run(rctx, filepath.Dir(exe), exe, []string{"-nodeid"})
	cancel()
	if err == nil && exit == 0 {
		if id, ok := ParseNodeIDOutput(out); ok {
			return id, "nodeid"
		}
	}
	for _, name := range []string{"MeshAgent.msh", "meshagent.msh"} {
		if b, err := p.ReadFile(filepath.Join(filepath.Dir(exe), name)); err == nil && len(b) < 64*1024 {
			if m := ParseMSH(string(b)); m.NodeID != "" {
				return m.NodeID, "msh"
			}
		}
	}
	if p.GOOS == "windows" {
		for _, k := range registryKeys {
			rctx, cancel := context.WithTimeout(ctx, p.Timeout)
			exit, out, err := p.Run(rctx, "", "reg.exe", []string{"query", k})
			cancel()
			if err == nil && exit == 0 {
				if id, ok := ParseRegistryText(out); ok {
					return id, "registry"
				}
			}
		}
	}
	return "", ""
}
