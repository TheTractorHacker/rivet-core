// Command rivetit-agent is the RMM endpoint agent for Windows and Linux (systemd).
package main

import (
	"errors"
	"fmt"
	"os"
	"runtime"
	"time"

	"rivetit-agent/internal/embed"
)

// Set at build time via -ldflags "-X main.version=... -X main.commit=...".
var (
	version = "0.0.0-dev"
	commit  = "unknown"
)

const usage = `rivetit-agent %s

Usage: rivetit-agent <command> [flags]

Commands:
  run         run in the foreground (also the entry point of the Windows service)
  setup       install from the configuration embedded in a RivetIT-downloaded installer   [--silent] [--no-service]
  install     enroll this endpoint and install + start the service (Windows service / Linux systemd unit; needs admin/root)
  uninstall   stop and remove the service and binaries   [--purge] [--remove-meshagent (Windows)]
  enroll      exchange an enrollment token for a device credential
  rotate      re-enroll with a new token, rotating the device credential (same identity)
  status      show enrollment/health state (no network traffic)
  version     print the version
  selftest    verify the binary starts (used by the updater)

Common flags: --state-dir DIR (default: %%ProgramData%%\RivetIT\Agent on Windows, /var/lib/rivetit-agent on Linux)
Run "rivetit-agent <command> -h" for command flags.
`

// devCommands is filled by TEST/DEV-only files (build tag devtools).
var devCommands = map[string]func([]string) int{}

func main() {
	if len(os.Args) < 2 {
		// Double-click on a stamped installer = setup; otherwise show help.
		if hasEmbeddedConfig() {
			os.Exit(cmdSetup(nil))
		}
		fmt.Fprintf(os.Stderr, usage, version)
		os.Exit(2)
	}
	cmd, args := os.Args[1], os.Args[2:]
	var code int
	switch cmd {
	case "run":
		code = cmdRun(args)
	case "enroll":
		code = cmdEnroll(args, false)
	case "rotate":
		code = cmdEnroll(args, true)
	case "status":
		code = cmdStatus(args)
	case "setup":
		code = cmdSetup(args)
	case "install":
		code = cmdInstall(args)
	case "uninstall":
		code = cmdUninstall(args)
	case "version", "--version", "-v":
		fmt.Printf("rivetit-agent %s (%s) %s/%s\n", version, commit, runtime.GOOS, runtime.GOARCH)
	case "selftest":
		fmt.Printf("rivetit-agent selftest ok version=%s os=%s arch=%s\n", version, runtime.GOOS, runtime.GOARCH)
	case "-h", "--help", "help":
		fmt.Printf(usage, version)
	default:
		if fn, ok := devCommands[cmd]; ok {
			os.Exit(fn(args))
		}
		fmt.Fprintf(os.Stderr, "unknown command %q\n\n", cmd)
		fmt.Fprintf(os.Stderr, usage, version)
		code = 2
	}
	os.Exit(code)
}

// hasEmbeddedConfig reports whether this exe carries a payload footer (valid or
// not: a damaged one still routes to setup so the user gets a clear error).
func hasEmbeddedConfig() bool {
	self, err := os.Executable()
	if err != nil {
		return false
	}
	_, err = embed.Read(self, time.Now())
	return err == nil || !errors.Is(err, embed.ErrNotStamped)
}
