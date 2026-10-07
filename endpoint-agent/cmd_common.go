package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"rivetit-agent/internal/agent"
	"rivetit-agent/internal/collect"
	"rivetit-agent/internal/logx"
	"rivetit-agent/internal/store"
	"rivetit-agent/internal/svc"
)

func newFlags(name string) (*flag.FlagSet, *string) {
	fs := flag.NewFlagSet(name, flag.ContinueOnError)
	fs.SetOutput(os.Stderr)
	dir := fs.String("state-dir", defaultStateDir(), "directory for configuration and state")
	return fs, dir
}

func openStore(dir string) (*store.Store, error) {
	if dir == "" {
		return nil, errors.New("--state-dir is required on this OS (or set RIVETIT_AGENT_STATE_DIR)")
	}
	return store.Open(dir)
}

// readToken resolves the enrollment token from --token, --token-file or
// RIVETIT_ENROLL_TOKEN (preferred: the flag is visible in process listings).
func readToken(flagTok, file string) (string, error) {
	if file != "" {
		b, err := os.ReadFile(file)
		if err != nil {
			return "", err
		}
		return strings.TrimSpace(string(b)), nil
	}
	if flagTok != "" {
		return flagTok, nil
	}
	return strings.TrimSpace(os.Getenv("RIVETIT_ENROLL_TOKEN")), nil
}

func newAgent(st *store.Store, log *slog.Logger, exe string, tune ...time.Duration) (*agent.Agent, error) {
	var minIv, pendIv time.Duration
	if len(tune) == 2 {
		minIv, pendIv = tune[0], tune[1]
	}
	return agent.New(agent.Options{Store: st, Version: version, Exe: exe, MinInterval: minIv, PendingInterval: pendIv,
		Platform: collect.NewPlatform(), Rebooter: agent.NewRebooter(log), Log: log})
}

func consoleLog(level string) *slog.Logger { return logx.New(os.Stderr, level) }

func cmdRun(args []string) int {
	fs, dir := newFlags("run")
	level := fs.String("log-level", "info", "debug|info|warn|error")
	noUpdate := fs.Bool("no-update", false, "disable self-update")
	minIv := fs.Int("min-interval", 0, "TEST ONLY: lower clamp for intervals, seconds (default 10)")
	pendIv := fs.Int("pending-interval", 0, "TEST ONLY: check-in/sample interval while pending approval, seconds (default 300)")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	st, err := openStore(*dir)
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	inService := svc.IsWindowsService()
	var w io.Writer = os.Stderr
	if inService {
		lw, err := logx.NewRotating(st.LogPath(), 5<<20)
		if err != nil {
			fmt.Fprintln(os.Stderr, "error: log file:", err)
			return 1
		}
		defer lw.Close()
		w = lw
	}
	log := logx.New(w, *level)
	exe := ""
	if !*noUpdate {
		if p, err := os.Executable(); err == nil {
			exe = p
		}
	}
	a, err := newAgent(st, log, exe, time.Duration(*minIv)*time.Second, time.Duration(*pendIv)*time.Second)
	if err != nil {
		log.Error("startup failed", "err", err)
		return 1
	}
	runAgent := func(ctx context.Context) int {
		err := a.Run(ctx)
		switch {
		case err == nil:
			return 0
		case errors.Is(err, agent.ErrRestartRequested):
			return agent.ExitRestart
		}
		log.Error("agent stopped", "err", err)
		return 1
	}
	if inService {
		code := 0
		if err := svc.RunAsService(func(ctx context.Context) int { code = runAgent(ctx); return code }); err != nil {
			log.Error("service failure", "err", err)
			return 1
		}
		return code
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	return runAgent(ctx)
}
