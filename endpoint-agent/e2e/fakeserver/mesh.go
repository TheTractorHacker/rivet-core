package main

import (
	"crypto/sha512"
	"encoding/hex"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
)

// startMockMesh serves a stand-in for a MeshCentral server's agent download: GET /meshagents?id=N&meshid=<64-character URL form>&installflags=F answers a tiny shell script that
// behaves like a MeshAgent for the three commands the installer uses (-install, -nodeid, -uninstall). It is NOT MeshCentral: it proves our side of the
// protocol (URL, pinning, running the installer, reporting the node id), not that a real Mesh server speaks it. See docs/rmm/REMOTE_AND_LOGS.md.
func startMockMesh(dir, addr, group, node string, s *server) {
	pems := selfSignedNamed(dir, "mesh_ca.pem", "mock-meshcentral")
	cert, err := tlsPair(pems)
	if err != nil {
		log.Fatal(err)
	}
	sum := sha512.Sum384(cert.Certificate[0])
	script := fmt.Sprintf(`#!/bin/sh
# FAKE MeshAgent for the e2e run
case "$1" in
  -nodeid) echo "MeshAgent (fake)"; echo '%[2]s'; exit 0;;
  -install)
    mkdir -p /usr/local/mesh_services/meshagent
    cp "$0" /usr/local/mesh_services/meshagent/meshagent
    chmod 755 /usr/local/mesh_services/meshagent/meshagent
    echo "installed by: $0 $*" >> %[1]s/mesh-installed.marker
    exit 0;;
  -uninstall)
    echo "uninstalled" >> %[1]s/mesh-uninstalled.marker
    rm -rf /usr/local/mesh_services/meshagent
    exit 0;;
esac
echo "unknown command: $*" >&2
exit 2
`, dir, node)
	mux := http.NewServeMux()
	mux.HandleFunc("/meshagents", func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		s.logf("MESH-DOWNLOAD id=%s meshid=%s installflags=%s raw=%s", q.Get("id"), q.Get("meshid"), q.Get("installflags"), r.URL.RawQuery)
		if group != "" && q.Get("meshid") != group {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "application/octet-stream")
		w.Write([]byte(script))
	})
	ln, err := newTLSListener(addr, cert)
	if err != nil {
		log.Fatal(err)
	}
	os.WriteFile(filepath.Join(dir, "mesh_url"), []byte("https://"+ln.Addr().String()), 0o644)
	os.WriteFile(filepath.Join(dir, "mesh_pin"), []byte(hex.EncodeToString(sum[:])), 0o644)
	s.logf("MESH-LISTEN https://%s pin=%s", ln.Addr().String(), hex.EncodeToString(sum[:]))
	go http.Serve(ln, mux)
}
