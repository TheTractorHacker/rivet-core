package remote

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

const (
	tid   = "0b1c2d3e-0000-4000-8000-000000000001"
	token = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
)

func sum(b []byte) string { h := sha256.Sum256(b); return hex.EncodeToString(h[:]) }

// fakeServer plays agent_transfer closely enough to exercise both directions.
type fakeServer struct {
	t *testing.T
	// download side (file_get)
	got       []byte
	maxBytes  int
	lastSum   string
	complete  bool
	requests  []api.TransferRequest
	failFirst int   // answer this many requests with 503 before behaving
	conflict  int64 // when >= 0 answer the next chunk with a 409 carrying this offset, once
	refuse    int   // answer the next request with this status (non-transient)
	badSum    bool  // report a final checksum problem
	// upload side (file_put)
	content    []byte
	sizeHeader string
	sumHeader  string
}

func newFakeServer(t *testing.T) *fakeServer {
	return &fakeServer{t: t, conflict: -1, maxBytes: 1 << 40}
}

func (s *fakeServer) Transfer(_ context.Context, tr api.TransferRequest) (*api.TransferResponse, error) {
	s.requests = append(s.requests, tr)
	if tr.Header["X-Transfer-Token"] != token {
		return nil, &api.APIError{Status: 404, Code: "not_found", Message: "no"}
	}
	if s.failFirst > 0 {
		s.failFirst--
		return nil, &api.APIError{Status: 503, Code: "unavailable", Message: "busy"}
	}
	if s.refuse != 0 {
		st := s.refuse
		s.refuse = 0
		return nil, &api.APIError{Status: st, Code: "too_large", Message: "no"}
	}
	if tr.Query.Get("transfer_id") != tid {
		return nil, &api.APIError{Status: 404, Code: "not_found"}
	}
	off, _ := strconv.Atoi(tr.Query.Get("offset"))
	switch tr.Method {
	case http.MethodPost:
		if s.conflict >= 0 {
			n := s.conflict
			s.conflict = -1
			return nil, &api.APIError{Status: 409, Code: "conflict", Received: &n}
		}
		if off != len(s.got) {
			n := int64(len(s.got))
			return nil, &api.APIError{Status: 409, Code: "conflict", Received: &n}
		}
		if len(s.got)+len(tr.Body) > s.maxBytes {
			return nil, &api.APIError{Status: 413, Code: "too_large"}
		}
		s.got = append(s.got, tr.Body...)
		last := tr.Header["X-Transfer-Last"] == "1"
		body := map[string]any{"ok": true, "received": len(s.got), "complete": last}
		if last {
			s.lastSum = tr.Header["X-Transfer-Sha256"]
			if s.badSum || s.lastSum != sum(s.got) {
				return nil, &api.APIError{Status: 422, Code: "checksum_mismatch"}
			}
			s.complete = true
		}
		b, _ := json.Marshal(body)
		return &api.TransferResponse{Status: 200, Header: http.Header{}, Body: b}, nil
	case http.MethodGet:
		length, _ := strconv.Atoi(tr.Query.Get("length"))
		if off >= len(s.content) || length < 1 || length > 1<<20 {
			return nil, &api.APIError{Status: 416, Code: "invalid"}
		}
		end := min(off+length, len(s.content))
		h := http.Header{}
		size, sh := s.sizeHeader, s.sumHeader
		if size == "" {
			size = strconv.Itoa(len(s.content))
		}
		if sh == "" {
			sh = sum(s.content)
		}
		h.Set("X-Transfer-Size", size)
		h.Set("X-Transfer-Sha256", sh)
		return &api.TransferResponse{Status: 200, Header: h, Body: append([]byte(nil), s.content[off:end]...)}, nil
	}
	return nil, &api.APIError{Status: 405}
}

func fastFiles(dir string, s *fakeServer) Files {
	p := PathPolicy{GOOS: "linux", Resolve: filepath.EvalSymlinks}
	return Files{GOOS: "linux", Policy: p, T: s, Sleep: func(context.Context, time.Duration) error { return nil }}
}

func getParams(path, allow string, max int) json.RawMessage {
	b, _ := json.Marshal(map[string]any{"transfer_id": tid, "token": token, "path": path, "max_bytes": max, "allow": allow, "chunk": 4096})
	return b
}

func realDir(t *testing.T) string {
	t.Helper()
	d, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	return d
}

func TestFileGetStreamsInChunksWithTheChecksumOnTheLastOne(t *testing.T) {
	dir := realDir(t)
	data := make([]byte, 4096*3+17)
	rand.Read(data)
	path := filepath.Join(dir, "report.bin")
	os.WriteFile(path, data, 0o644)
	srv := newFakeServer(t)
	r := fastFiles(dir, srv).Get(context.Background(), getParams(path, dir, 1<<20), 4096)
	if !r.OK {
		t.Fatalf("%+v", r)
	}
	if string(srv.got) != string(data) || !srv.complete {
		t.Fatalf("server holds %d bytes, complete=%v", len(srv.got), srv.complete)
	}
	if len(srv.requests) != 4 {
		t.Errorf("%d requests for 3 full chunks and a tail", len(srv.requests))
	}
	for i, rq := range srv.requests {
		last := i == len(srv.requests)-1
		if (rq.Header["X-Transfer-Last"] == "1") != last {
			t.Errorf("request %d: X-Transfer-Last = %q", i, rq.Header["X-Transfer-Last"])
		}
		if last && rq.Header["X-Transfer-Sha256"] != sum(data) {
			t.Errorf("final checksum %q", rq.Header["X-Transfer-Sha256"])
		}
		if !last && rq.Header["X-Transfer-Sha256"] != "" {
			t.Errorf("request %d carries a checksum before the end", i)
		}
		if rq.Method != http.MethodPost || rq.Query.Get("transfer_id") != tid || len(rq.Body) > 4096 {
			t.Errorf("request %d: %s %v %d", i, rq.Method, rq.Query, len(rq.Body))
		}
	}
	var out map[string]any
	json.Unmarshal([]byte(r.Output), &out)
	if out["sha256"] != sum(data) || int(out["bytes"].(float64)) != len(data) {
		t.Errorf("%v", out)
	}
}

func TestFileGetExactChunkMultipleAndEmptyFile(t *testing.T) {
	dir := realDir(t)
	for _, n := range []int{0, 1, 4096, 8192} {
		data := make([]byte, n)
		rand.Read(data)
		path := filepath.Join(dir, fmt.Sprintf("f%d", n))
		os.WriteFile(path, data, 0o644)
		srv := newFakeServer(t)
		if r := fastFiles(dir, srv).Get(context.Background(), getParams(path, dir, 1<<20), 4096); !r.OK || string(srv.got) != string(data) || !srv.complete {
			t.Errorf("size %d: %+v got %d complete %v", n, r, len(srv.got), srv.complete)
		}
		if last := srv.requests[len(srv.requests)-1]; last.Header["X-Transfer-Last"] != "1" || last.Header["X-Transfer-Sha256"] != sum(data) {
			t.Errorf("size %d: the final request must carry the marker and the checksum: %v", n, last.Header)
		}
	}
}

func TestFileGetResumesWhereTheServerSays(t *testing.T) {
	dir := realDir(t)
	data := make([]byte, 4096*4)
	rand.Read(data)
	path := filepath.Join(dir, "a.bin")
	os.WriteFile(path, data, 0o644)
	srv := newFakeServer(t)
	srv.got = append(srv.got, data[:4096]...) // the server already holds the first chunk (an earlier attempt)
	r := fastFiles(dir, srv).Get(context.Background(), getParams(path, dir, 1<<20), 4096)
	if !r.OK || string(srv.got) != string(data) {
		t.Fatalf("%+v got %d", r, len(srv.got))
	}
	// never more than three resyncs
	srv = newFakeServer(t)
	conflicts := 0
	f := fastFiles(dir, srv)
	f.T = transportFunc(func(ctx context.Context, tr api.TransferRequest) (*api.TransferResponse, error) {
		conflicts++
		n := int64(0)
		return nil, &api.APIError{Status: 409, Code: "conflict", Received: &n}
	})
	if r := f.Get(context.Background(), getParams(path, dir, 1<<20), 4096); r.OK || r.Detail != "upload_failed" || conflicts > maxResyncs+1 {
		t.Errorf("%+v after %d conflicts", r, conflicts)
	}
}

type transportFunc func(context.Context, api.TransferRequest) (*api.TransferResponse, error)

func (f transportFunc) Transfer(c context.Context, tr api.TransferRequest) (*api.TransferResponse, error) {
	return f(c, tr)
}

func TestFileGetRefusalsNeverSendAByte(t *testing.T) {
	dir := realDir(t)
	other := realDir(t)
	os.WriteFile(filepath.Join(dir, "ok.txt"), []byte("ok"), 0o644)
	os.WriteFile(filepath.Join(other, "elsewhere.txt"), []byte("x"), 0o644)
	os.WriteFile(filepath.Join(dir, "id_rsa"), []byte("key"), 0o600)
	os.Symlink("/etc/passwd", filepath.Join(dir, "passwd-link"))
	os.MkdirAll(filepath.Join(dir, "sub"), 0o755)
	big := make([]byte, 5000)
	os.WriteFile(filepath.Join(dir, "big"), big, 0o644)
	cases := []struct {
		name   string
		params json.RawMessage
		detail string
	}{
		{"outside the allowed folder", getParams(filepath.Join(other, "elsewhere.txt"), dir, 100), "path_denied:not_allowed"},
		{"traversal", getParams(dir+"/sub/../ok.txt", dir, 100), "path_denied:traversal"},
		{"deny list", getParams(filepath.Join(dir, "id_rsa"), dir, 100), "path_denied:deny_list"},
		{"symlink out of the folder", getParams(filepath.Join(dir, "passwd-link"), dir, 100), "path_denied:not_allowed"},
		{"a directory", getParams(filepath.Join(dir, "sub"), dir, 100), "not_regular"},
		{"missing", getParams(filepath.Join(dir, "nope"), dir, 100), "not_found"},
		{"no allowed folders", getParams(filepath.Join(dir, "ok.txt"), "", 100), "path_denied:no_allowed_roots"},
		{"too large", getParams(filepath.Join(dir, "big"), dir, 4999), "too_large"},
		{"bad token", json.RawMessage(`{"transfer_id":"` + tid + `","token":"short","path":"/x","max_bytes":5,"allow":"/x"}`), DetailBadParams},
		{"bad id", json.RawMessage(`{"transfer_id":"../x","token":"` + token + `","path":"/x","max_bytes":5,"allow":"/x"}`), DetailBadParams},
		{"no max", json.RawMessage(`{"transfer_id":"` + tid + `","token":"` + token + `","path":"/x","allow":"/x"}`), DetailBadParams},
	}
	for _, c := range cases {
		srv := newFakeServer(t)
		r := fastFiles(dir, srv).Get(context.Background(), c.params, 4096)
		if r.OK || !strings.HasPrefix(r.Detail, c.detail) {
			t.Errorf("%s: %+v; want %s", c.name, r, c.detail)
		}
		if len(srv.requests) != 0 {
			t.Errorf("%s: %d requests were sent", c.name, len(srv.requests))
		}
		if strings.Contains(r.Output, "secret") || strings.Contains(r.Output, "key") && c.name != "deny list" {
			t.Errorf("%s: output %q", c.name, r.Output)
		}
	}
}

func TestFileGetServerSideErrors(t *testing.T) {
	dir := realDir(t)
	path := filepath.Join(dir, "a.bin")
	os.WriteFile(path, make([]byte, 9000), 0o644)
	srv := newFakeServer(t)
	srv.maxBytes = 5000
	if r := fastFiles(dir, srv).Get(context.Background(), getParams(path, dir, 1<<20), 4096); r.OK || r.Detail != "server_refused:too_large" {
		t.Errorf("413 from the server: %+v", r)
	}
	srv = newFakeServer(t)
	srv.badSum = true
	if r := fastFiles(dir, srv).Get(context.Background(), getParams(path, dir, 1<<20), 4096); r.OK || r.Detail != "server_refused:checksum_mismatch" {
		t.Errorf("checksum: %+v", r)
	}
	srv = newFakeServer(t)
	srv.failFirst = 2 // two transient 503s are retried
	if r := fastFiles(dir, srv).Get(context.Background(), getParams(path, dir, 1<<20), 4096); !r.OK {
		t.Errorf("retries: %+v", r)
	}
	srv = newFakeServer(t)
	srv.failFirst = 100
	f := fastFiles(dir, srv)
	if r := f.Get(context.Background(), getParams(path, dir, 1<<20), 4096); r.OK || r.Detail != "upload_failed" || len(srv.requests) != defaultTries {
		t.Errorf("gives up after %d tries: %+v (%d)", defaultTries, r, len(srv.requests))
	}
	srv = newFakeServer(t)
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if r := fastFiles(dir, srv).Get(ctx, getParams(path, dir, 1<<20), 4096); r.OK {
		t.Errorf("a cancelled job: %+v", r)
	}
}

func TestFileGetStopsWhenTheFileGrowsPastTheLimit(t *testing.T) {
	dir := realDir(t)
	path := filepath.Join(dir, "grow.log")
	os.WriteFile(path, make([]byte, 4096*2), 0o644)
	srv := newFakeServer(t)
	f := fastFiles(dir, srv)
	inner := f.T
	f.T = transportFunc(func(ctx context.Context, tr api.TransferRequest) (*api.TransferResponse, error) {
		if len(srv.got) == 0 { // after the first chunk the file grows
			fh, _ := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
			fh.Write(make([]byte, 100000))
			fh.Close()
		}
		return inner.Transfer(ctx, tr)
	})
	if r := f.Get(context.Background(), getParams(path, dir, 4096*2), 4096); r.OK || r.Detail != DetailTooLarge {
		t.Errorf("%+v", r)
	}
}

// ---- file_put ----

func putParams(path, allow string, data []byte, overwrite bool, extra map[string]any) json.RawMessage {
	m := map[string]any{"transfer_id": tid, "token": token, "path": path, "size": len(data), "sha256": sum(data), "overwrite": overwrite, "allow": allow, "mode": "0640"}
	for k, v := range extra {
		m[k] = v
	}
	b, _ := json.Marshal(m)
	return b
}

func TestFilePutVerifiesAndSwapsAtomically(t *testing.T) {
	dir := realDir(t)
	data := make([]byte, 1<<20+12345)
	rand.Read(data)
	srv := newFakeServer(t)
	srv.content = data
	dest := filepath.Join(dir, "tool.bin")
	r := fastFiles(dir, srv).Put(context.Background(), putParams(dest, dir, data, false, map[string]any{"mode": "0600"}), 4096)
	if !r.OK {
		t.Fatalf("%+v", r)
	}
	got, _ := os.ReadFile(dest)
	if sum(got) != sum(data) {
		t.Fatal("content differs")
	}
	if fi, _ := os.Stat(dest); fi.Mode().Perm() != 0o600 {
		t.Errorf("mode %v", fi.Mode())
	}
	if entries, _ := os.ReadDir(dir); len(entries) != 1 {
		t.Errorf("a temp file was left: %v", entries)
	}
	for _, rq := range srv.requests {
		if rq.Method != http.MethodGet || rq.Query.Get("transfer_id") != tid {
			t.Errorf("%v", rq)
		}
		if n, _ := strconv.Atoi(rq.Query.Get("length")); n < 1 || n > 1<<20 {
			t.Errorf("length %d", n)
		}
	}
	// zero bytes
	srv = newFakeServer(t)
	if r := fastFiles(dir, srv).Put(context.Background(), putParams(filepath.Join(dir, "empty"), dir, nil, false, nil), 4096); !r.OK {
		t.Errorf("empty file: %+v", r)
	} else if fi, err := os.Stat(filepath.Join(dir, "empty")); err != nil || fi.Size() != 0 {
		t.Errorf("empty file not created: %v", err)
	}
}

func TestFilePutNeverTouchesTheDestinationWhenAnythingIsWrong(t *testing.T) {
	dir := realDir(t)
	data := []byte("the new content of the file")
	dest := filepath.Join(dir, "keep.txt")
	os.WriteFile(dest, []byte("ORIGINAL"), 0o644)
	untouched := func(name string) {
		t.Helper()
		if b, _ := os.ReadFile(dest); string(b) != "ORIGINAL" {
			t.Errorf("%s: the destination changed to %q", name, b)
		}
		if entries, _ := os.ReadDir(dir); len(entries) != 1 {
			t.Errorf("%s: left files behind: %v", name, entries)
		}
	}
	run := func(name string, srv *fakeServer, params json.RawMessage, want string) {
		t.Helper()
		r := fastFiles(dir, srv).Put(context.Background(), params, 4096)
		if r.OK || !strings.HasPrefix(r.Detail, want) {
			t.Errorf("%s: %+v; want %s", name, r, want)
		}
		untouched(name)
	}
	srv := newFakeServer(t)
	srv.content = []byte("some other bytes of the same length")[:len(data)]
	srv.sumHeader = sum(data) // the server lies in its headers too: only the hash of the bytes received can catch it
	run("wrong content (checksum_mismatch)", srv, putParams(dest, dir, data, true, nil), "checksum_mismatch")
	srv = newFakeServer(t)
	srv.content = data
	srv.sumHeader = sum([]byte("different"))
	run("server header disagrees with the signed hash", srv, putParams(dest, dir, data, true, nil), "metadata_mismatch")
	srv = newFakeServer(t)
	srv.content = data
	srv.sizeHeader = "999"
	run("server header disagrees with the signed size", srv, putParams(dest, dir, data, true, nil), "metadata_mismatch")
	srv = newFakeServer(t)
	srv.content = data[:10]
	run("short content", srv, putParams(dest, dir, data, true, nil), "")
	srv = newFakeServer(t)
	srv.content = data
	run("overwrite=false on an existing file", srv, putParams(dest, dir, data, false, nil), "exists")
	if len(srv.requests) != 0 {
		t.Error("nothing is downloaded for a destination that cannot be written")
	}
	srv = newFakeServer(t)
	srv.content = data
	srv.refuse = 413
	run("server refuses", srv, putParams(dest, dir, data, true, nil), "server_refused")
	srv = newFakeServer(t)
	srv.content = data
	run("outside the allowed folder", srv, putParams(filepath.Join(realDir(t), "x"), dir, data, true, nil), "path_denied:not_allowed")
	run("deny list", srv, putParams(filepath.Join(dir, "site.pfx"), dir, data, true, nil), "path_denied:deny_list")
	run("traversal", srv, putParams(dir+"/../x", dir, data, true, nil), "path_denied:traversal")
	run("no allowed folder", srv, putParams(filepath.Join(dir, "x"), "", data, true, nil), "path_denied:no_allowed_roots")
	run("parent folder is never created", srv, putParams(filepath.Join(dir, "newdir", "x"), dir, data, true, nil), "parent_missing")
	run("bad mode", srv, putParams(filepath.Join(dir, "x"), dir, data, true, map[string]any{"mode": "0999"}), DetailBadParams)
	run("bad hash", srv, putParams(filepath.Join(dir, "x"), dir, data, true, map[string]any{"sha256": "xyz"}), DetailBadParams)
	run("too big for the agent", srv, putParams(filepath.Join(dir, "x"), dir, data, true, map[string]any{"size": MaxTransferBytes + 1}), DetailTooLarge)
	if len(srv.requests) != 0 {
		t.Errorf("%d downloads for refused jobs", len(srv.requests))
	}
}

func TestFilePutRefusesSymlinkAndDirectoryDestinations(t *testing.T) {
	dir := realDir(t)
	data := []byte("data")
	victim := filepath.Join(dir, "victim.txt")
	os.WriteFile(victim, []byte("VICTIM"), 0o644)
	os.Symlink(victim, filepath.Join(dir, "link.txt"))
	os.Mkdir(filepath.Join(dir, "adir"), 0o755)
	srv := newFakeServer(t)
	srv.content = data
	for name, want := range map[string]string{"link.txt": "dest_is_symlink", "adir": "dest_is_directory"} {
		r := fastFiles(dir, srv).Put(context.Background(), putParams(filepath.Join(dir, name), dir, data, true, nil), 4096)
		if r.OK || !strings.HasPrefix(r.Detail, want) {
			t.Errorf("%s: %+v; want %s", name, r, want)
		}
	}
	if b, _ := os.ReadFile(victim); string(b) != "VICTIM" {
		t.Errorf("the target of the link was overwritten: %q", b)
	}
}

func TestFilePutDoesNotClobberWithoutOverwriteEvenWhenRacing(t *testing.T) {
	dir := realDir(t)
	data := []byte("new")
	srv := newFakeServer(t)
	srv.content = data
	dest := filepath.Join(dir, "race.txt")
	f := fastFiles(dir, srv)
	inner := f.T
	f.T = transportFunc(func(ctx context.Context, tr api.TransferRequest) (*api.TransferResponse, error) {
		os.WriteFile(dest, []byte("APPEARED"), 0o644) // somebody creates the file while we download
		return inner.Transfer(ctx, tr)
	})
	r := f.Put(context.Background(), putParams(dest, dir, data, false, nil), 4096)
	if r.OK || r.Detail != "exists" {
		t.Errorf("%+v", r)
	}
	if b, _ := os.ReadFile(dest); string(b) != "APPEARED" {
		t.Errorf("clobbered: %q", b)
	}
	if entries, _ := os.ReadDir(dir); len(entries) != 1 {
		t.Errorf("temp file left: %v", entries)
	}
}

func TestParseMode(t *testing.T) {
	for in, want := range map[string]os.FileMode{"": 0o640, "0640": 0o640, "640": 0o640, "0600": 0o600, "0755": 0o755, "4755": 0o755, "7777": 0o777} {
		if got, ok := ParseMode(in); !ok || got != want {
			t.Errorf("ParseMode(%q) = %v %v", in, got, ok)
		}
	}
	for _, in := range []string{"0999", "rwx", "06400", "-1", "0x1f", " 640", "64"} {
		if _, ok := ParseMode(in); ok {
			t.Errorf("ParseMode(%q) accepted", in)
		}
	}
}
