package deploy

import (
	"context"
	"encoding/json"
	"net/http"
	"os"
	"runtime"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"rivetit-agent/internal/remote"
)

// FS is the little file access the Linux side needs (package manager binaries, the reboot marker). Injected by the tests.
type FS interface {
	Exists(path string) bool
}

type osFS struct{}

func (osFS) Exists(p string) bool { _, err := os.Stat(p); return err == nil }

// Engine implements the two jobs. The zero value is the production engine for the running OS (plus StateDir, which the agent sets).
type Engine struct {
	GOOS     string                  // "" = runtime.GOOS
	R        remote.Runner           // nil = remote.ExecRunner{}
	FS       FS                      // nil = the real file system
	Reg      Registry                // Windows uninstall registry; nil = the real one (none elsewhere)
	HTTP     *http.Client            // nil = a client with the system roots; its redirect policy is always replaced by ours
	StateDir string                  // parent of the private staging directory; "" = os.TempDir()
	Getenv   func(string) string     // nil = os.Getenv
	beforeEx func(stagedPath string) // test hook: runs between the final hash check and the execution
}

// installMu serialises software_install on one machine (the executor may run several jobs at once): a second concurrent install fails fast with busy.
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

func (e Engine) getenv(k string) string {
	if e.Getenv != nil {
		return e.Getenv(k)
	}
	return os.Getenv(k)
}

// Handlers returns the job handlers by job type for the executor.
func (e Engine) Handlers() map[string]remote.Handler {
	return map[string]remote.Handler{JobDetect: e.Detect, JobInstall: e.Install}
}

func supportedGOOS(g string) bool { return g == "windows" || g == "linux" }

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

// Detect is software_detect (read only).
func (e Engine) Detect(ctx context.Context, raw json.RawMessage, maxOut int) remote.Result {
	if !supportedGOOS(e.goos()) {
		return remote.Cannot(DetailUnsupported)
	}
	items, err := ParseDetectParams(raw)
	if err != nil {
		return remote.Cannot(DetailInvalidParams)
	}
	pr := e.newProbe(ctx, "")
	out := DetectOut{V: 1, OK: true, Items: make([]ItemOut, 0, len(items))}
	for _, it := range items {
		r := pr.check(it)
		if r.fatal != "" {
			return remote.Fail(r.fatal, nil)
		}
		out.Items = append(out.Items, ItemOut{Key: it.Key, Installed: r.installed, Version: clip(r.version, 64), Satisfied: satisfied(r.installed, r.version, it.Min), Detail: clip(r.detail, maxDetail)})
	}
	b, err := json.Marshal(out)
	if err != nil || len(b) > maxOut {
		return remote.Fail("output_cap_too_small", nil)
	}
	return remote.Result{OK: true, Output: string(b)}
}

// outcome is what one install attempt came to.
type outcome struct {
	result, code, detail, version string
	reboot                        bool
	cannot                        string // a failure before anything ran (the executor reports no exit code)
}

func fail(code, detail string) outcome { return outcome{result: ResFailed, code: code, detail: detail} }

// Install is software_install.
func (e Engine) Install(ctx context.Context, raw json.RawMessage, maxOut int) remote.Result {
	if !supportedGOOS(e.goos()) {
		return remote.Cannot(DetailUnsupported)
	}
	p, err := ParseInstallParams(raw)
	if err != nil {
		return remote.Cannot(DetailInvalidParams)
	}
	if (e.goos() == "linux") != (p.Fmt == FmtApt || p.Fmt == FmtDnf) {
		return remote.Cannot(DetailUnsupported) // apt/dnf on Windows, msi/exe on Linux
	}
	if !installMu.TryLock() {
		return render(p, outcome{result: ResFailed, code: DetailBusy, detail: DetailBusy}, maxOut)
	}
	defer installMu.Unlock()
	ctx, cancel := context.WithTimeout(ctx, time.Duration(p.MaxMinutes)*time.Minute)
	defer cancel()
	var o outcome
	if e.goos() == "linux" {
		o = e.installLinux(ctx, p)
	} else {
		o = e.installWindows(ctx, p)
	}
	if o.cannot != "" {
		return remote.Cannot(o.cannot)
	}
	return render(p, o, maxOut)
}

func render(p InstallParams, o outcome, maxOut int) remote.Result {
	doc := InstallOut{
		V: 1, OK: goodResults[o.result], Action: p.Action, Mode: p.Mode, Fmt: p.Fmt, Result: o.result,
		Code: clip(o.code, 40), Detail: clip(remote.SanitizeDetail(o.detail), maxDetail), Version: clip(o.version, 64), RebootRequired: o.reboot,
	}
	b, err := json.Marshal(doc)
	if err != nil || len(b) > maxOut {
		return remote.Fail("output_cap_too_small", nil)
	}
	if doc.OK {
		return remote.Result{OK: true, Output: string(b)}
	}
	return remote.Result{OK: false, Detail: o.result, Output: string(b)}
}

// probeRes is the detection verdict for one item.
type probeRes struct {
	installed bool
	version   string
	detail    string
	fatal     string // the whole job fails with this code
}

// probe answers detection questions for one job; the registry is read once.
type probe struct {
	e   Engine
	ctx context.Context
	pm  string // "dpkg" | "rpm" | "" (decide by what is present)
	lx  *lx

	regLoaded bool
	regList   []UninstallEntry
	regErr    string
}

func (e Engine) newProbe(ctx context.Context, pm string) *probe {
	return &probe{e: e, ctx: ctx, pm: pm}
}

func (pr *probe) check(it Item) probeRes {
	switch pr.e.goos() {
	case "linux":
		if it.Kind != KindPackage {
			return probeRes{detail: "unsupported_kind"}
		}
		return pr.checkPackage(it.Value)
	case "windows":
		if it.Kind == KindPackage {
			return probeRes{detail: "unsupported_kind"}
		}
		return pr.checkRegistry(it)
	}
	return probeRes{fatal: DetailUnsupported}
}
