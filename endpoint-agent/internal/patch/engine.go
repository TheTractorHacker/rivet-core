package patch

import (
	"context"
	"encoding/json"
	"io"
	"os"
	"runtime"
	"sort"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/remote"
)

// PSRunner runs a fixed PowerShell script with extra environment variables (Windows only; nil elsewhere). It is the seam that keeps PowerShell out
// of the unit tests.
type PSRunner func(ctx context.Context, script string, env []string, timeout time.Duration) jobs.ExecResult

// FS is the little file access the Linux side needs (reboot markers, package manager binaries, history logs). Injected by the tests.
type FS interface {
	Exists(path string) bool
	// ReadTail returns at most max bytes from the end of the file.
	ReadTail(path string, max int64) ([]byte, error)
}

type osFS struct{}

func (osFS) Exists(p string) bool { _, err := os.Stat(p); return err == nil }

func (osFS) ReadTail(p string, max int64) ([]byte, error) {
	f, err := os.Open(p)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	if st, err := f.Stat(); err == nil && st.Size() > max {
		if _, err := f.Seek(st.Size()-max, io.SeekStart); err != nil {
			return nil, err
		}
	}
	return io.ReadAll(io.LimitReader(f, max))
}

// Engine implements the two jobs. The zero value is the production engine for the running OS.
type Engine struct {
	GOOS  string           // "" = runtime.GOOS
	R     remote.Runner    // nil = remote.ExecRunner{}
	PS    PSRunner         // nil = the real PowerShell on Windows, none elsewhere
	FS    FS               // nil = the real file system
	Now   func() time.Time // nil = time.Now
	Local *time.Location   // zone of the zone-less timestamps in the Linux logs; nil = time.Local
}

// installMu serialises patch_install on one machine (the executor may run several jobs at once): a second concurrent install fails fast with busy.
var installMu sync.Mutex

func (e Engine) goos() string {
	if e.GOOS == "" {
		return runtime.GOOS
	}
	return e.GOOS
}

func (e Engine) runner() remote.Runner {
	if e.R == nil {
		return remote.ExecRunner{}
	}
	return e.R
}

func (e Engine) fs() FS {
	if e.FS == nil {
		return osFS{}
	}
	return e.FS
}

func (e Engine) now() time.Time {
	if e.Now == nil {
		return time.Now().UTC()
	}
	return e.Now().UTC()
}

func (e Engine) ps() PSRunner {
	if e.PS != nil {
		return e.PS
	}
	return defaultPS()
}

// Handlers returns the job handlers by job type for the executor.
func (e Engine) Handlers() map[string]remote.Handler {
	return map[string]remote.Handler{JobScan: e.Scan, JobInstall: e.Install}
}

// Scan is patch_scan.
func (e Engine) Scan(ctx context.Context, raw json.RawMessage, maxOut int) remote.Result {
	p, err := ParseScanParams(raw)
	if err != nil {
		return remote.Cannot(DetailInvalidParams)
	}
	switch e.goos() {
	case "windows":
		return e.scanWindows(ctx, p, maxOut)
	case "linux":
		return e.scanLinux(ctx, p, maxOut)
	}
	return remote.Cannot(DetailUnsupported)
}

// Install is patch_install.
func (e Engine) Install(ctx context.Context, raw json.RawMessage, maxOut int) remote.Result {
	if g := e.goos(); g != "windows" && g != "linux" {
		return remote.Cannot(DetailUnsupported)
	}
	p, err := ParseInstallParams(e.goos(), raw)
	if err != nil {
		return remote.Cannot(DetailInvalidParams)
	}
	if !installMu.TryLock() {
		return remote.Fail(DetailBusy, nil)
	}
	defer installMu.Unlock()
	if e.goos() == "windows" {
		return e.installWindows(ctx, p, maxOut)
	}
	return e.installLinux(ctx, p, maxOut)
}

func rfc3339(t time.Time) string { return t.UTC().Format(time.RFC3339) }

// clip cuts s to at most n bytes without splitting a rune, and replaces control characters by spaces.
func clip(s string, n int) string {
	s = strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f {
			return ' '
		}
		return r
	}, strings.ToValidUTF8(s, "?"))
	if len(s) > n {
		s = s[:n]
		for !utf8.ValidString(s) {
			s = s[:len(s)-1]
		}
	}
	return s
}

func rank(u Update) int {
	if u.Kind == "security" || u.Kind == "critical" {
		return 0
	}
	return 1
}

// sortUpdates orders critical/security first, then by title, then by id (a total order, so the output is deterministic).
func sortUpdates(us []Update) {
	sort.SliceStable(us, func(i, j int) bool {
		a, b := us[i], us[j]
		if rank(a) != rank(b) {
			return rank(a) < rank(b)
		}
		if la, lb := strings.ToLower(a.Title), strings.ToLower(b.Title); la != lb { // case-insensitive, like the Windows script's Sort-Object
			return la < lb
		}
		if a.Title != b.Title {
			return a.Title < b.Title
		}
		return a.ID < b.ID
	})
}

// finishScan applies the item cap and the output cap and renders the document.
func finishScan(out ScanOut, maxItems, maxOut int) remote.Result {
	sortUpdates(out.Updates)
	if out.Total < len(out.Updates) {
		out.Total = len(out.Updates)
	}
	all := out.Updates
	trunc := out.Truncated
	if len(all) > maxItems {
		all, trunc = all[:maxItems], true
	}
	if all == nil {
		all = []Update{}
	}
	if out.Total > len(all) {
		trunc = true
	}
	doc, ok := remote.FitList(len(all), trunc, maxOut, func(n int, t bool) string {
		o := out
		o.Updates, o.Truncated = all[:n], t
		b, err := json.Marshal(o)
		if err != nil {
			return `{"v":1,"ok":false,"detail":"internal_error"}`
		}
		return string(b)
	})
	if !ok {
		return remote.Fail("output_cap_too_small", nil)
	}
	return remote.Result{OK: true, Output: doc}
}

// finishInstall renders the install document; the verdict is computed here, never taken from a script.
func finishInstall(out InstallOut, maxOut int) remote.Result {
	out.V = 1
	out.OK = true
	for _, r := range out.Results {
		if !goodResults[r.Result] {
			out.OK = false
		}
		if r.RebootRequired && (r.Result == "installed" || r.Result == "needs_reboot") {
			out.RebootRequired = true
		}
	}
	if out.Results == nil {
		out.Results = []ResultItem{}
	}
	all := out.Results
	doc, ok := remote.FitList(len(all), out.Truncated, maxOut, func(n int, t bool) string {
		o := out
		o.Results, o.Truncated = all[:n], t
		b, err := json.Marshal(o)
		if err != nil {
			return `{"v":1,"ok":false,"detail":"internal_error"}`
		}
		return string(b)
	})
	if !ok {
		return remote.Fail("output_cap_too_small", nil)
	}
	if out.OK {
		return remote.Result{OK: true, Output: doc}
	}
	return remote.Result{OK: false, Detail: "install_failed", Output: doc}
}
