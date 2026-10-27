//go:build devtools

// TEST/DEV ONLY. This file is compiled only with `-tags devtools`; release and
// dist builds never contain the stamp command. Production installers are
// stamped by the RivetIT server at download time.
package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"rivetit-agent/internal/embed"
	"rivetit-agent/internal/store"
)

func init() { devCommands["stamp"] = cmdStamp }

// cmdStamp: rivetit-agent stamp --in EXE --out F --server U --token T --department D [--ca PEMFILE] [--ttl 1h]
func cmdStamp(args []string) int {
	fs, _ := newFlags("stamp")
	in := fs.String("in", "", "source exe (default: this executable); an existing stamp is replaced")
	out := fs.String("out", "", "output file")
	server := fs.String("server", "", "server URL")
	token := fs.String("token", "", "enrollment token (rvte1.<selector>.<secret>)")
	dept := fs.String("department", "", "department label")
	ca := fs.String("ca", "", "PEM file with the CA certificate(s) to embed")
	ttl := fs.Duration("ttl", time.Hour, "validity")
	meshURL := fs.String("mesh-url", "", "ask the installer to also install the MeshCentral agent from this https URL (needs --mesh-group)")
	meshGroup := fs.String("mesh-group", "", "MeshCentral device group id (the meshid of the download address, or hex)")
	meshPin := fs.String("mesh-pin", "", "SHA-384/256 hex pin of the Mesh server certificate (empty = system roots)")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	if *out == "" || *server == "" || *token == "" || *dept == "" {
		fmt.Fprintln(os.Stderr, "stamp: --out, --server, --token and --department are required (TEST/DEV ONLY tool)")
		return 2
	}
	src := *in
	if src == "" {
		var err error
		if src, err = os.Executable(); err != nil {
			fmt.Fprintln(os.Stderr, "error:", err)
			return 1
		}
	}
	f, err := os.Open(src)
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	defer f.Close()
	fi, _ := f.Stat()
	n, err := embed.UnstampedSize(f, fi.Size())
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	exe := make([]byte, n)
	if _, err := f.ReadAt(exe, 0); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	now := time.Now().UTC()
	p := embed.Payload{Version: 1, InstallerID: store.NewUUID(), ServerURL: *server, EnrollmentToken: *token, Department: *dept,
		CreatedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(*ttl).Format(time.RFC3339)}
	if *ca != "" {
		b, err := os.ReadFile(*ca)
		if err != nil {
			fmt.Fprintln(os.Stderr, "error:", err)
			return 1
		}
		s := string(b)
		p.CAPEM = &s
	}
	if *meshURL != "" {
		p.InstallMeshAgent = json.RawMessage(`true`)
		spec := map[string]any{"url": *meshURL, "meshid": *meshGroup}
		if *meshPin != "" {
			spec["pin"] = *meshPin
		}
		p.Mesh, _ = json.Marshal(spec)
	}
	pl, err := embed.Marshal(p, now)
	if err != nil {
		fmt.Fprintln(os.Stderr, "stamp: payload rejected:", err)
		return 2
	}
	tmp := *out + ".tmp"
	if err := os.WriteFile(tmp, embed.Build(exe, pl), 0o755); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	if err := os.Rename(tmp, *out); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	fmt.Println("stamped", filepath.Base(*out), "for department", *dept, "(TEST/DEV ONLY)")
	return 0
}
