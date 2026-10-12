package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"rivetit-agent/internal/embed"
	"rivetit-agent/internal/logx"
)

// Exit codes of `setup` (and of a no-argument run of a stamped installer).
const (
	exitOK          = 0
	exitBadConfig   = 2 // embedded configuration missing, invalid or expired (also bad flags)
	exitRejected    = 3 // server rejected the enrollment token (invalid, expired, used up)
	exitTransient   = 4 // network/transient: the install is done, enrollment is pending; re-run is safe
	exitInstallFail = 5 // install or service failure
	exitNotElevated = 6 // not elevated and cannot elevate
)

// tokenRe matches anything that looks like an enrollment token.
var tokenRe = regexp.MustCompile(`rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+`)

// redactWriter scrubs enrollment tokens from every line written through it.
// (Defense in depth: the code never logs the token on purpose.)
type redactWriter struct {
	mu sync.Mutex
	w  io.Writer
}

func (r *redactWriter) Write(p []byte) (int, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if _, err := r.w.Write(tokenRe.ReplaceAll(p, []byte("rvte1.[redacted]"))); err != nil {
		return 0, err
	}
	return len(p), nil
}

// escapeArg quotes one argument for a Windows command line (CommandLineToArgvW
// rules; same algorithm as golang.org/x/sys/windows.EscapeArg, kept here so it
// is testable on every OS).
func escapeArg(s string) string {
	if s == "" {
		return `""`
	}
	if !strings.ContainsAny(s, " \t\n\v\"") {
		return s
	}
	var b strings.Builder
	b.WriteByte('"')
	bs := 0
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c == '\\':
			bs++
		case c == '"':
			b.WriteString(strings.Repeat(`\`, bs*2+1))
			b.WriteByte('"')
			bs = 0
		default:
			b.WriteString(strings.Repeat(`\`, bs))
			bs = 0
			b.WriteByte(c)
		}
	}
	b.WriteString(strings.Repeat(`\`, bs*2))
	b.WriteByte('"')
	return b.String()
}

// joinArgs builds the ShellExecute parameter string.
func joinArgs(args []string) string {
	q := make([]string, len(args))
	for i, a := range args {
		q[i] = escapeArg(a)
	}
	return strings.Join(q, " ")
}

type setupOpts struct {
	silent, noService, elevated bool
	noMesh                      bool // --no-mesh-agent: ignore a Mesh request in the installer payload
	stateDir, installDir        string
	explicit                    map[string]bool

	// MSI package (packaging/msi): the installer is Windows Installer, which has already placed this program in the install directory.
	fromMSI     bool   // --from-msi: no Add/Remove entry (the MSI owns it), enrollment pending is not a failure, an upgrade may reuse the existing enrollment
	payloadFrom string // --payload-from FILE: read the appended installer payload from FILE (the .msi) instead of from this program
	// Public properties of the MSI (msiexec SERVER=... ENROLL_TOKEN=...): the payload-less way to enroll. Only with --from-msi.
	server, token, tokenFile, caFile string
}

// elevatedArgs rebuilds the child command line from the PARSED options only
// (an allowlist), never from raw os.Args, so nothing user-controlled can add a
// flag or a command. --silent is deliberately absent: silent runs never elevate.
// Custom --state-dir/--install-dir are NOT forwarded (see runSetup): an
// unelevated user must not be able to point an administrator-approved process
// at a path of their choosing (service binary swap, state purge).
func (o setupOpts) elevatedArgs() []string {
	a := []string{"setup", "--elevated"}
	if o.noService {
		a = append(a, "--no-service")
	}
	if o.noMesh {
		a = append(a, "--no-mesh-agent")
	}
	return a
}

func parseSetup(args []string) (setupOpts, int) {
	fs, dir := newFlags("setup")
	var o setupOpts
	fs.BoolVar(&o.silent, "silent", false, "never show UI; report the result through the exit code")
	fs.BoolVar(&o.noService, "no-service", false, "do not register or start the service (test mode)")
	fs.BoolVar(&o.elevated, "elevated", false, "internal: this process was relaunched elevated")
	fs.BoolVar(&o.noMesh, "no-mesh-agent", false, "do not install the MeshCentral agent even if this installer carries a request for it")
	fs.BoolVar(&o.fromMSI, "from-msi", false, "run by the RivetIT MSI package (internal): this program is already installed")
	fs.StringVar(&o.payloadFrom, "payload-from", "", "read the installer payload appended to this file (the .msi) instead of to this program; needs --from-msi")
	fs.StringVar(&o.server, "server", "", "MSI property mode: RivetIT base URL (needs --from-msi and a token unless the device is already enrolled)")
	fs.StringVar(&o.token, "token", "", "MSI property mode: enrollment token (prefer --token-file)")
	fs.StringVar(&o.tokenFile, "token-file", "", "MSI property mode: file containing the enrollment token")
	fs.StringVar(&o.caFile, "ca-file", "", "MSI property mode: extra CA certificate (PEM) for an internal CA")
	installDir := fs.String("install-dir", defaultInstallDir(), "binary directory")
	if err := fs.Parse(args); err != nil {
		if errors.Is(err, flag.ErrHelp) {
			return o, -1
		}
		return o, exitBadConfig
	}
	if fs.NArg() > 0 {
		fmt.Fprintln(os.Stderr, "setup takes no positional arguments")
		return o, exitBadConfig
	}
	// The MSI-only options are refused anywhere else, and the two payload sources exclude each other.
	if !o.fromMSI && (o.payloadFrom != "" || o.server != "" || o.token != "" || o.tokenFile != "" || o.caFile != "") {
		fmt.Fprintln(os.Stderr, "--payload-from, --server, --token, --token-file and --ca-file belong to the MSI package and need --from-msi")
		return o, exitBadConfig
	}
	if o.payloadFrom != "" && (o.server != "" || o.token != "" || o.tokenFile != "" || o.caFile != "") {
		fmt.Fprintln(os.Stderr, "--payload-from cannot be combined with --server, --token, --token-file or --ca-file")
		return o, exitBadConfig
	}
	if o.server == "" && (o.token != "" || o.tokenFile != "" || o.caFile != "") {
		fmt.Fprintln(os.Stderr, "--token, --token-file and --ca-file need --server")
		return o, exitBadConfig
	}
	o.explicit = map[string]bool{}
	fs.Visit(func(f *flag.Flag) { o.explicit[f.Name] = true })
	o.stateDir, o.installDir = *dir, *installDir
	if o.installDir == "" && o.stateDir != "" {
		// Platforms without a standard program directory (tests, unsupported unix): stage under the state dir.
		o.installDir = filepath.Join(o.stateDir, "bin")
	}
	return o, 0
}

func cmdSetup(args []string) int { return runSetup(args, realOps(), time.Now) }

// runSetup is the stamped installer entry point.
func runSetup(args []string, ops sysOps, now func() time.Time) int {
	o, code := parseSetup(args)
	if code == -1 {
		return 0
	}
	if code != 0 {
		return code
	}
	title := "RivetIT Agent setup"
	say := func(msg string, isErr bool) {
		if isErr {
			fmt.Fprintln(os.Stderr, msg)
		} else {
			fmt.Println(msg)
		}
		if !o.silent {
			ops.message(title, msg, isErr)
		}
	}

	self, err := ops.executable()
	if err != nil {
		say("Cannot locate the installer: "+err.Error(), true)
		return exitInstallFail
	}
	// Where the configuration comes from: the payload appended to this program (the exe installer), to the .msi named by --payload-from (the MSI
	// package), or the MSI's public properties. Validate before any elevation prompt: a bad installer should fail early.
	src := self
	if o.payloadFrom != "" {
		src = o.payloadFrom
	}
	var pl *embed.Payload
	refresh := false // an MSI upgrade without a usable payload: keep the enrollment, only re-register the service
	var embedErr error
	if o.server != "" {
		var perr error
		if pl, perr = o.propertyPayload(now()); perr != nil {
			say(perr.Error(), true)
			return exitBadConfig
		}
	} else {
		pl, err = embed.Read(src, now())
		if err != nil {
			if o.fromMSI && (errors.Is(err, embed.ErrNotStamped) || errors.Is(err, embed.ErrExpired)) {
				refresh, embedErr = true, err // decided after the elevation check, when the state directory can be read
			} else {
				code, msg := classifyEmbedErr(err)
				if o.elevated {
					// The user already saw this in the parent's relaunch; keep quiet UI-wise.
					fmt.Fprintln(os.Stderr, msg)
					return code
				}
				say(msg, true)
				return code
			}
		}
	}

	if !ops.isElevated() {
		if o.silent || o.elevated {
			msg := "RivetIT Agent setup needs administrator rights and cannot elevate."
			fmt.Fprintln(os.Stderr, msg)
			if !o.silent {
				ops.message(title, msg, true)
			}
			return exitNotElevated
		}
		if o.fromMSI {
			say("The MSI setup step must run elevated (Windows Installer runs it as SYSTEM).", true)
			return exitNotElevated
		}
		if o.explicit["state-dir"] || o.explicit["install-dir"] {
			say("Custom --state-dir/--install-dir are only accepted from an already elevated run (for example SYSTEM or an elevated prompt).", true)
			return exitNotElevated
		}
		fmt.Println("Administrator rights are required; asking Windows to elevate...")
		c, err := ops.elevate(o.elevatedArgs())
		if err != nil {
			say("RivetIT Agent setup needs administrator rights and could not elevate: "+err.Error(), true)
			return exitNotElevated
		}
		return c
	}

	st, err := openStore(o.stateDir)
	if err != nil {
		say("Cannot prepare the state directory: "+err.Error(), true)
		return exitInstallFail
	}
	logPath := filepath.Join(st.Dir, "install.log")
	var sink io.Writer = os.Stderr
	if o.silent {
		sink = io.Discard
	}
	if lw, err := logx.NewRotating(logPath, 1<<20); err == nil {
		defer lw.Close()
		sink = io.MultiWriter(lw, sink)
	} else {
		fmt.Fprintln(os.Stderr, "warning: cannot write", logPath+":", err)
	}
	log := slog.New(slog.NewTextHandler(&redactWriter{w: sink}, &slog.HandlerOptions{Level: slog.LevelInfo}))

	f := enrollFlags{}
	if refresh {
		if existing, _ := st.LoadToken(); existing == "" {
			// no payload and nothing to keep: the usual explanation
			code, msg := classifyEmbedErr(embedErr)
			say(msg, true)
			return code
		}
		log.Info("setup start (MSI upgrade or repair without a payload: keeping the existing enrollment)", "version", version, "exe", self, "silent", o.silent)
	} else {
		log.Info("setup start", "version", version, "exe", self, "installer_id", pl.InstallerID, "server", pl.ServerURL,
			"department", pl.Department, "token", pl.RedactedToken(), "expires_at", pl.ExpiresAt, "silent", o.silent, "no_service", o.noService, "from_msi", o.fromMSI, "payload_from", src)
		f = enrollFlags{server: pl.ServerURL, department: pl.Department}
		f.token = pl.EnrollmentToken
		if pl.CAPEM != nil {
			f.caPEM = []byte(*pl.CAPEM)
		}
	}
	res := performInstall(installParams{flags: f, stateDir: o.stateDir, installDir: o.installDir, noService: o.noService,
		skipIfEnroll: true, skipARP: o.fromMSI, self: self, version: version}, ops, log)
	code, msg, isErr := setupOutcome(res, logPath)
	if o.fromMSI && res.Kind == instDeferred {
		// Windows Installer treats a non-zero exit as a failed install and rolls it back, which would defeat an image or GPO install made while the
		// server is unreachable: the service is registered and keeps retrying with the stored one-shot token.
		code, isErr = exitOK, false
	}
	// The optional MeshCentral agent comes AFTER the RivetIT agent is in place and can never change the outcome or the exit code.
	if !o.noMesh && pl != nil && (res.Kind == instOK || res.Kind == instDeferred || res.Kind == instDeferredFail) {
		if line := installMeshAgent(context.Background(), ops, pl.InstallMeshAgent, pl.Mesh, st.Dir, log); line != "" {
			msg += "\n" + line
		}
	}
	log.Info("setup finished", "exit", code, "result", res.Msg)
	say(msg, isErr)
	return code
}

// propertyPayload builds the installer configuration from the MSI's public properties (--server and friends) with the payload's own validation, so a
// property can never carry what a stamped installer could not. The token may be empty (an already enrolled device being reinstalled).
func (o setupOpts) propertyPayload(now time.Time) (*embed.Payload, error) {
	if err := embed.ValidateServerURL(o.server); err != nil {
		return nil, errors.New("the SERVER property is not usable: " + strings.TrimPrefix(err.Error(), embed.ErrInvalid.Error()+": "))
	}
	tok, err := readToken(o.token, o.tokenFile)
	if err != nil {
		return nil, errors.New("cannot read the enrollment token file")
	}
	if tok != "" {
		if err := embed.ValidateToken(tok); err != nil {
			return nil, errors.New("the enrollment token has the wrong shape")
		}
	}
	p := &embed.Payload{Version: 1, ServerURL: o.server, EnrollmentToken: tok, Department: "", CreatedAt: now.UTC().Format(time.RFC3339), ExpiresAt: now.Add(time.Hour).UTC().Format(time.RFC3339)}
	if o.caFile != "" {
		b, err := os.ReadFile(o.caFile)
		if err != nil {
			return nil, errors.New("cannot read the CA_PEM_FILE")
		}
		pem := string(b)
		if err := embed.ValidateCAPEM(pem); err != nil {
			return nil, errors.New("the CA_PEM_FILE is not a PEM certificate bundle")
		}
		p.CAPEM = &pem
	}
	return p, nil
}

func classifyEmbedErr(err error) (int, string) {
	switch {
	case errors.Is(err, embed.ErrNotStamped):
		return exitBadConfig, "This installer has no embedded configuration (it was not downloaded from RivetIT). Download the installer for your department again."
	case errors.Is(err, embed.ErrExpired):
		return exitBadConfig, "This installer has expired. Download a new installer from RivetIT."
	case errors.Is(err, embed.ErrInvalid):
		return exitBadConfig, "This installer's embedded configuration is damaged or invalid (" + strings.TrimPrefix(err.Error(), embed.ErrInvalid.Error()+": ") + "). Download it again."
	}
	return exitInstallFail, "Cannot read the installer: " + err.Error()
}

func setupOutcome(r installResult, logPath string) (code int, msg string, isErr bool) {
	tail := "\nLog: " + logPath
	switch r.Kind {
	case instOK:
		return exitOK, "RivetIT Agent was installed and is running." + tail, false
	case instDeferred:
		return exitTransient, "RivetIT Agent was installed, but the server could not be reached to finish enrolling. It will keep retrying; running this installer again is safe." + tail, true
	case instDeferredFail:
		return exitInstallFail, "RivetIT Agent was installed, but enrollment failed on this computer: " + r.Msg + tail, true
	case instRejected:
		return exitRejected, "The server rejected this installer's enrollment (" + r.Msg + ") Download a new installer from RivetIT." + tail, true
	case instNoToken, instConfig:
		return exitBadConfig, "Setup could not use its configuration: " + r.Msg + tail, true
	}
	return exitInstallFail, "RivetIT Agent could not be installed: " + r.Msg + tail, true
}
