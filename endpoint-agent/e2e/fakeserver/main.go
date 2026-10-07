// Command fakeserver is a minimal, contract-shaped RivetIT server for local
// end-to-end runs and footprint measurement. It is a TEST TOOL: it keeps
// everything in memory, accepts one fixed enrollment token and is not the
// real server.
package main

import (
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"flag"
	"fmt"
	"io"
	"log"
	"math/big"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"time"

	"rivetit-agent/internal/jobs"
)

type server struct {
	mu          sync.Mutex
	pub         ed25519.PublicKey
	priv        ed25519.PrivateKey
	token       string
	devTok      string
	interval    int
	collectIv   int
	jobScript   string
	jobType     string // type of e2e-job-1 (shell on Linux, powershell on Windows)
	jobQueued   bool
	jobDone     bool
	foreignJob  bool // also queue e2e-job-2 of the OTHER script type (must be answered unsupported_platform)
	foreignDone bool
	updBin      []byte // served as the update artifact when updVersion is set
	updVersion  string
	updOffer    bool
	mode        string // "": normal | offline: drop connections | disabled: 503 module_disabled
	retryAfter  string
	baseURL     string
	checkins    int
	bytesIn     int64
	bytesOut    int64
	lastSeq     float64
	revokeAfter int
	outFile     *os.File
}

func (s *server) logf(f string, a ...any) {
	line := fmt.Sprintf(f, a...)
	log.Println(line)
	if s.outFile != nil {
		fmt.Fprintln(s.outFile, line)
	}
}

func (s *server) writeJSON(w http.ResponseWriter, code int, v any) int {
	b, _ := json.Marshal(v)
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	w.Write(b)
	s.mu.Lock()
	s.bytesOut += int64(len(b))
	s.mu.Unlock()
	return len(b)
}

func (s *server) authed(r *http.Request) bool {
	return r.Header.Get("Authorization") == "Bearer "+s.devTok
}

func (s *server) enroll(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	var req struct {
		EnrollmentToken string `json:"enrollment_token"`
		Device          map[string]any
	}
	json.Unmarshal(body, &req)
	if req.EnrollmentToken != s.token {
		s.writeJSON(w, 401, map[string]any{"error": "invalid enrollment token", "code": "invalid_token"})
		return
	}
	s.logf("ENROLL install_id=%v host=%v os=%v/%v version=%v", req.Device["install_id"], req.Device["hostname"], req.Device["os"], req.Device["arch"], req.Device["agent_version"])
	s.mu.Lock()
	s.devTok = "dev_" + base64.RawURLEncoding.EncodeToString(randBytes(24))
	tok := s.devTok
	s.mu.Unlock()
	s.writeJSON(w, 201, map[string]any{
		"device_id": 1, "signing_key_id": "fake-key-1", "device_token": tok, "check_in_interval_s": s.interval,
		"server_time": time.Now().UTC().Format(time.RFC3339), "status": "linked", "matched_asset_id": 1,
		"signing_public_key": base64.StdEncoding.EncodeToString(s.pub),
		"config": map[string]any{"collect_interval_s": s.collectIv, "checks": []any{
			map[string]any{"key": "disk_root", "type": "disk", "params": map[string]any{"mount": "/", "warn_free_pct": 20, "fail_free_pct": 10}, "interval_s": 60},
			map[string]any{"key": "reboot", "type": "pending_reboot", "params": map[string]any{}, "interval_s": 60},
		}},
	})
}

func randBytes(n int) []byte { b := make([]byte, n); rand.Read(b); return b }

// gate applies the control mode (/_ctl) to a device endpoint; true means "answered".
func (s *server) gate(w http.ResponseWriter, r *http.Request, name string) bool {
	s.mu.Lock()
	mode, ra := s.mode, s.retryAfter
	s.mu.Unlock()
	switch mode {
	case "offline": // behave like a dead network: close the connection without an answer
		if hj, ok := w.(http.Hijacker); ok {
			if c, _, err := hj.Hijack(); err == nil {
				c.Close()
			}
		}
		s.logf("GATE offline %s", name)
		return true
	case "disabled":
		w.Header().Set("Retry-After", ra)
		s.writeJSON(w, 503, map[string]any{"error": "The RMM module is disabled", "code": "module_disabled"})
		s.logf("GATE disabled %s", name)
		return true
	}
	return false
}

func (s *server) updateManifest(agentVersion string) any {
	s.mu.Lock()
	defer s.mu.Unlock()
	if !s.updOffer || s.updVersion == "" || agentVersion == s.updVersion || len(s.updBin) == 0 {
		return nil
	}
	sum := sha256.Sum256(s.updBin)
	h := hex.EncodeToString(sum[:])
	return map[string]any{"version": s.updVersion, "url": s.baseURL + "/api/v1/agent_update_file", "sha256": h,
		"signature": base64.StdEncoding.EncodeToString(ed25519.Sign(s.priv, []byte(h))), "min_version": ""}
}

func (s *server) updateFile(w http.ResponseWriter, r *http.Request) {
	if !s.authed(r) {
		s.writeJSON(w, 401, map[string]any{"error": "bad token", "code": "invalid_token"})
		return
	}
	s.mu.Lock()
	b := s.updBin
	s.mu.Unlock()
	s.logf("UPDATE-DOWNLOAD bytes=%d", len(b))
	w.Header().Set("Content-Type", "application/octet-stream")
	w.Write(b)
}

func (s *server) ctl(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	s.mu.Lock()
	if m, ok := q["mode"]; ok {
		s.mode = m[0]
	}
	if u, ok := q["update"]; ok {
		s.updOffer = u[0] == "on"
	}
	if ra := q.Get("retry_after"); ra != "" {
		s.retryAfter = ra
	}
	s.mu.Unlock()
	s.mu.Lock()
	m, uo := s.mode, s.updOffer
	s.mu.Unlock()
	s.logf("CTL mode=%q update_offered=%v", m, uo)
	w.Write([]byte("ok\n"))
}

func (s *server) checkin(w http.ResponseWriter, r *http.Request) {
	if s.gate(w, r, "checkin") {
		return
	}
	body, _ := io.ReadAll(r.Body)
	if !s.authed(r) {
		s.writeJSON(w, 401, map[string]any{"error": "bad token", "code": "invalid_token"})
		return
	}
	var req map[string]any
	json.Unmarshal(body, &req)
	s.mu.Lock()
	s.checkins++
	n := s.checkins
	s.bytesIn += int64(len(body))
	seq, _ := req["seq"].(float64)
	s.lastSeq = seq
	buffered, _ := req["buffered"].([]any)
	hasInv := req["inventory"] != nil
	agentVersion, _ := req["agent_version"].(string)
	platform, _ := req["platform"].(string)
	arch, _ := req["arch"].(string)
	caps := fmt.Sprint(req["capabilities"])
	revoke := s.revokeAfter > 0 && n > s.revokeAfter
	pending := 0
	if s.jobQueued && !s.jobDone {
		pending++
	}
	if s.foreignJob && s.jobQueued && !s.foreignDone {
		pending++
	}
	s.mu.Unlock()
	if revoke {
		s.writeJSON(w, 401, map[string]any{"error": "revoked", "code": "revoked"})
		s.logf("CHECKIN #%d REVOKED", n)
		return
	}
	out := s.writeJSON(w, 200, map[string]any{"ok": true, "next_check_in_s": s.interval, "jobs_pending": pending, "status": "linked", "matched_asset_id": 1, "signing_key_id": "fake-key-1",
		"server_time": time.Now().UTC().Format(time.RFC3339), "update": s.updateManifest(agentVersion),
		"config": map[string]any{"collect_interval_s": s.collectIv, "checks": []any{
			map[string]any{"key": "disk_root", "type": "disk", "params": map[string]any{"mount": "/"}, "interval_s": 60},
			map[string]any{"key": "reboot", "type": "pending_reboot", "params": map[string]any{}, "interval_s": 60}}}})
	s.logf("CHECKIN #%d seq=%v version=%s platform=%s/%s bytes_in=%d bytes_out=%d buffered=%d inventory=%v caps=%s", n, seq, agentVersion, platform, arch, len(body), out, len(buffered), hasInv, caps)
	if inv, ok := req["inventory"].(map[string]any); ok {
		s.logf("INVENTORY os=%v os_version=%v serial=%v model=%v cpu=%v mem=%v disks=%d net=%d uptime=%v pending_reboot=%v", inv["os"], inv["os_version"], inv["serial"], inv["model"], inv["cpu"], inv["memory_total_bytes"], len(asSlice(inv["disks"])), len(asSlice(inv["network"])), inv["uptime_s"], inv["pending_reboot"])
	}
	if m, ok := req["metrics"].(map[string]any); ok {
		s.logf("METRICS cpu=%v mem=%v disk=%v rx=%v tx=%v", m["cpu_pct"], m["mem_pct"], m["disk"], m["net_rx_bps"], m["net_tx_bps"])
	}
	if checks := asSlice(req["checks"]); len(checks) > 0 {
		s.logf("CHECKS %v", checks)
	}
	if n == 1 && s.jobScript != "" {
		s.mu.Lock()
		s.jobQueued = true
		s.mu.Unlock()
	}
}

func asSlice(v any) []any { l, _ := v.([]any); return l }

func (s *server) job(id, typ, script string) json.RawMessage {
	now := time.Now().UTC()
	f := map[string]any{"job_id": id, "attempt": 1, "type": typ, "script": script,
		"params": map[string]any{}, "timeout_s": 30, "max_output_bytes": 4096,
		"issued_at": now.Format(time.RFC3339), "expires_at": now.Add(time.Hour).Format(time.RFC3339)}
	raw, _ := json.Marshal(f)
	canon, _ := jobs.Canonical(raw)
	f["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(s.priv, canon))
	raw, _ = json.Marshal(f)
	return raw
}

func (s *server) jobsH(w http.ResponseWriter, r *http.Request) {
	if s.gate(w, r, "jobs") {
		return
	}
	if !s.authed(r) {
		s.writeJSON(w, 401, map[string]any{"error": "bad token", "code": "invalid_token"})
		return
	}
	if r.Method == http.MethodGet {
		s.mu.Lock()
		q := s.jobQueued && !s.jobDone
		f := s.foreignJob && s.jobQueued && !s.foreignDone
		s.mu.Unlock()
		list := []json.RawMessage{}
		if q {
			list = append(list, s.job("e2e-job-1", s.jobType, s.jobScript))
		}
		if f {
			other := "powershell"
			if s.jobType == "powershell" {
				other = "shell"
			}
			list = append(list, s.job("e2e-job-2", other, "touch /tmp/e2e-foreign-ran"))
		}
		s.writeJSON(w, 200, map[string]any{"jobs": list})
		return
	}
	body, _ := io.ReadAll(r.Body)
	var rep map[string]any
	json.Unmarshal(body, &rep)
	s.logf("JOB-REPORT job=%v state=%v exit=%v output=%q", rep["job_id"], rep["state"], rep["exit_code"], rep["output"])
	if st, _ := rep["state"].(string); st != "running" {
		s.mu.Lock()
		if rep["job_id"] == "e2e-job-2" {
			s.foreignDone = true
		} else {
			s.jobDone = true
		}
		s.mu.Unlock()
	}
	s.writeJSON(w, 200, map[string]any{"ok": true})
}

func selfSigned(dir string) (tls_cert [2][]byte) {
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(time.Now().UnixNano()), Subject: pkix.Name{CommonName: "rivetit-fakeserver"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(48 * time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true, IsCA: true, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, DNSNames: []string{"localhost"}}
	der, _ := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	kb, _ := x509.MarshalECPrivateKey(key)
	certPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb})
	os.WriteFile(filepath.Join(dir, "ca.pem"), certPEM, 0o644)
	return [2][]byte{certPEM, keyPEM}
}

func main() {
	listen := flag.String("listen", "127.0.0.1:0", "listen address")
	dir := flag.String("dir", "", "output directory (ca.pem, url, events.log)")
	token := flag.String("token", "E2E-ENROLL-TOKEN", "accepted enrollment token")
	interval := flag.Int("interval", 5, "check-in interval seconds")
	collect := flag.Int("collect", 10, "collect interval seconds")
	script := flag.String("job-script", "", "if set, queue one signed job with this script after the first check-in")
	revoke := flag.Int("revoke-after", 0, "if >0, answer 401 revoked after this many check-ins")
	jobType := flag.String("job-type", "powershell", "type of the signed job: shell (Linux) or powershell (Windows)")
	foreign := flag.Bool("foreign-job", false, "also queue e2e-job-2 of the other script type: the agent must report unsupported_platform")
	updBin := flag.String("update-binary", "", "file served as the self-update artifact (with -update-version)")
	updVer := flag.String("update-version", "", "version offered to agents running a different version (once /_ctl?update=on)")
	ctlListen := flag.String("ctl-listen", "", "plain-HTTP 127.0.0.1:PORT (0 = any) for /_ctl; address written to <dir>/ctl (lets a shell without curl drive faults)")
	flag.Parse()
	if *dir == "" {
		log.Fatal("-dir required")
	}
	os.MkdirAll(*dir, 0o755)
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	s := &server{pub: pub, priv: priv, token: *token, interval: *interval, collectIv: *collect, jobScript: *script, revokeAfter: *revoke,
		jobType: *jobType, foreignJob: *foreign, retryAfter: "20"}
	if *updBin != "" {
		b, err := os.ReadFile(*updBin)
		if err != nil {
			log.Fatal(err)
		}
		s.updBin, s.updVersion = b, *updVer
	}
	s.outFile, _ = os.OpenFile(filepath.Join(*dir, "events.log"), os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o644)
	pems := selfSigned(*dir)
	cert, err := tlsPair(pems)
	if err != nil {
		log.Fatal(err)
	}
	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/agent_enroll", s.enroll)
	mux.HandleFunc("/api/v1/agent_checkin", s.checkin)
	mux.HandleFunc("/api/v1/agent_jobs", s.jobsH)
	mux.HandleFunc("/api/v1/agent_update_file", s.updateFile)
	mux.HandleFunc("/_ctl", s.ctl) // ?mode=offline|disabled|ok[&retry_after=N]: e2e fault injection
	mux.HandleFunc("/_stats", func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		defer s.mu.Unlock()
		json.NewEncoder(w).Encode(map[string]any{"checkins": s.checkins, "bytes_in": s.bytesIn, "bytes_out": s.bytesOut, "last_seq": s.lastSeq, "job_done": s.jobDone, "wire_in": wireIn.Load(), "wire_out": wireOut.Load()})
	})
	if *ctlListen != "" {
		cl, err := net.Listen("tcp", *ctlListen)
		if err != nil {
			log.Fatal(err)
		}
		os.WriteFile(filepath.Join(*dir, "ctl"), []byte(cl.Addr().String()), 0o644)
		cm := http.NewServeMux()
		cm.HandleFunc("/_ctl", s.ctl)
		go http.Serve(cl, cm)
	}
	ln, err := newTLSListener(*listen, cert)
	if err != nil {
		log.Fatal(err)
	}
	url := "https://" + ln.Addr().String()
	s.baseURL = url
	os.WriteFile(filepath.Join(*dir, "url"), []byte(url), 0o644)
	s.logf("LISTEN %s ca=%s", url, filepath.Join(*dir, "ca.pem"))
	log.Fatal(http.Serve(ln, mux))
}
