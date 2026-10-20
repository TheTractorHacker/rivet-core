package main

import (
	"crypto/sha512"
	"encoding/hex"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

// startMockMesh serves a stand-in for a MeshCentral server's agent download: GET /meshagents?id=N (Linux, like MeshCentral's own script) or /meshagents?id=N&meshid=<64-character URL form>&installflags=F (Windows) and GET /meshsettings?id=<meshid> answer a tiny shell script that
// behaves like a MeshAgent for the three commands the installer uses (-fullinstall, -nodeid, -fulluninstall). It is NOT MeshCentral: it proves our side of the
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
  -run) sleep 3600; exit 0;;
  -fullinstall)
    # what the real one does: copy itself and the .msh into the install dir, register a service, start it
    D=/usr/local/mesh_services/meshagent
    mkdir -p "$D"
    cp "$0" "$D/meshagent"; chmod 755 "$D/meshagent"
    case "$*" in *--copy-msh=1*) cp ./meshagent.msh "$D/meshagent.msh";; esac
    printf '#!/bin/sh\n' > /etc/init.d/meshagent; chmod 755 /etc/init.d/meshagent
    echo "installed by: $0 $*" >> %[1]s/mesh-installed.marker
    cp ./meshagent.msh %[1]s/mesh-installed.msh
    setsid "$D/meshagent" -run >/dev/null 2>&1 </dev/null &
    exit 0;;
  -fulluninstall)
    echo "uninstalled" >> %[1]s/mesh-uninstalled.marker
    for f in $(grep -la "/usr/local/mesh_services/[m]eshagent/meshagent.-run" /proc/[0-9]*/cmdline 2>/dev/null); do p=${f#/proc/}; kill "${p%%/*}" 2>/dev/null; done
    rm -rf /usr/local/mesh_services/meshagent /etc/init.d/meshagent
    exit 0;;
esac
echo "unknown command: $*" >&2
exit 2
`, dir, node)
	mux := http.NewServeMux()
	mux.HandleFunc("/meshagents", func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		s.logf("MESH-DOWNLOAD id=%s meshid=%s installflags=%s raw=%s", q.Get("id"), q.Get("meshid"), q.Get("installflags"), r.URL.RawQuery)
		if q.Get("meshid") != "" && group != "" && q.Get("meshid") != group {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "application/octet-stream")
		// agent ids 99 and 98 stand in for a hostile or broken Mesh server: a body over the agent's 64 MiB cap, announced (99) or streamed without a length (98)
		if id := q.Get("id"); id == "99" || id == "98" {
			if id == "99" {
				w.Header().Set("Content-Length", fmt.Sprint(70<<20))
			}
			chunk := []byte("#!/bin/sh\n" + strings.Repeat("#", 1<<20))
			for i := 0; i < 70; i++ {
				if _, err := w.Write(chunk); err != nil {
					return
				}
			}
			return
		}
		w.Write([]byte(script))
	})
	mux.HandleFunc("/meshsettings", func(w http.ResponseWriter, r *http.Request) {
		s.logf("MESH-SETTINGS meshid=%s raw=%s", r.URL.Query().Get("id"), r.URL.RawQuery)
		if group != "" && r.URL.Query().Get("id") != group {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "application/octet-stream")
		w.Write([]byte("MeshName=E2E\nMeshType=2\nStartupType=9\nMeshID=0x00\nServerID=00\nMeshServer=wss://mock/agent.ashx\n"))
	})
	ln, err := newTLSListener(addr, cert)
	if err != nil {
		log.Fatal(err)
	}
	s.mu.Lock()
	s.meshURL, s.meshPin, s.meshGroup = "https://"+ln.Addr().String(), hex.EncodeToString(sum[:]), group
	s.mu.Unlock()
	os.WriteFile(filepath.Join(dir, "mesh_url"), []byte("https://"+ln.Addr().String()), 0o644)
	os.WriteFile(filepath.Join(dir, "mesh_pin"), []byte(hex.EncodeToString(sum[:])), 0o644)
	s.logf("MESH-LISTEN https://%s pin=%s", ln.Addr().String(), hex.EncodeToString(sum[:]))
	go http.Serve(ln, mux)
}
