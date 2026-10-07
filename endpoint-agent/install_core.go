package main

import (
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"runtime"
	"strings"

	"rivetit-agent/internal/embed"
)

// sysOps is every operating-system side effect of an install, so the flow (and
// its exit codes) can be tested on Linux with fakes. realOps() is in ops_*.go.
type sysOps struct {
	isElevated   func() bool
	elevate      func(args []string) (exitCode int, err error)
	executable   func() (string, error)
	stopService  func() error
	installSvc   func(exe string, args []string) error
	startService func() error
	registerARP  func(installDir, exe string) error
	removeARP    func() error
	message      func(title, text string, isError bool)
}

type instKind int

const (
	instOK           instKind = iota
	instNoToken               // first install without a token
	instConfig                // cannot write configuration
	instRejected              // enrollment permanently rejected
	instDeferred              // installed, but enrollment must be retried by the service (transient)
	instDeferredFail          // installed, but enrollment failed locally
	instFail                  // binary/service failure
)

type installResult struct {
	Kind instKind
	Msg  string
	Dst  string // installed exe path (when reached)
}

type installParams struct {
	flags        enrollFlags
	stateDir     string
	installDir   string
	noService    bool
	skipIfEnroll bool // setup: an already enrolled device on the same server keeps its identity, no new exchange
	self         string
	version      string
}

func sameServer(a, b string) bool {
	return strings.TrimRight(strings.ToLower(a), "/") == strings.TrimRight(strings.ToLower(b), "/")
}

// performInstall is the single install code path shared by `install` and
// `setup`: config, enroll (or defer), unstamped binary copy, service, ARP.
func performInstall(p installParams, ops sysOps, log *slog.Logger) installResult {
	st, err := openStore(p.stateDir)
	if err != nil {
		return installResult{Kind: instConfig, Msg: err.Error()}
	}
	tok, _ := readToken(p.flags.token, p.flags.tokenFile)
	prevCfg, _ := st.LoadConfig()
	if err := applyConfig(st, p.flags); err != nil {
		return installResult{Kind: instConfig, Msg: err.Error()}
	}
	log.Info("configuration saved", "state_dir", st.Dir)

	// 1. enroll (idempotent)
	existing, _ := st.LoadToken()
	deferred := enrollOK
	switch {
	case tok != "" && p.skipIfEnroll && existing != "" && sameServer(prevCfg.ServerURL, p.flags.server):
		log.Info("device is already enrolled on this server: keeping its identity, skipping a new enrollment")
	case tok != "":
		o := tryEnroll(st, tok, log)
		switch o.Kind {
		case enrollOK:
			log.Info("enrolled")
		case enrollRejected:
			return installResult{Kind: instRejected, Msg: o.Msg}
		default:
			// Offline at install time (imaging/GPO): keep the one-shot token,
			// protected, and let the service finish enrollment.
			log.Warn("could not enroll now; the service will retry using the stored one-shot token", "reason", o.Msg)
			if err := st.SaveEnrollToken(tok); err != nil {
				return installResult{Kind: instConfig, Msg: err.Error()}
			}
			deferred = o.Kind
		}
	case existing == "":
		return installResult{Kind: instNoToken, Msg: "an enrollment token is required for a first install"}
	}

	// 2. binary: only the unstamped bytes ever reach the program directory
	if p.installDir == "" {
		return installResult{Kind: instFail, Msg: "no install directory"}
	}
	if err := os.MkdirAll(p.installDir, 0o755); err != nil {
		return installResult{Kind: instFail, Msg: err.Error()}
	}
	if runtime.GOOS != "windows" { // a restrictive umask (the install script uses 077 for temp files) must not leave a 0700 program dir
		_ = os.Chmod(p.installDir, 0o755)
	}
	dst := filepath.Join(p.installDir, exeName)
	if !samePath(p.self, dst) {
		if !p.noService {
			if err := ops.stopService(); err != nil {
				return installResult{Kind: instFail, Msg: "stopping existing service: " + err.Error()}
			}
		}
		if err := installBinary(p.self, dst); err != nil {
			return installResult{Kind: instFail, Msg: "installing binary: " + err.Error(), Dst: dst}
		}
		log.Info("binary installed (embedded configuration stripped)", "path", dst)
	} else if stamped(p.self) {
		return installResult{Kind: instFail, Msg: "the installed copy would still carry the embedded token; refusing"}
	}

	// 3. service + Add/Remove Programs
	if !p.noService {
		if err := ops.installSvc(dst, []string{"run", "--state-dir", p.stateDir}); err != nil {
			return installResult{Kind: instFail, Msg: err.Error(), Dst: dst}
		}
		if err := ops.startService(); err != nil {
			return installResult{Kind: instFail, Msg: "starting service: " + err.Error(), Dst: dst}
		}
		log.Info("service installed and started")
		if err := ops.registerARP(p.installDir, dst); err != nil {
			// cosmetic: the agent works without an uninstall entry
			log.Warn("could not register the Add/Remove Programs entry", "err", err)
		}
	}
	switch deferred {
	case enrollTransient:
		return installResult{Kind: instDeferred, Msg: "installed, but the server could not be reached to enroll; the service will retry", Dst: dst}
	case enrollFailed:
		return installResult{Kind: instDeferredFail, Msg: "installed, but enrollment failed locally; see the log", Dst: dst}
	}
	return installResult{Kind: instOK, Dst: dst}
}

func samePath(a, b string) bool {
	if filepath.Clean(a) == filepath.Clean(b) {
		return true
	}
	ai, e1 := os.Stat(a)
	bi, e2 := os.Stat(b)
	return e1 == nil && e2 == nil && os.SameFile(ai, bi)
}

// stamped reports whether path still carries an embedded payload footer.
func stamped(path string) bool {
	f, err := os.Open(path)
	if err != nil {
		return false
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return false
	}
	n, err := embed.UnstampedSize(f, fi.Size())
	return err != nil || n != fi.Size()
}

// installBinary writes the unstamped bytes of src next to dst and swaps them
// in, keeping the previous binary as dst.old (a running service exe cannot be
// overwritten, but it can be renamed).
func installBinary(src, dst string) error {
	tmp := dst + ".tmp"
	if _, err := embed.WriteUnstamped(src, tmp); err != nil {
		return err
	}
	_ = os.Remove(dst + ".old")
	if _, err := os.Stat(dst); err == nil {
		if err := os.Rename(dst, dst+".old"); err != nil {
			os.Remove(tmp)
			return fmt.Errorf("cannot replace the installed binary (service running? stop it first): %w", err)
		}
	}
	if err := os.Rename(tmp, dst); err != nil {
		// put the old one back so the machine keeps a working agent
		_ = os.Rename(dst+".old", dst)
		os.Remove(tmp)
		return err
	}
	if runtime.GOOS != "windows" {
		_ = os.Chmod(dst, 0o755)
	}
	_ = os.Remove(dst + ".old") // best effort; still locked if the old service process lingers
	return nil
}
