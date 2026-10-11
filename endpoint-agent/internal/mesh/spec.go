// Package mesh is the OPTIONAL "also install the MeshCentral agent" feature of
// the installer, and the discovery of the local Mesh node id.
//
// The RivetIT agent never bundles a Mesh binary: a MeshCentral agent belongs to
// one customer's Mesh server and device group. When (and only when) the stamped
// installer payload asks for it, the installer downloads the agent from that
// server over HTTPS, verifies the server (system roots, or a pinned certificate
// hash), runs it, and records the outcome. Nothing in this package may ever make
// the RivetIT install fail: every function reports through a Result.
//
// UNVERIFIED against a real MeshCentral server. The download URL, the agent id
// table, the install flags and the node-id locations are written from the
// MeshCentral documentation and source as remembered, and are exercised here
// only against mock servers and fixtures. See docs/rmm/REMOTE_AND_LOGS.md.
package mesh

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"regexp"
	"strconv"
	"strings"
)

// Caps on what a payload may ask for. The agent never trusts the payload
// further than these bounds, whatever the server signed it with.
const (
	maxURL      = 500
	maxArgs     = 8
	maxArgLen   = 100
	maxAgentIDs = 16
)

var (
	hexRe  = regexp.MustCompile(`^[0-9a-f]+$`)
	argRe  = regexp.MustCompile(`^-{0,2}[A-Za-z0-9][A-Za-z0-9=_./:@-]*$`)
	osArch = regexp.MustCompile(`^(windows|linux)/[a-z0-9]{2,10}$`)
)

// DefaultAgentIDs maps GOOS/GOARCH to the MeshCentral agent id that
// `<mesh>/meshagents?id=<N>` serves. The payload may override or extend it so a
// wrong entry is corrected on the server without a new agent.
//
// VERIFY AGAINST YOUR MESH VERSION (Mesh server > My Server > Mesh Agents, or
// meshcentral/meshcentral.js `meshAgentsArchitectureNumbers`):
// 3 Windows x86-32 service, 4 Windows x86-64 service, 5 Linux x86-32,
// 6 Linux x86-64, 25 Linux ARM 32-bit, 26 Linux ARM 64-bit (aarch64).
// The Windows ARM64 id (36) is the least certain entry.
var DefaultAgentIDs = map[string]int{
	"windows/386":   3,
	"windows/amd64": 4,
	"windows/arm64": 36,
	"linux/386":     5,
	"linux/amd64":   6,
	"linux/arm64":   26,
}

// DefaultInstallArgs is what is passed to the downloaded installer, by GOOS.
// Windows: `MeshAgent.exe -fullinstall` (installs the service, using the
// settings the server embedded in the download). Linux: `./meshagent -install`.
var DefaultInstallArgs = map[string][]string{
	"windows": {"-fullinstall"},
	"linux":   {"-install"},
}

// Spec is the validated Mesh source carried in the installer payload.
type Spec struct {
	URL          string              `json:"url"`
	MeshID       string              `json:"meshid"`
	Pin          string              `json:"pin,omitempty"`
	AgentIDs     map[string]int      `json:"agent_ids,omitempty"`
	InstallArgs  map[string][]string `json:"install_args,omitempty"`
	InstallFlags int                 `json:"installflags,omitempty"`
	base         *url.URL
}

// ErrDisabled is returned by ParseSpec when the payload does not ask for Mesh.
var ErrDisabled = errors.New("mesh agent not requested")

// ParseSpec reads the two optional payload members leniently. It returns
// ErrDisabled when the install was not requested (absent, false or not a
// boolean); any other error means "requested, but the description is unusable":
// the installer then records the error and carries on with the RivetIT install.
func ParseSpec(install, spec json.RawMessage) (*Spec, error) {
	var want bool
	if len(install) == 0 || json.Unmarshal(install, &want) != nil || !want {
		return nil, ErrDisabled
	}
	if len(spec) == 0 {
		return nil, errors.New("the installer asks for the Mesh agent but carries no Mesh source")
	}
	var s Spec
	if err := json.Unmarshal(spec, &s); err != nil {
		return nil, errors.New("the Mesh source in the installer is not the expected object")
	}
	if err := s.Validate(); err != nil {
		return nil, err
	}
	return &s, nil
}

// Validate checks every field and normalises the pin to lower-case hex.
func (s *Spec) Validate() error {
	if s.URL == "" || len(s.URL) > maxURL {
		return errors.New("Mesh URL missing or too long")
	}
	u, err := url.Parse(s.URL)
	if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.Opaque != "" || u.ForceQuery {
		return errors.New("the Mesh URL must be a plain https:// address")
	}
	for _, r := range s.URL {
		if r <= 0x20 || r == 0x7f {
			return errors.New("the Mesh URL contains whitespace or control characters")
		}
	}
	s.base = u
	s.MeshID = strings.ToLower(s.MeshID)
	if n := len(s.MeshID); n < 32 || n > 128 || n%2 != 0 || !hexRe.MatchString(s.MeshID) {
		return errors.New("the Mesh device group id must be 32-128 hex characters")
	}
	if s.Pin != "" {
		p := strings.ToLower(strings.ReplaceAll(s.Pin, ":", ""))
		if (len(p) != 64 && len(p) != 96) || !hexRe.MatchString(p) {
			return errors.New("the Mesh certificate pin must be a SHA-256 (64) or SHA-384 (96) hex hash")
		}
		s.Pin = p
	}
	if len(s.AgentIDs) > maxAgentIDs {
		return errors.New("too many Mesh agent ids")
	}
	for k, v := range s.AgentIDs {
		if !osArch.MatchString(k) || v < 1 || v > 255 {
			return fmt.Errorf("invalid Mesh agent id entry %q", k)
		}
	}
	for k, args := range s.InstallArgs {
		if k != "windows" && k != "linux" {
			return fmt.Errorf("invalid install_args key %q", k)
		}
		if len(args) > maxArgs {
			return errors.New("too many install arguments")
		}
		for _, a := range args {
			if len(a) > maxArgLen || !argRe.MatchString(a) {
				return errors.New("an install argument has characters that are not allowed")
			}
		}
	}
	if s.InstallFlags < 0 || s.InstallFlags > 2 {
		return errors.New("installflags must be 0, 1 or 2")
	}
	return nil
}

// AgentID picks the agent id for a platform: the payload table first, then the
// built-in one.
func (s *Spec) AgentID(goos, goarch string) (int, bool) {
	k := goos + "/" + goarch
	if v, ok := s.AgentIDs[k]; ok {
		return v, true
	}
	v, ok := DefaultAgentIDs[k]
	return v, ok
}

// Args returns the installer arguments for goos (payload first, then default).
func (s *Spec) Args(goos string) []string {
	if a, ok := s.InstallArgs[goos]; ok && len(a) > 0 {
		return a
	}
	return DefaultInstallArgs[goos]
}

// DownloadURL is `<mesh>/meshagents?id=<N>&meshid=<group>&installflags=<F>`.
func (s *Spec) DownloadURL(id int) string {
	base := strings.TrimRight(s.URL, "/")
	return base + "/meshagents?id=" + strconv.Itoa(id) + "&meshid=" + s.MeshID + "&installflags=" + strconv.Itoa(s.InstallFlags)
}

// Host is the Mesh server's host name (for logs; never the group id).
func (s *Spec) Host() string {
	if s.base != nil {
		return s.base.Host
	}
	return ""
}

// redact removes the device group id (which would let anyone who sees a log
// download an agent that joins the group) from text.
func (s *Spec) redact(t string) string {
	if s.MeshID != "" {
		t = strings.ReplaceAll(t, s.MeshID, "[meshid]")
	}
	return t
}
