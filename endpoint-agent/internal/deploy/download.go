package deploy

import (
	"context"
	"crypto/sha256"
	"crypto/subtle"
	"crypto/tls"
	"encoding/hex"
	"errors"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

var errRedirect = errors.New("redirect refused")

var stageNameRe = regexp.MustCompile(`^deploy-[0-9]+$`)

// newHTTPClient is the production client: TLS 1.2+, verification against the system roots, the default proxy environment, no keep-alive.
func newHTTPClient() *http.Client {
	tr := &http.Transport{
		Proxy:               http.ProxyFromEnvironment,
		TLSClientConfig:     &tls.Config{MinVersion: tls.VersionTLS12},
		TLSHandshakeTimeout: 20 * time.Second,
		DisableKeepAlives:   true,
	}
	return &http.Client{Transport: tr}
}

// checkRedirect follows at most 3 redirects, each to https and to the very host the job named.
func checkRedirect(req *http.Request, via []*http.Request) error {
	if len(via) > 3 || req.URL.Scheme != "https" || !strings.EqualFold(req.URL.Host, via[0].URL.Host) || req.URL.User != nil {
		return errRedirect
	}
	return nil
}

func removeStage(dir string) { _ = os.RemoveAll(dir) }

// sweepStale removes staging directories a crashed run left behind (ours by name, older than a day).
func sweepStale(parent string) {
	ents, err := os.ReadDir(parent)
	if err != nil {
		return
	}
	for _, en := range ents {
		if !en.IsDir() || !stageNameRe.MatchString(en.Name()) {
			continue
		}
		if fi, err := en.Info(); err == nil && time.Since(fi.ModTime()) > 24*time.Hour {
			_ = os.RemoveAll(filepath.Join(parent, en.Name()))
		}
	}
}

// download fetches p.URL into a fresh private directory. It returns the directory (when one was created: the caller removes it, always), the staged
// file, and on failure the result code (download_failed, hash_mismatch or failed) with a short detail that never holds the URL.
func (e Engine) download(ctx context.Context, p InstallParams) (dir, file, res, detail string) {
	parent := e.StateDir
	if parent == "" {
		parent = os.TempDir()
	} else {
		sweepStale(parent)
	}
	dir, err := os.MkdirTemp(parent, "deploy-")
	if err != nil {
		return "", "", ResFailed, "cannot create the staging directory"
	}
	_ = os.Chmod(dir, 0o700)
	ext := "msi"
	if p.Fmt == FmtExe {
		ext = "exe"
	}
	file = filepath.Join(dir, "package."+ext)

	base := e.HTTP
	if base == nil {
		base = newHTTPClient()
	}
	cl := *base // our redirect policy always wins over the injected client's
	cl.CheckRedirect = checkRedirect
	u, err := url.Parse(p.URL)
	if err != nil || u.Scheme != "https" {
		return dir, "", ResDownloadFailed, "invalid_url"
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return dir, "", ResDownloadFailed, "invalid_url"
	}
	req.Header.Set("User-Agent", "RivetIT-Agent")
	req.Header.Set("Accept-Encoding", "identity")
	resp, err := cl.Do(req)
	if err != nil {
		return dir, "", ResDownloadFailed, netDetail(ctx, err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return dir, "", ResDownloadFailed, "http_" + itoa(resp.StatusCode)
	}
	if enc := strings.ToLower(resp.Header.Get("Content-Encoding")); enc != "" && enc != "identity" {
		return dir, "", ResDownloadFailed, "content_encoding"
	}
	if resp.ContentLength >= 0 && resp.ContentLength != p.Size {
		return dir, "", ResDownloadFailed, "size_mismatch"
	}
	f, err := os.OpenFile(file, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return dir, "", ResFailed, "cannot create the staged file"
	}
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, p.Size+1))
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return dir, "", ResDownloadFailed, netDetail(ctx, err)
	}
	if n != p.Size {
		return dir, "", ResDownloadFailed, "size_mismatch"
	}
	if subtle.ConstantTimeCompare([]byte(hex.EncodeToString(h.Sum(nil))), []byte(p.SHA256)) != 1 {
		return dir, "", ResHashMismatch, "the download does not match the pinned sha256"
	}
	return dir, file, "", ""
}

// netDetail names a transport failure without the URL (which may hold a signed query string).
func netDetail(ctx context.Context, err error) string {
	switch {
	case errors.Is(err, errRedirect):
		return "redirect_refused"
	case ctx.Err() != nil || errors.Is(err, context.DeadlineExceeded):
		return DetailTimeout
	}
	return "transfer_failed"
}

// fileMatches re-hashes the staged file.
func fileMatches(path, want string, size int64) (bool, error) {
	f, err := os.Open(path)
	if err != nil {
		return false, err
	}
	defer f.Close()
	h := sha256.New()
	n, err := io.Copy(h, io.LimitReader(f, size+1))
	if err != nil {
		return false, err
	}
	return n == size && subtle.ConstantTimeCompare([]byte(hex.EncodeToString(h.Sum(nil))), []byte(want)) == 1, nil
}
