package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"time"

	"rivetit-agent/internal/agent"
	"rivetit-agent/internal/api"
	"rivetit-agent/internal/store"
)

// enrollFlags are shared by enroll, rotate and install.
type enrollFlags struct {
	server, token, tokenFile, ca, pin, department string
	caPEM                                         []byte // CA certificates supplied in memory (stamped installer); used when ca is empty
}

func applyConfig(st *store.Store, f enrollFlags) error {
	cfg, err := st.LoadConfig()
	if err != nil {
		return err
	}
	if f.server != "" {
		cfg.ServerURL = f.server
	}
	if f.pin != "" {
		cfg.PinSPKISHA256 = f.pin
	}
	if f.department != "" {
		cfg.Department = f.department
	}
	if f.ca != "" || len(f.caPEM) > 0 {
		pem := f.caPEM
		if f.ca != "" {
			var err error
			if pem, err = os.ReadFile(f.ca); err != nil {
				return fmt.Errorf("read --ca: %w", err)
			}
		}
		// copy into the protected state dir so the agent does not depend on
		// the original path (a GPO share, a temp dir...)
		dst := st.Dir + string(os.PathSeparator) + "ca.pem"
		if err := store.WriteFileAtomic(dst, pem, 0o600); err != nil {
			return err
		}
		cfg.CAFile = dst
	}
	if cfg.ServerURL == "" {
		return errors.New("--server is required (no server_url configured yet)")
	}
	return st.SaveConfig(cfg)
}

func cmdEnroll(args []string, rotate bool) int {
	name := "enroll"
	if rotate {
		name = "rotate"
	}
	fs, dir := newFlags(name)
	var f enrollFlags
	fs.StringVar(&f.server, "server", "", "RivetIT base URL, e.g. https://rivetit.example.com")
	fs.StringVar(&f.token, "token", "", "enrollment token (prefer --token-file or RIVETIT_ENROLL_TOKEN)")
	fs.StringVar(&f.tokenFile, "token-file", "", "file containing the enrollment token")
	fs.StringVar(&f.ca, "ca", "", "extra CA certificate (PEM) for an internal CA")
	fs.StringVar(&f.pin, "pin-spki", "", "optional hex SHA-256 of the server public key (SPKI) to pin")
	fs.StringVar(&f.department, "department", "", "informational department label")
	level := fs.String("log-level", "info", "log level")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	st, err := openStore(*dir)
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	tok, err := readToken(f.token, f.tokenFile)
	if err != nil || tok == "" {
		fmt.Fprintln(os.Stderr, "error: an enrollment token is required (--token-file, --token or RIVETIT_ENROLL_TOKEN)")
		return 2
	}
	if err := applyConfig(st, f); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	if code := enrollNow(st, tok, *level, rotate); code != 0 {
		return code
	}
	return printStatus(st)
}

// enrollKind classifies an enrollment attempt for callers that map it to an
// exit code (install keeps its historic codes; setup has its own table).
type enrollKind int

const (
	enrollOK        enrollKind = iota
	enrollRejected             // token invalid/expired/used up, or the credential is revoked: permanent
	enrollTransient            // network error, 5xx or 429: retry later
	enrollFailed               // local failure (bad config/CA, store write)
)

type enrollOutcome struct {
	Kind enrollKind
	Msg  string // safe to show: never contains the token
	Code int    // exit code the enroll/rotate/install commands have always used
}

// tryEnroll performs the exchange once. It never prints.
func tryEnroll(st *store.Store, tok string, log *slog.Logger) enrollOutcome {
	a, err := newAgent(st, log, "")
	if err != nil {
		return enrollOutcome{enrollFailed, err.Error(), 1}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()
	if _, err := a.Enroll(ctx, tok); err != nil {
		var ae *api.APIError
		if errors.As(err, &ae) {
			switch {
			case ae.Status == 429:
				return enrollOutcome{enrollTransient, fmt.Sprintf("enrollment rate-limited by the server; retry in %s", ae.RetryAfter), 75}
			case ae.AuthRejected() || ae.Status == 403:
				return enrollOutcome{enrollRejected, fmt.Sprintf("enrollment token rejected (%s): it may be invalid, expired or already used; ask an administrator for a new one", ae.Code), 3}
			case ae.Revoked():
				return enrollOutcome{enrollRejected, "enrollment refused: this credential has been revoked", 3}
			case ae.Transient():
				return enrollOutcome{enrollTransient, "enrollment failed: " + err.Error(), 1}
			default:
				return enrollOutcome{enrollRejected, "enrollment refused by the server: " + err.Error(), 1}
			}
		}
		return enrollOutcome{enrollFailed, "enrollment failed: " + err.Error(), 1}
	}
	return enrollOutcome{enrollOK, "", 0}
}

// enrollNow performs the exchange and prints a clear result.
func enrollNow(st *store.Store, tok, level string, rotate bool) int {
	if o := tryEnroll(st, tok, consoleLog(level)); o.Kind != enrollOK {
		fmt.Fprintln(os.Stderr, o.Msg)
		return o.Code
	}
	if rotate {
		fmt.Println("credential rotated; device identity unchanged")
	}
	return 0
}

func cmdStatus(args []string) int {
	fs, dir := newFlags("status")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	st, err := openStore(*dir)
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	return printStatus(st)
}

func printStatus(st *store.Store) int {
	state, err := st.LoadState()
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	cfg, _ := st.LoadConfig()
	tok, _ := st.LoadToken()
	fmt.Printf("rivetit-agent %s\n", version)
	fmt.Print(agent.Describe(state, cfg, tok != ""))
	for _, f := range st.CheckPerms() {
		fmt.Printf("WARNING: %s is readable by other users\n", f)
	}
	return 0
}
