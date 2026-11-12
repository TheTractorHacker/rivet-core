package deploy

import (
	"context"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// The PHP side (CORE-15) relies on the agent enforcing the pinned installer hash and size. These tests pin that behaviour at the download step itself
// (the Windows install tests in deploy_test.go cover the same cases end to end), including the fail-closed handling of an empty pin.

func downloadWith(t *testing.T, h func(rw http.ResponseWriter, r *http.Request, body []byte), mod func(*InstallParams)) (res, detail string, w *winRig) {
	t.Helper()
	w = newWinRig(t, h)
	p := w.params(FmtMsi, "install")
	if mod != nil {
		mod(&p)
	}
	dir, file, res, detail := w.e.download(context.Background(), p)
	if dir != "" {
		defer removeStage(dir)
	}
	if res != "" && file != "" {
		t.Errorf("a refused download still returned a staged file: %s", file)
	}
	return res, detail, w
}

func TestDownloadAcceptsExactlyThePinnedBytes(t *testing.T) {
	res, detail, _ := downloadWith(t, nil, nil)
	if res != "" || detail != "" {
		t.Fatalf("the pinned payload was refused: %s %s", res, detail)
	}
}

func TestDownloadRefusesAWrongSha256(t *testing.T) {
	res, _, _ := downloadWith(t, nil, func(p *InstallParams) { p.SHA256 = strings.Repeat("0", 64) })
	if res != ResHashMismatch {
		t.Errorf("result %q, want %q", res, ResHashMismatch)
	}
}

func TestDownloadRefusesAnEmptyOrMalformedPinnedHash(t *testing.T) {
	for name, pin := range map[string]string{"empty": "", "short": "abcd", "upper-case of the right hash is not the lower-case pin": "UPPER"} {
		res, _, _ := downloadWith(t, nil, func(p *InstallParams) {
			if pin == "UPPER" {
				p.SHA256 = strings.ToUpper(p.SHA256)
			} else {
				p.SHA256 = pin
			}
		})
		if res != ResHashMismatch {
			t.Errorf("%s: result %q, want %q (fail closed)", name, res, ResHashMismatch)
		}
	}
	w := newWinRig(t, nil)
	p := w.params(FmtMsi, "install")
	p.SHA256 = ""
	if o := w.e.installWindows(context.Background(), p); o.result != ResHashMismatch || len(w.ran) != 0 {
		t.Errorf("an empty pin reached the installer: %+v ran=%v", o, w.ran)
	}
}

func TestDownloadRefusesAShortBody(t *testing.T) {
	res, detail, _ := downloadWith(t, func(rw http.ResponseWriter, r *http.Request, b []byte) { rw.Write(b[:len(b)-1]) }, nil)
	if res != ResDownloadFailed || detail != "size_mismatch" {
		t.Errorf("%s %s", res, detail)
	}
}

func TestDownloadRefusesALongBody(t *testing.T) {
	// one byte too many is enough: the reader stops at size+1 and n must equal size
	res, detail, _ := downloadWith(t, func(rw http.ResponseWriter, r *http.Request, b []byte) { rw.Write(append(append([]byte{}, b...), 'X')) }, nil)
	if res != ResDownloadFailed || detail != "size_mismatch" {
		t.Errorf("%s %s", res, detail)
	}
}

func TestDownloadRefusesAContentLengthThatIsNotThePinnedSize(t *testing.T) {
	// an honest server, but the job pinned a different size: refused from the header, before the body is read
	res, detail, _ := downloadWith(t, nil, func(p *InstallParams) { p.Size += 5 })
	if res != ResDownloadFailed || detail != "size_mismatch" {
		t.Errorf("%s %s", res, detail)
	}
}

func TestDownloadRefusesANonIdentityContentEncoding(t *testing.T) {
	for _, enc := range []string{"gzip", "br", "deflate"} {
		res, detail, _ := downloadWith(t, func(rw http.ResponseWriter, r *http.Request, b []byte) {
			rw.Header().Set("Content-Encoding", enc)
			rw.Write(b)
		}, nil)
		if res != ResDownloadFailed || detail != "content_encoding" {
			t.Errorf("%s: %s %s", enc, res, detail)
		}
	}
}

func TestDownloadSendsIdentityAcceptEncoding(t *testing.T) {
	var got string
	downloadWith(t, func(rw http.ResponseWriter, r *http.Request, b []byte) {
		got = r.Header.Get("Accept-Encoding")
		rw.Write(b)
	}, nil)
	if got != "identity" {
		t.Errorf("Accept-Encoding %q", got)
	}
}

// The pre-exec re-check (windows.go) is fileMatches on the staged file: a file changed after the download must not match.
func TestStagedFileRecheckRefusesAChangedFile(t *testing.T) {
	body := []byte("INSTALLER-BYTES-" + strings.Repeat("z", 1000))
	want := sum(body)
	path := filepath.Join(t.TempDir(), "package.msi")
	if err := os.WriteFile(path, body, 0o600); err != nil {
		t.Fatal(err)
	}
	if ok, err := fileMatches(path, want, int64(len(body))); err != nil || !ok {
		t.Fatalf("untouched file: %v %v", ok, err)
	}
	swaps := map[string][]byte{
		"same size, different content": append([]byte("EVIL"), body[4:]...),
		"truncated":                    body[:len(body)-1],
		"appended":                     append(append([]byte{}, body...), 'X'),
		"emptied":                      nil,
	}
	for name, b := range swaps {
		if err := os.WriteFile(path, b, 0o600); err != nil {
			t.Fatal(err)
		}
		if ok, err := fileMatches(path, want, int64(len(body))); err != nil || ok {
			t.Errorf("%s: matched (ok=%v err=%v)", name, ok, err)
		}
	}
	if ok, _ := fileMatches(path, "", int64(len(body))); ok {
		t.Error("an empty pin matched")
	}
	if ok, err := fileMatches(filepath.Join(t.TempDir(), "gone"), want, 1); ok || err == nil {
		t.Errorf("a missing staged file must be an error, got ok=%v err=%v", ok, err)
	}
}
