package collect

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/binary"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"log"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/store"
)

func cfixture(t *testing.T, name string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("testdata", "checks", name))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func xspec(typ, params string) store.CheckSpec {
	return store.CheckSpec{Key: "k", Type: typ, Params: json.RawMessage(params)}
}

func assertRes(t *testing.T, r api.CheckResult, status string, value *float64, detailHas string) {
	t.Helper()
	if r.Status != status {
		t.Fatalf("status %s (%q), want %s", r.Status, r.Detail, status)
	}
	if (value == nil) != (r.Value == nil) || (value != nil && *r.Value != *value) {
		t.Fatalf("value %v, want %v (%q)", ptrStr(r.Value), ptrStr(value), r.Detail)
	}
	if detailHas != "" && !strings.Contains(r.Detail, detailHas) {
		t.Fatalf("detail %q does not contain %q", r.Detail, detailHas)
	}
}

func ptrStr(p *float64) string {
	if p == nil {
		return "nil"
	}
	b, _ := json.Marshal(*p)
	return string(b)
}

func f64(v float64) *float64 { return &v }

// extCol is a collector whose OS access is entirely fake.
func extCol(goos string) (*Collector, *fake, *Sys) {
	f := newFake()
	c := newCol(f)
	x := &Sys{GOOS: goos,
		Exec: func(context.Context, time.Duration, string, ...string) (string, int, error) {
			return "", 0, errors.New("exec not faked")
		},
		ReadFile: func(string) ([]byte, error) { return nil, os.ErrNotExist },
		ReadDir:  func(string) ([]os.DirEntry, error) { return nil, os.ErrNotExist },
		Sleep:    func(context.Context, time.Duration) {},
		Statfs:   func(string) (uint64, uint64, error) { return 0, 0, errors.New("statfs not faked") },
	}
	c.X = x
	return c, f, x
}

func TestExtendedTypesPerPlatform(t *testing.T) {
	has := func(goos, typ string) bool {
		for _, e := range ExtendedTypes(goos) {
			if e == typ {
				return true
			}
		}
		return false
	}
	for _, typ := range []string{"cpu", "memory", "process", "port", "ping", "http", "cert", "ntp", "smart"} {
		if !has("windows", typ) || !has("linux", typ) {
			t.Fatalf("%s must exist on both", typ)
		}
	}
	if !has("windows", "av") || has("linux", "av") || has("windows", "systemd_failed") || !has("linux", "systemd_failed") || has("windows", "inodes") || !has("linux", "inodes") {
		t.Fatal("platform-only types are wrong")
	}
	if len(allExtended) != 12 {
		t.Fatal("allExtended is out of step")
	}
	c, _, _ := extCol("linux")
	r := c.RunCheck(context.Background(), xspec("av", `{}`))
	assertRes(t, r, Unknown, nil, "not available on linux")
	r = c.RunCheck(context.Background(), xspec("nonsense", `{}`))
	assertRes(t, r, Unknown, nil, "unsupported check type")
}

func TestLegacyResultsCarryNoValue(t *testing.T) {
	b, _ := json.Marshal(res("k", OK, "x"))
	if string(b) != `{"key":"k","status":"ok","detail":"x"}` {
		t.Fatalf("a legacy result must stay byte-identical: %s", b)
	}
	b, _ = json.Marshal(resv("k", OK, "x", 12.3456))
	if string(b) != `{"key":"k","status":"ok","detail":"x","value":12.35}` {
		t.Fatalf("%s", b)
	}
}

// ---------------------------------------------------------------- parsers

func TestParsePing(t *testing.T) {
	cases := []struct {
		file string
		want pingResult
	}{
		{"ping_linux.txt", pingResult{3, 3, 0, 0.400}},
		{"ping_linux_loss.txt", pingResult{4, 2, 50, 25.0}},
		{"ping_busybox.txt", pingResult{2, 2, 0, 12.0}},
		{"ping_windows.txt", pingResult{3, 3, 0, 1}},
		{"ping_windows_loss.txt", pingResult{4, 1, 75, 18}},
		{"ping_linux_down.txt", pingResult{3, 0, 100, 0}},
	}
	for _, c := range cases {
		got, err := parsePing(cfixture(t, c.file))
		if err != nil || got != c.want {
			t.Fatalf("%s: %+v %v, want %+v", c.file, got, err, c.want)
		}
	}
	if _, err := parsePing("garbage"); err == nil {
		t.Fatal("garbage must be an error")
	}
}

func TestParseMemoryFiles(t *testing.T) {
	total, free, err := parseSwap(cfixture(t, "meminfo.txt"))
	if err != nil || total != 4194304 || free != 3145728 {
		t.Fatalf("%d %d %v", total, free, err)
	}
	if _, _, err := parseSwap("MemTotal: 1 kB"); err == nil {
		t.Fatal("missing swap lines")
	}
	v, err := parsePSI(cfixture(t, "psi_memory.txt"))
	if err != nil || v != 12.34 {
		t.Fatalf("%v %v", v, err)
	}
	if _, err := parsePSI("nope"); err == nil {
		t.Fatal("bad psi")
	}
}

func TestProcessMatching(t *testing.T) {
	names := parseTasklist(cfixture(t, "tasklist.csv"))
	if len(names) != 6 || names[2] != "svchost.exe" {
		t.Fatalf("%v", names)
	}
	for _, c := range []struct {
		want string
		n    int
	}{{"svchost", 2}, {"SVCHOST.EXE", 2}, {"msmpeng.exe", 1}, {"nothere", 0}, {"System", 1}} {
		if got := countProcesses(names, c.want); got != c.n {
			t.Fatalf("%s: %d want %d", c.want, got, c.n)
		}
	}
	// the kernel cuts comm to 15 characters
	if countProcesses([]string{"very-long-proces"[:15]}, "very-long-process-name") != 1 {
		t.Fatal("comm truncation")
	}
	if !isPID("1234") || isPID("self") || isPID("") {
		t.Fatal("isPID")
	}
}

func TestParseSmartAndDisks(t *testing.T) {
	if got := parseSmartScan(cfixture(t, "smartctl_scan.json")); len(got) != 2 || got[1] != "/dev/nvme0" {
		t.Fatalf("%v", got)
	}
	ok, _ := parseSmartctl("/dev/sda", cfixture(t, "smartctl_ok.json"))
	bad, _ := parseSmartctl("/dev/sdb", cfixture(t, "smartctl_fail.json"))
	none, _ := parseSmartctl("/dev/sdc", cfixture(t, "smartctl_nostatus.json"))
	if ok.Passed == nil || !*ok.Passed || bad.Passed == nil || *bad.Passed || none.Passed != nil || !strings.Contains(none.Note, "Permission denied") {
		t.Fatalf("%+v %+v %+v", ok, bad, none)
	}
	if _, err := parseSmartctl("x", "not json"); err == nil {
		t.Fatal("bad json")
	}
	d, err := parsePhysicalDisks(cfixture(t, "physicaldisk_array.json"))
	if err != nil || len(d) != 2 || d[1].Health != "Warning" {
		t.Fatalf("%+v %v", d, err)
	}
	d, err = parsePhysicalDisks(cfixture(t, "physicaldisk_single_numeric.json"))
	if err != nil || len(d) != 1 || d[0].Health != "Unhealthy" || d[0].Operational != "Unhealthy" {
		t.Fatalf("%+v %v", d, err)
	}
	if _, err := parsePhysicalDisks(""); err == nil {
		t.Fatal("empty")
	}
}

func TestParseDefenderUnitsAndStore(t *testing.T) {
	d, err := parseDefender(cfixture(t, "defender_ok.json"))
	if err != nil || !d.AVEnabled || !d.RealTime || d.SignatureAgeDays != 1 {
		t.Fatalf("%+v %v", d, err)
	}
	if _, err := parseDefender(""); err == nil || !strings.Contains(err.Error(), "third-party") {
		t.Fatalf("%v", err)
	}
	if _, err := parseDefender(`{"AntivirusEnabled":true}`); err == nil {
		t.Fatal("incomplete")
	}
	units := parseFailedUnits(cfixture(t, "systemctl_failed.txt"))
	if len(units) != 3 || units[1] != "snapd.service" || units[2] != "user@1000.service" {
		t.Fatalf("%v", units)
	}
	if !ignored("user@1000.service", []string{"user@*"}) || !ignored("snapd.service", []string{"snapd.service"}) || ignored("snapd.service", []string{"snap"}) {
		t.Fatal("ignore matching")
	}
	names, ats := parseCertStore(cfixture(t, "certstore.txt"))
	if len(names) != 3 || names[2] != "CN=old.corp.test" || ats[2].Year() != 2026 || ats[2].Month() != 11 {
		t.Fatalf("%v %v", names, ats)
	}
}

// ---------------------------------------------------------------- cpu and memory

func TestCPUCheck(t *testing.T) {
	c, f, x := extCol("linux")
	f.idle, f.total = 100, 200
	x.Sleep = func(context.Context, time.Duration) { f.idle, f.total = f.idle+5, f.total+100 } // 95% busy
	assertRes(t, c.RunCheck(context.Background(), xspec("cpu", `{"window_s":2}`)), Warn, f64(95), "95% busy over 2s")
	f.idle, f.total = 100, 200
	x.Sleep = func(context.Context, time.Duration) { f.idle, f.total = f.idle+1, f.total+100 } // 99%
	assertRes(t, c.RunCheck(context.Background(), xspec("cpu", `{}`)), Fail, f64(99), "")
	f.idle, f.total = 0, 0
	x.Sleep = func(context.Context, time.Duration) { f.idle, f.total = 50, 100 }
	assertRes(t, c.RunCheck(context.Background(), xspec("cpu", `{"warn_pct":60,"fail_pct":70}`)), OK, f64(50), "")
	x.Sleep = func(context.Context, time.Duration) {} // counters did not advance
	assertRes(t, c.RunCheck(context.Background(), xspec("cpu", `{}`)), Unknown, nil, "did not advance")
	f.fail["cpu"] = true
	assertRes(t, c.RunCheck(context.Background(), xspec("cpu", `{}`)), Unknown, nil, "broke")
	assertRes(t, c.RunCheck(context.Background(), xspec("cpu", `[1]`)), Unknown, nil, "invalid cpu")
}

func TestMemoryCheck(t *testing.T) {
	c, f, x := extCol("linux")
	f.memT, f.memA = 1000, 100 // 90% used
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{}`)), Warn, f64(90), "90% used")
	f.memA = 20 // 98%
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{}`)), Fail, f64(98), "")
	f.memA = 900
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{"warn_pct":50,"fail_pct":60}`)), OK, f64(10), "")
	f.memT = 0
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{}`)), Unknown, nil, "unknown")

	files := map[string]string{"/proc/meminfo": cfixture(t, "meminfo.txt"), "/proc/pressure/memory": cfixture(t, "psi_memory.txt")}
	x.ReadFile = func(p string) ([]byte, error) {
		if v, ok := files[p]; ok {
			return []byte(v), nil
		}
		return nil, os.ErrNotExist
	}
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{"metric":"swap_used_pct","warn_pct":20,"fail_pct":50}`)), Warn, f64(25), "swap 25% used")
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{"metric":"psi_some_avg10","warn_pct":10,"fail_pct":50}`)), Warn, f64(12.34), "12.3")
	files["/proc/meminfo"] = cfixture(t, "meminfo_noswap.txt")
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{"metric":"swap_used_pct"}`)), OK, f64(0), "no swap")
	delete(files, "/proc/pressure/memory")
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{"metric":"psi_some_avg10"}`)), Unknown, nil, "not enabled")
	assertRes(t, c.RunCheck(context.Background(), xspec("memory", `{"metric":"bogus"}`)), Unknown, nil, "unknown memory metric")
	w, _, _ := extCol("windows")
	assertRes(t, w.RunCheck(context.Background(), xspec("memory", `{"metric":"swap_used_pct"}`)), Unknown, nil, "only available on Linux")
}

// ---------------------------------------------------------------- process

type fakeEntry struct{ name string }

func (e fakeEntry) Name() string               { return e.name }
func (e fakeEntry) IsDir() bool                { return true }
func (e fakeEntry) Type() os.FileMode          { return os.ModeDir }
func (e fakeEntry) Info() (os.FileInfo, error) { return nil, errors.New("n/a") }

func TestProcessCheck(t *testing.T) {
	c, _, x := extCol("linux")
	comm := map[string]string{"/proc/1/comm": "systemd\n", "/proc/812/comm": "nginx\n", "/proc/813/comm": "nginx\n", "/proc/900/comm": "sshd\n"}
	x.ReadDir = func(string) ([]os.DirEntry, error) {
		return []os.DirEntry{fakeEntry{"1"}, fakeEntry{"812"}, fakeEntry{"813"}, fakeEntry{"900"}, fakeEntry{"self"}, fakeEntry{"999"}}, nil // 999 vanished
	}
	x.ReadFile = func(p string) ([]byte, error) {
		if v, ok := comm[p]; ok {
			return []byte(v), nil
		}
		return nil, os.ErrNotExist
	}
	assertRes(t, c.RunCheck(context.Background(), xspec("process", `{"name":"nginx"}`)), OK, f64(2), "2 nginx running")
	assertRes(t, c.RunCheck(context.Background(), xspec("process", `{"name":"nginx","min_count":3}`)), Fail, f64(2), "at least 3")
	assertRes(t, c.RunCheck(context.Background(), xspec("process", `{"name":"nginx","max_count":1}`)), Fail, f64(2), "at most 1")
	assertRes(t, c.RunCheck(context.Background(), xspec("process", `{"name":"postgres"}`)), Fail, f64(0), "0 postgres")
	assertRes(t, c.RunCheck(context.Background(), xspec("process", `{"name":"cron","min_count":0}`)), OK, f64(0), "")
	assertRes(t, c.RunCheck(context.Background(), xspec("process", `{}`)), Unknown, nil, "invalid process")

	w, _, wx := extCol("windows")
	wx.Exec = func(_ context.Context, _ time.Duration, name string, args ...string) (string, int, error) {
		if name != "tasklist" {
			t.Fatalf("ran %s", name)
		}
		return cfixture(t, "tasklist.csv"), 0, nil
	}
	assertRes(t, w.RunCheck(context.Background(), xspec("process", `{"name":"svchost.exe","min_count":2}`)), OK, f64(2), "")
	assertRes(t, w.RunCheck(context.Background(), xspec("process", `{"name":"MsMpEng"}`)), OK, f64(1), "")
	wx.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) {
		return "", -1, errors.New("tasklist timed out")
	}
	assertRes(t, w.RunCheck(context.Background(), xspec("process", `{"name":"x"}`)), Unknown, nil, "cannot list")
}

// ---------------------------------------------------------------- port

func TestPortCheck(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Skip("no loopback")
	}
	defer ln.Close()
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			c.Close()
		}
	}()
	c := newCol(newFake())
	port := ln.Addr().(*net.TCPAddr).Port
	r := c.RunCheck(context.Background(), xspec("port", `{"host":"127.0.0.1","port":`+itoa(port)+`}`))
	if r.Status != OK || r.Value == nil || !strings.Contains(r.Detail, "open") {
		t.Fatalf("%+v", r)
	}
	ln.Close()
	r = c.RunCheck(context.Background(), xspec("port", `{"host":"127.0.0.1","port":`+itoa(port)+`,"timeout_ms":500}`))
	if r.Status != Fail || r.Value != nil || !strings.Contains(r.Detail, "refused") {
		t.Fatalf("%+v", r)
	}
	for _, bad := range []string{`{"host":"-oProxy","port":80}`, `{"host":"a b","port":80}`, `{"host":"example.test","port":0}`, `{"host":"example.test","port":70000}`, `{}`} {
		if r := c.RunCheck(context.Background(), xspec("port", bad)); r.Status != Unknown {
			t.Fatalf("%s: %+v", bad, r)
		}
	}
	// the latency warning: a faked slow connection
	cc, _, x := extCol("linux")
	now := time.Unix(100, 0)
	x.Now = func() time.Time { return now }
	x.Dial = func(context.Context, string, string) (net.Conn, error) {
		now = now.Add(750 * time.Millisecond)
		a, b := net.Pipe()
		b.Close()
		return a, nil
	}
	r = cc.RunCheck(context.Background(), xspec("port", `{"host":"db.corp.test","port":5432,"warn_ms":500}`))
	if r.Status != Warn || r.Value == nil || *r.Value != 750 {
		t.Fatalf("%+v", r)
	}
	x.Dial = func(context.Context, string, string) (net.Conn, error) { return nil, context.DeadlineExceeded }
	r = cc.RunCheck(context.Background(), xspec("port", `{"host":"db.corp.test","port":5432}`))
	if r.Status != Fail || !strings.Contains(r.Detail, "timed out") {
		t.Fatalf("%+v", r)
	}
}

func itoa(i int) string { return strconv.Itoa(i) }

// ---------------------------------------------------------------- ping

func TestPingCheck(t *testing.T) {
	c, _, x := extCol("linux")
	var gotArgs []string
	out := ""
	x.Exec = func(_ context.Context, _ time.Duration, name string, args ...string) (string, int, error) {
		gotArgs = append([]string{name}, args...)
		return out, 0, nil
	}
	out = cfixture(t, "ping_linux.txt")
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.1"}`)), OK, f64(0.4), "0% loss")
	if strings.Join(gotArgs, " ") != "ping -n -c 3 -W 2 10.0.0.1" {
		t.Fatalf("%v", gotArgs)
	}
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.1","warn_ms":0}`)), OK, f64(0.4), "")
	out = cfixture(t, "ping_linux_loss.txt")
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.9","count":4}`)), Warn, f64(25), "50% loss")
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.9","warn_loss_pct":60,"fail_loss_pct":90,"warn_ms":20,"fail_ms":30}`)), Warn, f64(25), "")
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.9","warn_loss_pct":60,"fail_loss_pct":90,"fail_ms":20}`)), Fail, f64(25), "")
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.9","fail_loss_pct":50}`)), Fail, f64(25), "")
	out = cfixture(t, "ping_linux_down.txt")
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.77"}`)), Fail, nil, "no reply")
	out = "garbage"
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.1"}`)), Unknown, nil, "could not read")
	x.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) {
		return "", -1, errors.New("exec: \"ping\": not found")
	}
	assertRes(t, c.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.1"}`)), Unknown, nil, "could not run")
	for _, bad := range []string{`{"host":"-f"}`, `{"host":"a;b"}`, `{}`, `{"host":"x y"}`} {
		assertRes(t, c.RunCheck(context.Background(), xspec("ping", bad)), Unknown, nil, "invalid ping")
	}

	w, _, wx := extCol("windows")
	wx.Exec = func(_ context.Context, _ time.Duration, name string, args ...string) (string, int, error) {
		gotArgs = append([]string{name}, args...)
		return cfixture(t, "ping_windows.txt"), 0, nil
	}
	assertRes(t, w.RunCheck(context.Background(), xspec("ping", `{"host":"10.0.0.1","count":3,"timeout_ms":1500}`)), OK, f64(1), "")
	if strings.Join(gotArgs, " ") != "ping -n 3 -w 1500 10.0.0.1" {
		t.Fatalf("%v", gotArgs)
	}
}

// ---------------------------------------------------------------- http

func TestHTTPCheck(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/ok", func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("hello healthy world")) })
	mux.HandleFunc("/err", func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(500) })
	mux.HandleFunc("/slow", func(w http.ResponseWriter, r *http.Request) {
		time.Sleep(700 * time.Millisecond)
		_, _ = w.Write([]byte("x"))
	})
	mux.HandleFunc("/redir", func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, "/ok", http.StatusFound) })
	srv := httptest.NewServer(mux)
	defer srv.Close()
	c := newCol(newFake())
	c.Timeout = 5 * time.Second
	run := func(p string) api.CheckResult { return c.RunCheck(context.Background(), xspec("http", p)) }

	r := run(`{"url":"` + srv.URL + `/ok","contains":"healthy"}`)
	if r.Status != OK || r.Value == nil || !strings.Contains(r.Detail, "200") {
		t.Fatalf("%+v", r)
	}
	if r = run(`{"url":"` + srv.URL + `/ok","contains":"missing text"}`); r.Status != Fail || !strings.Contains(r.Detail, "expected text not found") {
		t.Fatalf("%+v", r)
	}
	if r = run(`{"url":"` + srv.URL + `/err"}`); r.Status != Fail || !strings.Contains(r.Detail, "status 500") {
		t.Fatalf("%+v", r)
	}
	if r = run(`{"url":"` + srv.URL + `/err","expect_min":500,"expect_max":599}`); r.Status != OK {
		t.Fatalf("%+v", r)
	}
	if r = run(`{"url":"` + srv.URL + `/slow","warn_ms":300}`); r.Status != Warn || !strings.Contains(r.Detail, "slow") {
		t.Fatalf("%+v", r)
	}
	if r = run(`{"url":"` + srv.URL + `/redir"}`); r.Status != OK {
		t.Fatalf("redirect followed: %+v", r)
	}
	if r = run(`{"url":"` + srv.URL + `/redir","follow_redirects":false,"expect_min":200,"expect_max":299}`); r.Status != Fail || !strings.Contains(r.Detail, "status 302") {
		t.Fatalf("redirect not followed: %+v", r)
	}
	if r = run(`{"url":"` + srv.URL + `/ok","method":"HEAD"}`); r.Status != OK {
		t.Fatalf("%+v", r)
	}
	// secrets in the URL never reach the detail
	if r = run(`{"url":"` + srv.URL + `/err?token=SECRET#frag"}`); strings.Contains(r.Detail, "SECRET") || strings.Contains(r.Detail, "token") {
		t.Fatalf("the query leaked: %q", r.Detail)
	}
	srv.Close()
	if r = run(`{"url":"` + srv.URL + `/ok","timeout_ms":800}`); r.Status != Fail || strings.Contains(r.Detail, "?") {
		t.Fatalf("%+v", r)
	}
	for _, bad := range []string{`{}`, `{"url":"ftp://x"}`, `{"url":"http://user:pw@host/"}`, `{"url":"not a url"}`} {
		if r := run(bad); r.Status != Unknown {
			t.Fatalf("%s: %+v", bad, r)
		}
	}
}

func selfSigned(t *testing.T, notAfter time.Time) (tls.Certificate, []byte) {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tpl := &x509.Certificate{SerialNumber: big.NewInt(time.Now().UnixNano()), Subject: pkix.Name{CommonName: "check.test"}, NotBefore: time.Now().Add(-24 * time.Hour), NotAfter: notAfter,
		DNSNames: []string{"check.test", "localhost"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return tls.Certificate{Certificate: [][]byte{der}, PrivateKey: key}, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
}

func tlsServer(t *testing.T, notAfter time.Time) *httptest.Server {
	cert, _ := selfSigned(t, notAfter)
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("ok")) }))
	srv.TLS = &tls.Config{Certificates: []tls.Certificate{cert}}
	srv.Config.ErrorLog = log.New(io.Discard, "", 0)
	srv.StartTLS()
	return srv
}

func TestHTTPCertificateExpiry(t *testing.T) {
	c := newCol(newFake())
	c.Timeout = 5 * time.Second
	soon := tlsServer(t, time.Now().Add(10*24*time.Hour+time.Hour))
	defer soon.Close()
	r := c.RunCheck(context.Background(), xspec("http", `{"url":"`+soon.URL+`/","verify_tls":false}`))
	if r.Status != Warn || !strings.Contains(r.Detail, "certificate expires in 10 days") {
		t.Fatalf("default warn at 14 days: %+v", r)
	}
	r = c.RunCheck(context.Background(), xspec("http", `{"url":"`+soon.URL+`/","verify_tls":false,"cert_warn_days":5,"cert_fail_days":2}`))
	if r.Status != OK {
		t.Fatalf("%+v", r)
	}
	r = c.RunCheck(context.Background(), xspec("http", `{"url":"`+soon.URL+`/","verify_tls":false,"cert_warn_days":30,"cert_fail_days":12}`))
	if r.Status != Fail {
		t.Fatalf("%+v", r)
	}
	r = c.RunCheck(context.Background(), xspec("http", `{"url":"`+soon.URL+`/","verify_tls":false,"cert_warn_days":0,"cert_fail_days":0}`))
	if r.Status != OK {
		t.Fatalf("expiry checks switched off: %+v", r)
	}
	// verification is on by default: a self-signed certificate is refused, with no URL in the detail
	r = c.RunCheck(context.Background(), xspec("http", `{"url":"`+soon.URL+`/x?secret=1"}`))
	if r.Status != Fail || strings.Contains(r.Detail, "secret") {
		t.Fatalf("%+v", r)
	}
}

// ---------------------------------------------------------------- cert

func TestCertCheckFilesDirsAndEndpoint(t *testing.T) {
	dir := t.TempDir()
	_, p1 := selfSigned(t, time.Now().Add(40*24*time.Hour+time.Hour))
	_, p2 := selfSigned(t, time.Now().Add(5*24*time.Hour+time.Hour))
	_, p3 := selfSigned(t, time.Now().Add(-3*24*time.Hour-time.Hour))
	write := func(name string, b []byte) string {
		p := filepath.Join(dir, name)
		if err := os.WriteFile(p, b, 0o600); err != nil {
			t.Fatal(err)
		}
		return p
	}
	good, soon, dead := write("good.pem", p1), write("soon.crt", p2), write("dead.pem", p3)
	write("notes.txt", []byte("ignore me"))
	write("junk.pem", []byte("not a certificate"))
	c := newCol(newFake())
	c.Timeout = 5 * time.Second
	run := func(p string) api.CheckResult { return c.RunCheck(context.Background(), xspec("cert", p)) }

	r := run(`{"paths":["` + good + `"]}`)
	if r.Status != OK || r.Value == nil || *r.Value != 40 {
		t.Fatalf("%+v", r)
	}
	r = run(`{"paths":["` + good + `","` + soon + `"]}`)
	if r.Status != Fail || *r.Value != 5 || !strings.Contains(r.Detail, "soon.crt") {
		t.Fatalf("the soonest wins, 5 days is under the default fail of 7: %+v", r)
	}
	r = run(`{"paths":["` + soon + `"],"warn_days":10,"fail_days":3}`)
	if r.Status != Warn {
		t.Fatalf("%+v", r)
	}
	r = run(`{"paths":["` + dead + `"]}`)
	if r.Status != Fail || *r.Value != -4 || !strings.Contains(r.Detail, "expired 4 days ago") {
		t.Fatalf("%+v", r)
	}
	r = run(`{"paths":["` + dir + `"]}`)
	if r.Status != Fail || *r.Value != -4 || !strings.Contains(r.Detail, "junk.pem: no certificate") {
		t.Fatalf("a directory is scanned for .pem/.crt/.cer; unreadable files are reported: %+v", r)
	}
	r = run(`{"paths":["` + filepath.Join(dir, "missing.pem") + `"]}`)
	if r.Status != Unknown {
		t.Fatalf("%+v", r)
	}
	if r = run(`{}`); r.Status != Unknown {
		t.Fatalf("%+v", r)
	}

	srv := tlsServer(t, time.Now().Add(20*24*time.Hour+time.Hour))
	defer srv.Close()
	_, port, _ := net.SplitHostPort(strings.TrimPrefix(srv.URL, "https://"))
	r = run(`{"host":"127.0.0.1","port":` + port + `,"warn_days":30,"fail_days":7}`)
	if r.Status != Warn || *r.Value != 20 {
		t.Fatalf("%+v", r)
	}

	w, _, wx := extCol("windows")
	wx.Exec = func(_ context.Context, _ time.Duration, name string, args ...string) (string, int, error) {
		if name != "powershell.exe" || !strings.Contains(strings.Join(args, " "), `Cert:\LocalMachine\My`) {
			t.Fatalf("%s %v", name, args)
		}
		return strings.ReplaceAll(cfixture(t, "certstore.txt"), "2026-11-03T08:30:00Z", time.Now().Add(9*24*time.Hour+time.Hour).UTC().Format(time.RFC3339)), 0, nil
	}
	r = w.RunCheck(context.Background(), xspec("cert", `{"store":"LocalMachine\\My"}`))
	if r.Status != Warn || *r.Value != 9 || !strings.Contains(r.Detail, "old.corp.test") {
		t.Fatalf("%+v", r)
	}
	lx, _, _ := extCol("linux")
	if r = lx.RunCheck(context.Background(), xspec("cert", `{"store":"LocalMachine\\My"}`)); r.Status != Unknown || !strings.Contains(r.Detail, "Windows") {
		t.Fatalf("%+v", r)
	}
}

func TestDaysLeftFloors(t *testing.T) {
	now := time.Unix(1_000_000, 0)
	for _, c := range []struct {
		d time.Duration
		n int
	}{{40*24*time.Hour + time.Hour, 40}, {23 * time.Hour, 0}, {0, 0}, {-time.Hour, -1}, {-24 * time.Hour, -1}, {-25 * time.Hour, -2}} {
		if got := daysLeft(now.Add(c.d), now); got != c.n {
			t.Fatalf("%v: %d want %d", c.d, got, c.n)
		}
	}
}

// ---------------------------------------------------------------- ntp

// ntpServer answers SNTP requests with a clock that is skew ahead of the local one.
func ntpServer(t *testing.T, skew time.Duration, mutate func([]byte)) string {
	t.Helper()
	pc, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Skip("no loopback udp")
	}
	t.Cleanup(func() { pc.Close() })
	go func() {
		buf := make([]byte, 128)
		for {
			n, addr, err := pc.ReadFrom(buf)
			if err != nil || n < 48 {
				return
			}
			now := time.Now().Add(skew)
			resp := make([]byte, 48)
			resp[0] = 0<<6 | 4<<3 | 4
			resp[1] = 2
			copy(resp[24:32], buf[40:48])
			binary.BigEndian.PutUint64(resp[32:40], ntpTimestamp(now))
			binary.BigEndian.PutUint64(resp[40:48], ntpTimestamp(now))
			if mutate != nil {
				mutate(resp)
			}
			_, _ = pc.WriteTo(resp, addr)
		}
	}()
	return pc.LocalAddr().String()
}

func ntpCol(addr string) *Collector {
	c, _, x := extCol("linux")
	x.Dial = func(ctx context.Context, network, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, network, addr)
	}
	return c
}

func TestNTPCheck(t *testing.T) {
	c := ntpCol(ntpServer(t, 2*time.Second, nil))
	r := c.RunCheck(context.Background(), xspec("ntp", `{"server":"time.corp.test","warn_ms":1000,"fail_ms":5000}`))
	if r.Status != Warn || r.Value == nil || *r.Value < 1900 || *r.Value > 2100 || !strings.Contains(r.Detail, "behind") {
		t.Fatalf("%+v", r)
	}
	c = ntpCol(ntpServer(t, -9*time.Second, nil))
	r = c.RunCheck(context.Background(), xspec("ntp", `{"server":"time.corp.test"}`))
	if r.Status != Fail || !strings.Contains(r.Detail, "ahead of") || *r.Value < 8900 {
		t.Fatalf("%+v", r)
	}
	c = ntpCol(ntpServer(t, 0, nil))
	if r = c.RunCheck(context.Background(), xspec("ntp", `{"server":"time.corp.test"}`)); r.Status != OK || *r.Value > 200 {
		t.Fatalf("%+v", r)
	}
	// replies that must not be trusted
	for name, mut := range map[string]func([]byte){
		"unsynchronised": func(b []byte) { b[0] |= 3 << 6 },
		"kiss of death":  func(b []byte) { b[1] = 0 },
		"wrong echo":     func(b []byte) { b[25] ^= 0xff },
		"client mode":    func(b []byte) { b[0] = b[0]&^7 | 3 },
		"no time":        func(b []byte) { copy(b[40:48], make([]byte, 8)) },
	} {
		c = ntpCol(ntpServer(t, 0, mut))
		if r = c.RunCheck(context.Background(), xspec("ntp", `{"server":"time.corp.test","timeout_ms":800}`)); r.Status != Unknown || r.Value != nil {
			t.Fatalf("%s: %+v", name, r)
		}
	}
	if r = c.RunCheck(context.Background(), xspec("ntp", `{"server":"-bad"}`)); r.Status != Unknown {
		t.Fatalf("%+v", r)
	}
	// nothing listening: no answer is unknown, never a fabricated zero offset
	pc, _ := net.ListenPacket("udp", "127.0.0.1:0")
	addr := pc.LocalAddr().String()
	defer pc.Close()
	c = ntpCol(addr)
	if r = c.RunCheck(context.Background(), xspec("ntp", `{"server":"time.corp.test","timeout_ms":500}`)); r.Status != Unknown || r.Value != nil {
		t.Fatalf("%+v", r)
	}
}

func TestNTPTimestampRoundTrip(t *testing.T) {
	now := time.Date(2026, 10, 10, 12, 0, 0, 123456789, time.UTC)
	back := fromNTP(ntpTimestamp(now))
	if d := back.Sub(now); d > time.Microsecond || d < -time.Microsecond {
		t.Fatalf("%v", d)
	}
	if _, err := parseNTPResponse(make([]byte, 10), make([]byte, 48), now); err == nil {
		t.Fatal("short reply")
	}
}

// ---------------------------------------------------------------- smart, av, systemd, inodes

func TestSmartCheck(t *testing.T) {
	c, _, x := extCol("linux")
	byDev := map[string]string{"/dev/sda": cfixture(t, "smartctl_ok.json"), "/dev/nvme0": cfixture(t, "smartctl_ok.json"), "/dev/sdb": cfixture(t, "smartctl_fail.json"), "/dev/sdc": cfixture(t, "smartctl_nostatus.json")}
	var calls []string
	x.Exec = func(_ context.Context, _ time.Duration, name string, args ...string) (string, int, error) {
		calls = append(calls, name+" "+strings.Join(args, " "))
		if name != "smartctl" {
			t.Fatalf("ran %s", name)
		}
		if args[0] == "--scan" {
			return cfixture(t, "smartctl_scan.json"), 0, nil
		}
		return byDev[args[len(args)-1]], 0, nil
	}
	assertRes(t, c.RunCheck(context.Background(), xspec("smart", `{}`)), OK, nil, "passed on 2 disk(s)")
	assertRes(t, c.RunCheck(context.Background(), xspec("smart", `{"devices":["/dev/sda","/dev/sdb"]}`)), Fail, nil, "FAILED on /dev/sdb")
	assertRes(t, c.RunCheck(context.Background(), xspec("smart", `{"devices":["/dev/sda","/dev/sdc"]}`)), Warn, nil, "unreadable: /dev/sdc")
	assertRes(t, c.RunCheck(context.Background(), xspec("smart", `{"devices":["/dev/sdc"]}`)), Unknown, nil, "no SMART health status")
	x.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) {
		return "", -1, errors.New(`exec: "smartctl": executable file not found`)
	}
	assertRes(t, c.RunCheck(context.Background(), xspec("smart", `{}`)), Unknown, nil, "smartctl is not available")

	w, _, wx := extCol("windows")
	wx.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) {
		return cfixture(t, "physicaldisk_array.json"), 0, nil
	}
	assertRes(t, w.RunCheck(context.Background(), xspec("smart", `{}`)), Warn, nil, "WDC WD10EZEX warning")
	wx.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) {
		return cfixture(t, "physicaldisk_single_numeric.json"), 0, nil
	}
	assertRes(t, w.RunCheck(context.Background(), xspec("smart", `{}`)), Fail, nil, "Virtual HD unhealthy")
	wx.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) {
		return `{"FriendlyName":"A","HealthStatus":"Healthy"}`, 0, nil
	}
	assertRes(t, w.RunCheck(context.Background(), xspec("smart", `{}`)), OK, nil, "1 disk(s) healthy")
}

func TestAVCheck(t *testing.T) {
	w, _, x := extCol("windows")
	out := cfixture(t, "defender_ok.json")
	x.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) { return out, 0, nil }
	assertRes(t, w.RunCheck(context.Background(), xspec("av", `{}`)), OK, f64(1), "signatures 1 days old")
	out = cfixture(t, "defender_stale.json")
	assertRes(t, w.RunCheck(context.Background(), xspec("av", `{}`)), Warn, f64(9), "9 days")
	assertRes(t, w.RunCheck(context.Background(), xspec("av", `{"max_signature_age_days":3}`)), Fail, f64(9), "")
	assertRes(t, w.RunCheck(context.Background(), xspec("av", `{"max_signature_age_days":30}`)), OK, f64(9), "")
	out = cfixture(t, "defender_off.json")
	assertRes(t, w.RunCheck(context.Background(), xspec("av", `{}`)), Fail, f64(0), "real-time protection is turned off")
	assertRes(t, w.RunCheck(context.Background(), xspec("av", `{"require_realtime":false}`)), OK, f64(0), "")
	out = ""
	assertRes(t, w.RunCheck(context.Background(), xspec("av", `{}`)), Unknown, nil, "third-party")
}

func TestSystemdFailedCheck(t *testing.T) {
	c, _, x := extCol("linux")
	x.Exec = func(_ context.Context, _ time.Duration, name string, args ...string) (string, int, error) {
		if name != "systemctl" {
			t.Fatal(name)
		}
		return cfixture(t, "systemctl_failed.txt"), 0, nil
	}
	assertRes(t, c.RunCheck(context.Background(), xspec("systemd_failed", `{}`)), Fail, f64(3), "apt-daily.service, snapd.service, user@1000.service")
	assertRes(t, c.RunCheck(context.Background(), xspec("systemd_failed", `{"ignore":["apt-daily.service","user@*"]}`)), Fail, f64(1), "1 failed unit(s): snapd.service")
	assertRes(t, c.RunCheck(context.Background(), xspec("systemd_failed", `{"ignore":["apt-daily.service","user@*"],"max_failed":1}`)), OK, f64(1), "")
	x.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) { return "", 0, nil }
	assertRes(t, c.RunCheck(context.Background(), xspec("systemd_failed", `{}`)), OK, f64(0), "0 failed")
	x.Exec = func(context.Context, time.Duration, string, ...string) (string, int, error) {
		return "", -1, errors.New("not found")
	}
	assertRes(t, c.RunCheck(context.Background(), xspec("systemd_failed", `{}`)), Unknown, nil, "not available")
}

func TestInodesCheck(t *testing.T) {
	c, f, x := extCol("linux")
	f.disks = []DiskStat{{Mount: "/", Total: 1, Free: 1}, {Mount: "/var", Total: 1, Free: 1}, {Mount: "/data", Total: 1, Free: 1}}
	stat := map[string][2]uint64{"/": {1000, 700}, "/var": {1000, 60}, "/data": {0, 0}}
	x.Statfs = func(p string) (uint64, uint64, error) { s := stat[p]; return s[0], s[1], nil }
	assertRes(t, c.RunCheck(context.Background(), xspec("inodes", `{}`)), Warn, f64(94), "/var: 94% of inodes used")
	assertRes(t, c.RunCheck(context.Background(), xspec("inodes", `{"fail_pct":90}`)), Fail, f64(94), "")
	assertRes(t, c.RunCheck(context.Background(), xspec("inodes", `{"mount":"/"}`)), OK, f64(30), "")
	assertRes(t, c.RunCheck(context.Background(), xspec("inodes", `{"warn_pct":90,"fail_pct":99}`)), Warn, f64(94), "")
	assertRes(t, c.RunCheck(context.Background(), xspec("inodes", `{"mount":"/data"}`)), Unknown, nil, "no filesystem")
	assertRes(t, c.RunCheck(context.Background(), xspec("inodes", `{"mount":"/nope"}`)), Unknown, nil, "no filesystem")
	if _, _, err := DefaultSys().Statfs("/"); err != nil && DefaultSys().GOOS == "linux" {
		t.Fatalf("the real statfs failed: %v", err)
	}
}

func TestDefaultSysExecAndFileCaps(t *testing.T) {
	x := DefaultSys()
	if x.GOOS == "windows" {
		t.Skip("posix commands")
	}
	out, code, err := x.Exec(context.Background(), 5*time.Second, "sh", "-c", "echo hi; exit 3")
	if err != nil || code != 3 || strings.TrimSpace(out) != "hi" {
		t.Fatalf("%q %d %v", out, code, err)
	}
	if _, _, err := x.Exec(context.Background(), 200*time.Millisecond, "sleep", "5"); err == nil || !strings.Contains(err.Error(), "timed out") {
		t.Fatalf("%v", err)
	}
	if _, _, err := x.Exec(context.Background(), time.Second, "no-such-program-xyz"); err == nil {
		t.Fatal("missing program")
	}
	big, _, _ := x.Exec(context.Background(), 10*time.Second, "sh", "-c", "head -c 600000 /dev/zero | tr '\\0' a")
	if len(big) != maxExecOutput {
		t.Fatalf("output cap: %d", len(big))
	}
}
