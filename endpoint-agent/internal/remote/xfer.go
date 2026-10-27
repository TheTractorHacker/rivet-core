package remote

import (
	"context"
	"crypto/sha256"
	"encoding"
	"encoding/hex"
	"encoding/json"
	"errors"
	"hash"
	"io"
	"io/fs"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/api"
)

// Transfer limits.
const (
	// MaxTransferBytes is the agent's own hard cap for one transferred file.
	MaxTransferBytes = 536870912
	// DefaultChunk is the largest body of one transfer request.
	DefaultChunk = api.MaxTransferChunk

	maxResyncs      = 3
	defaultTries    = 5
	defaultFileMode = 0o640
)

var (
	transferIDRE = regexp.MustCompile(`^[A-Za-z0-9-]{8,64}$`)
	hex64RE      = regexp.MustCompile(`^[0-9a-fA-F]{64}$`)
	modeRE       = regexp.MustCompile(`^[0-7]{3,4}$`)
	codeCleanRE  = regexp.MustCompile(`[^a-z0-9_]`)
)

// Transport is the agent_transfer endpoint (satisfied by *api.Client).
type Transport interface {
	Transfer(ctx context.Context, tr api.TransferRequest) (*api.TransferResponse, error)
}

// Files implements the file_get and file_put jobs.
type Files struct {
	GOOS    string
	Policy  PathPolicy
	T       Transport
	Backoff api.Backoff
	Sleep   func(ctx context.Context, d time.Duration) error
	Tries   int // attempts per chunk (default 5)
}

func (f Files) goos() string {
	if f.GOOS == "" {
		return runtime.GOOS
	}
	return f.GOOS
}

func (f Files) tries() int {
	if f.Tries > 0 {
		return f.Tries
	}
	return defaultTries
}

func (f Files) backoff() api.Backoff {
	if f.Backoff.Base > 0 {
		return f.Backoff
	}
	return api.Backoff{Base: time.Second, Max: 30 * time.Second}
}

func (f Files) sleep(ctx context.Context, d time.Duration) error {
	if f.Sleep != nil {
		return f.Sleep(ctx, d)
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// serverRefusedDetail renders "server_refused:<code>" from an API error.
func serverRefusedDetail(ae *api.APIError) string {
	code := codeCleanRE.ReplaceAllString(strings.ToLower(ae.Code), "")
	if len(code) > 40 {
		code = code[:40]
	}
	if code == "" {
		code = strconv.Itoa(ae.Status)
	}
	return "server_refused:" + code
}

// doChunk sends one transfer request, retrying transient failures with the
// agent's backoff. It returns the response, or a detail code (and, for a 409
// resync, the byte count the server holds).
func (f Files) doChunk(ctx context.Context, tr api.TransferRequest) (resp *api.TransferResponse, resync *int64, detail string) {
	bo := f.backoff()
	for try := 0; try < f.tries(); try++ {
		r, err := f.T.Transfer(ctx, tr)
		if err == nil {
			return r, nil, ""
		}
		if ctx.Err() != nil {
			return nil, nil, "cancelled"
		}
		var ae *api.APIError
		if !errors.As(err, &ae) {
			return nil, nil, "transfer_failed"
		}
		if ae.Status == http.StatusConflict && ae.Received != nil {
			return nil, ae.Received, ""
		}
		if ae.Transient() {
			if try == f.tries()-1 {
				break
			}
			if f.sleep(ctx, bo.DelayWithRetryAfter(try, ae.RetryAfter)) != nil {
				return nil, nil, "cancelled"
			}
			continue
		}
		return nil, nil, serverRefusedDetail(ae)
	}
	return nil, nil, "transfer_failed"
}

// ---- file_get (device -> server) ----

type fileGetParams struct {
	TransferID string `json:"transfer_id"`
	Token      string `json:"token"`
	Path       string `json:"path"`
	MaxBytes   *int64 `json:"max_bytes"`
	Allow      string `json:"allow"`
	Chunk      int    `json:"chunk"`
}

type fileGetOut struct {
	V          int    `json:"v"`
	TransferID string `json:"transfer_id"`
	Bytes      int64  `json:"bytes"`
	SHA256     string `json:"sha256"`
}

func xferFailure(id, detail string) Result {
	m := map[string]any{}
	if transferIDRE.MatchString(id) {
		m["transfer_id"] = id
	}
	return failure(detail, m)
}

type sumReader struct {
	r io.ReaderAt
	h hash.Hash
	n int64 // bytes hashed so far (= prefix length)
}

// advanceTo makes the running hash cover exactly the first off bytes of the file
// (re-reading from the start when the server asks to resume earlier).
func (s *sumReader) advanceTo(off int64) error {
	if off < s.n {
		s.h.Reset()
		s.n = 0
	}
	buf := make([]byte, 64<<10)
	for s.n < off {
		want := int64(len(buf))
		if off-s.n < want {
			want = off - s.n
		}
		k, err := s.r.ReadAt(buf[:want], s.n)
		s.h.Write(buf[:k])
		s.n += int64(k)
		if err != nil && int64(k) < want {
			return err
		}
	}
	return nil
}

func (s *sumReader) add(p []byte) { s.h.Write(p); s.n += int64(len(p)) }

// Get is file_get: stream a local file to the server in chunks.
func (f Files) Get(ctx context.Context, raw json.RawMessage, _ int) Result {
	var p fileGetParams
	if err := decodeParams(raw, &p); err != nil {
		return cannotLaunch(DetailBadParams)
	}
	if !transferIDRE.MatchString(p.TransferID) || !hex64RE.MatchString(p.Token) || p.Path == "" || p.MaxBytes == nil || *p.MaxBytes < 0 {
		return cannotLaunch(DetailBadParams)
	}
	maxBytes := *p.MaxBytes
	if maxBytes > MaxTransferBytes {
		maxBytes = MaxTransferBytes
	}
	chunk := p.Chunk
	if chunk <= 0 || chunk > DefaultChunk {
		chunk = DefaultChunk
	}
	resolved, err := f.Policy.Check(p.Path, p.Allow)
	if err != nil {
		return xferFailure(p.TransferID, err.Error())
	}
	file, size, detail := openRegular(resolved)
	if detail != "" {
		return xferFailure(p.TransferID, detail)
	}
	defer file.Close()
	if size > maxBytes {
		return xferFailure(p.TransferID, DetailTooLarge)
	}
	sr := &sumReader{r: file, h: sha256.New()}
	buf := make([]byte, chunk)
	var offset int64
	resyncs := 0
	for {
		if ctx.Err() != nil {
			return xferFailure(p.TransferID, "cancelled")
		}
		if err := sr.advanceTo(offset); err != nil {
			return xferFailure(p.TransferID, "read_failed")
		}
		n, rerr := file.ReadAt(buf, offset)
		if rerr != nil && rerr != io.EOF {
			return xferFailure(p.TransferID, "read_failed")
		}
		if offset+int64(n) > maxBytes {
			return xferFailure(p.TransferID, DetailTooLarge) // the file grew past max_bytes
		}
		last := n < len(buf)
		if !last { // exactly a full chunk: is anything left?
			var one [1]byte
			k, e := file.ReadAt(one[:], offset+int64(n))
			if k == 0 && e == io.EOF {
				last = true
			} else if k == 1 && offset+int64(n)+1 > maxBytes {
				return xferFailure(p.TransferID, DetailTooLarge)
			}
		}
		body := make([]byte, n)
		copy(body, buf[:n])
		hdr := map[string]string{"X-Transfer-Token": p.Token, "Content-Type": "application/octet-stream"}
		finalSum := ""
		if last {
			c := cloneHash(sr.h)
			c.Write(body)
			finalSum = hex.EncodeToString(c.Sum(nil))
			hdr["X-Transfer-Last"] = "1"
			hdr["X-Transfer-Sha256"] = finalSum
		}
		q := url.Values{"transfer_id": {p.TransferID}, "offset": {strconv.FormatInt(offset, 10)}}
		resp, resync, detail := f.doChunk(ctx, api.TransferRequest{Method: http.MethodPost, Query: q, Header: hdr, Body: body})
		if detail != "" {
			if strings.HasPrefix(detail, "server_refused:") || detail == "cancelled" {
				return xferFailure(p.TransferID, detail)
			}
			return xferFailure(p.TransferID, "upload_failed")
		}
		if resync != nil {
			resyncs++
			if resyncs > maxResyncs || *resync < 0 {
				return xferFailure(p.TransferID, "upload_failed")
			}
			offset = *resync
			continue
		}
		var ack struct {
			OK       bool   `json:"ok"`
			Received *int64 `json:"received"`
			Complete bool   `json:"complete"`
		}
		_ = json.Unmarshal(resp.Body, &ack)
		next := offset + int64(n)
		if ack.Received != nil && *ack.Received != next {
			// The server holds a different amount than we just sent: resume there.
			resyncs++
			if resyncs > maxResyncs || *ack.Received < 0 {
				return xferFailure(p.TransferID, "upload_failed")
			}
			offset = *ack.Received
			continue
		}
		if last {
			if !ack.Complete {
				return xferFailure(p.TransferID, "upload_failed")
			}
			return Result{OK: true, Output: marshal(fileGetOut{V: 1, TransferID: p.TransferID, Bytes: next, SHA256: finalSum})}
		}
		sr.add(body)
		offset = next
	}
}

// cloneHash copies a sha256 state so a trial Sum cannot disturb the running hash.
func cloneHash(h hash.Hash) hash.Hash {
	b, err := h.(encoding.BinaryMarshaler).MarshalBinary()
	if err != nil {
		panic("sha256 state not marshalable: " + err.Error())
	}
	nh := sha256.New()
	if err := nh.(encoding.BinaryUnmarshaler).UnmarshalBinary(b); err != nil {
		panic("sha256 state not restorable: " + err.Error())
	}
	return nh
}

// openRegular opens path read-only without following a final symlink and checks
// the OPEN handle is a regular file.
func openRegular(path string) (*os.File, int64, string) {
	f, err := openNoFollow(path)
	if err != nil {
		switch {
		case errors.Is(err, fs.ErrNotExist):
			return nil, 0, DetailNotFound
		case errors.Is(err, fs.ErrPermission):
			return nil, 0, "permission_denied"
		}
		return nil, 0, "open_failed"
	}
	fi, err := f.Stat()
	if err != nil {
		f.Close()
		return nil, 0, "open_failed"
	}
	if !fi.Mode().IsRegular() {
		f.Close()
		return nil, 0, "not_regular"
	}
	return f, fi.Size(), ""
}

// ---- file_put (server -> device) ----

type filePutParams struct {
	TransferID string `json:"transfer_id"`
	Token      string `json:"token"`
	Path       string `json:"path"`
	Size       *int64 `json:"size"`
	SHA256     string `json:"sha256"`
	Overwrite  bool   `json:"overwrite"`
	Allow      string `json:"allow"`
	Mode       string `json:"mode"`
}

type filePutOut struct {
	V          int    `json:"v"`
	TransferID string `json:"transfer_id"`
	Path       string `json:"path"`
	Bytes      int64  `json:"bytes"`
	SHA256     string `json:"sha256"`
}

// ParseMode reads the octal "mode" param (default 0640, masked to 0777).
func ParseMode(s string) (os.FileMode, bool) {
	if s == "" {
		return defaultFileMode, true
	}
	if !modeRE.MatchString(s) {
		return 0, false
	}
	n, err := strconv.ParseUint(s, 8, 32)
	if err != nil {
		return 0, false
	}
	return os.FileMode(n & 0o777), true
}

// TempName is the in-directory temp file of a transfer.
func TempName(id string) string { return ".rivetit-xfer-" + id + ".tmp" }

var errExists = errors.New("exists")

// Put is file_put: download a server file into a temp file next to the
// destination, verify size and checksum, then rename it into place.
func (f Files) Put(ctx context.Context, raw json.RawMessage, _ int) Result {
	var p filePutParams
	if err := decodeParams(raw, &p); err != nil {
		return cannotLaunch(DetailBadParams)
	}
	mode, modeOK := ParseMode(p.Mode)
	if !transferIDRE.MatchString(p.TransferID) || !hex64RE.MatchString(p.Token) || !hex64RE.MatchString(p.SHA256) ||
		p.Path == "" || p.Size == nil || *p.Size < 0 || !modeOK {
		return cannotLaunch(DetailBadParams)
	}
	size := *p.Size
	wantSum := strings.ToLower(p.SHA256)
	if size > MaxTransferBytes {
		return xferFailure(p.TransferID, DetailTooLarge)
	}
	dest, err := f.Policy.Check(p.Path, p.Allow)
	if err != nil {
		return xferFailure(p.TransferID, err.Error())
	}
	// The policy returns the symlink-resolved path. A destination that is itself a link is refused outright: a file_put never
	// writes through a link, even one whose target is allowed.
	if li, err := os.Lstat(p.Path); err == nil && li.Mode()&fs.ModeSymlink != 0 {
		return xferFailure(p.TransferID, "dest_is_symlink")
	}
	dir := filepath.Dir(dest)
	if di, err := os.Stat(dir); err != nil || !di.IsDir() {
		return xferFailure(p.TransferID, "parent_missing")
	}
	if li, err := os.Lstat(dest); err == nil {
		switch {
		case li.Mode()&fs.ModeSymlink != 0:
			return xferFailure(p.TransferID, "dest_is_symlink")
		case li.IsDir():
			return xferFailure(p.TransferID, "dest_is_directory")
		case !li.Mode().IsRegular():
			return xferFailure(p.TransferID, "dest_not_regular")
		case !p.Overwrite:
			return xferFailure(p.TransferID, "exists")
		}
	} else if !errors.Is(err, fs.ErrNotExist) {
		return xferFailure(p.TransferID, "dest_stat_failed")
	}
	tmpPath := filepath.Join(dir, TempName(p.TransferID))
	tmp, err := createTemp(tmpPath)
	if err != nil {
		return xferFailure(p.TransferID, "temp_failed")
	}
	committed := false
	defer func() {
		tmp.Close()
		if !committed {
			os.Remove(tmpPath)
		}
	}()
	h := sha256.New()
	var offset int64
	if size == 0 {
		// Nothing to fetch; the announced checksum must be that of an empty file.
		if hex.EncodeToString(h.Sum(nil)) != wantSum {
			return xferFailure(p.TransferID, "metadata_mismatch")
		}
	}
	for offset < size {
		if ctx.Err() != nil {
			return xferFailure(p.TransferID, "cancelled")
		}
		length := size - offset
		if length > DefaultChunk {
			length = DefaultChunk
		}
		q := url.Values{"transfer_id": {p.TransferID}, "offset": {strconv.FormatInt(offset, 10)}, "length": {strconv.FormatInt(length, 10)}}
		resp, resync, detail := f.doChunk(ctx, api.TransferRequest{Method: http.MethodGet, Query: q, Header: map[string]string{"X-Transfer-Token": p.Token}})
		if resync != nil {
			return xferFailure(p.TransferID, "download_failed") // a download has nothing to resume from the server side
		}
		if detail != "" {
			if strings.HasPrefix(detail, "server_refused:") || detail == "cancelled" {
				return xferFailure(p.TransferID, detail)
			}
			return xferFailure(p.TransferID, "download_failed")
		}
		if resp.Status != http.StatusOK {
			return xferFailure(p.TransferID, "download_failed")
		}
		if hs := resp.Header.Get("X-Transfer-Size"); hs != strconv.FormatInt(size, 10) {
			return xferFailure(p.TransferID, "metadata_mismatch")
		}
		if hh := strings.ToLower(resp.Header.Get("X-Transfer-Sha256")); hh != wantSum {
			return xferFailure(p.TransferID, "metadata_mismatch")
		}
		body := resp.Body
		if int64(len(body)) == 0 {
			return xferFailure(p.TransferID, "download_failed")
		}
		if int64(len(body)) > length {
			return xferFailure(p.TransferID, "size_mismatch")
		}
		if _, err := tmp.Write(body); err != nil {
			return xferFailure(p.TransferID, "write_failed")
		}
		h.Write(body)
		offset += int64(len(body))
	}
	if offset != size {
		return xferFailure(p.TransferID, "size_mismatch")
	}
	got := hex.EncodeToString(h.Sum(nil))
	if got != wantSum {
		return xferFailure(p.TransferID, "checksum_mismatch")
	}
	if err := tmp.Sync(); err != nil {
		return xferFailure(p.TransferID, "write_failed")
	}
	if err := tmp.Close(); err != nil {
		return xferFailure(p.TransferID, "write_failed")
	}
	if f.goos() != "windows" {
		if err := os.Chmod(tmpPath, mode); err != nil {
			return xferFailure(p.TransferID, "chmod_failed")
		}
	}
	if err := commitFile(tmpPath, dest, p.Overwrite); err != nil {
		if errors.Is(err, errExists) {
			return xferFailure(p.TransferID, "exists")
		}
		return xferFailure(p.TransferID, "commit_failed")
	}
	committed = true
	return Result{OK: true, Output: marshal(filePutOut{V: 1, TransferID: p.TransferID, Path: dest, Bytes: size, SHA256: got})}
}

// createTemp creates the transfer temp file O_CREATE|O_EXCL, mode 0600. A stale
// file of a crashed earlier run of the same transfer is removed first.
func createTemp(path string) (*os.File, error) {
	open := func() (*os.File, error) { return os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600) }
	f, err := open()
	if errors.Is(err, fs.ErrExist) {
		if li, lerr := os.Lstat(path); lerr == nil && (li.Mode().IsRegular() || li.Mode()&fs.ModeSymlink != 0) {
			if os.Remove(path) == nil {
				return open()
			}
		}
	}
	return f, err
}
