package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"runtime"
	"time"

	"rivetit-agent/internal/mesh"
)

// meshProbe finds an installed MeshCentral agent (swapped in tests).
var meshProbe = func() mesh.Probe { return mesh.Probe{GOOS: runtime.GOOS} }

// meshFlags are the `install` options for the optional Mesh agent. A stamped
// installer (`setup`) gets the same information from its payload instead.
type meshFlags struct {
	install bool
	url     string
	group   string
	pin     string
}

func (m *meshFlags) register(fs *flag.FlagSet) {
	fs.BoolVar(&m.install, "install-mesh-agent", false, "ALSO install the MeshCentral agent from your Mesh server (needs --mesh-url and --mesh-group-id); a failure never fails this install")
	fs.StringVar(&m.url, "mesh-url", "", "MeshCentral base URL (https only)")
	fs.StringVar(&m.group, "mesh-group-id", "", "MeshCentral device group id the device should join (the meshid of the Mesh download address: 64 characters, or hex)")
	fs.StringVar(&m.pin, "mesh-cert-pin", "", "SHA-384 (or SHA-256) hex hash of the Mesh server certificate; empty = verify with the system roots")
}

// raw renders the flags as the payload members ParseSpec reads.
func (m meshFlags) raw() (install, spec json.RawMessage) {
	if !m.install {
		return nil, nil
	}
	b, _ := json.Marshal(map[string]string{"url": m.url, "meshid": m.group, "pin": m.pin})
	return json.RawMessage(`true`), b
}

// installMeshAgent runs the optional Mesh agent install after the RivetIT agent
// is in place. It NEVER fails the caller: the outcome is saved to the state
// directory (reported at the next check-in) and summarised in one line, which is
// "" when Mesh was not requested.
func installMeshAgent(ctx context.Context, ops sysOps, install, specRaw json.RawMessage, stateDir string, log *slog.Logger) string {
	spec, err := mesh.ParseSpec(install, specRaw)
	if errors.Is(err, mesh.ErrDisabled) {
		return ""
	}
	save := func(r mesh.Result) {
		if err := mesh.SaveResult(stateDir, r); err != nil {
			log.Warn("could not save the Mesh agent result", "err", err)
		}
	}
	now := time.Now().UTC().Format(time.RFC3339)
	if err != nil {
		log.Warn("the Mesh agent was not installed: the installer's Mesh settings are unusable (the RivetIT agent install is not affected)", "reason", err)
		save(mesh.Result{AttemptedAt: now, Error: err.Error()})
		return "MeshCentral agent: not installed (" + err.Error() + ")."
	}
	if found := meshProbe().Found(); found != "" {
		log.Info("a MeshCentral agent is already installed; leaving it alone", "path", found)
		save(mesh.Result{AttemptedAt: now, Installed: true, Host: spec.Host()})
		return "MeshCentral agent: already installed, left untouched."
	}
	run := ops.meshInstall
	if run == nil {
		run = mesh.Install
	}
	res := run(ctx, spec, mesh.InstallOptions{GOOS: runtime.GOOS, GOARCH: runtime.GOARCH, WorkDir: stateDir, Log: log})
	save(res)
	if res.Installed {
		return "MeshCentral agent: installed."
	}
	return "MeshCentral agent: NOT installed (" + res.Error + "). The RivetIT agent is installed and unaffected."
}

// removeMeshAgentIfPresent runs the Mesh agent's own uninstaller. It is only
// called for `uninstall --remove-meshagent`; a plain uninstall never touches Mesh.
// The uninstall flag is from MeshAgent documentation as remembered
// (Windows -fulluninstall, Linux -uninstall) and was NOT run against a real agent.
func removeMeshAgentIfPresent() {
	p := meshProbe()
	exe := p.Found()
	if exe == "" {
		fmt.Println("no MeshCentral agent found")
		return
	}
	arg := "-uninstall"
	if runtime.GOOS == "windows" {
		arg = "-fulluninstall"
	}
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	run := p.Run
	if run == nil {
		run = mesh.RunProgram
	}
	exit, out, err := run(ctx, "", exe, []string{arg})
	switch {
	case err != nil:
		fmt.Fprintln(os.Stderr, "warning: MeshAgent uninstall:", err)
	case exit != 0:
		fmt.Fprintf(os.Stderr, "warning: MeshAgent uninstall exited with code %d: %s\n", exit, out)
	default:
		fmt.Println("MeshCentral agent uninstalled (as requested)")
	}
}
