//go:build windows

// Package svc wraps the Windows service control manager.
// UNVERIFIED on real Windows: this file cross-compiles and vets but has never
// been executed.
package svc

import (
	"context"
	"errors"
	"fmt"
	"time"

	"golang.org/x/sys/windows"
	wsvc "golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

const ServiceName = "RivetITAgent"

func IsWindowsService() bool {
	ok, err := wsvc.IsWindowsService()
	return err == nil && ok
}

type handler struct{ run func(ctx context.Context) int }

func (h *handler) Execute(_ []string, r <-chan wsvc.ChangeRequest, s chan<- wsvc.Status) (bool, uint32) {
	s <- wsvc.Status{State: wsvc.StartPending}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan int, 1)
	go func() { done <- h.run(ctx) }()
	s <- wsvc.Status{State: wsvc.Running, Accepts: wsvc.AcceptStop | wsvc.AcceptShutdown}
	for {
		select {
		case c := <-r:
			switch c.Cmd {
			case wsvc.Interrogate:
				s <- c.CurrentStatus
			case wsvc.Stop, wsvc.Shutdown:
				s <- wsvc.Status{State: wsvc.StopPending, WaitHint: 30000}
				cancel()
				select {
				case <-done:
				case <-time.After(25 * time.Second):
				}
				return false, 0
			}
		case code := <-done:
			// A non-zero service-specific exit code is a "failure" to the SCM,
			// which triggers the restart recovery action (used for update
			// restarts and unexpected exits; the install sets
			// FailureActionsOnNonCrashFailures).
			if code != 0 {
				return true, uint32(code)
			}
			return false, 0
		}
	}
}

// RunAsService runs under the SCM; run returns the process exit code.
func RunAsService(run func(ctx context.Context) int) error {
	return wsvc.Run(ServiceName, &handler{run: run})
}

func ServiceExists() (bool, error) {
	m, err := mgr.Connect()
	if err != nil {
		return false, err
	}
	defer m.Disconnect()
	s, err := m.OpenService(ServiceName)
	if err != nil {
		if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			return false, nil
		}
		return false, err
	}
	s.Close()
	return true, nil
}

// InstallService creates (or updates) the service and its recovery actions.
// Idempotent. exePath is quoted by the SCM wrapper; args are passed verbatim.
func InstallService(exePath string, args []string) error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connect to SCM (run as Administrator): %w", err)
	}
	defer m.Disconnect()
	cfg := mgr.Config{
		DisplayName:      "RivetIT Agent",
		Description:      "RivetIT endpoint agent: inventory, health monitoring and controlled maintenance jobs.",
		StartType:        mgr.StartAutomatic,
		DelayedAutoStart: true,
		ErrorControl:     mgr.ErrorNormal,
	}
	s, err := m.OpenService(ServiceName)
	if err == nil {
		defer s.Close()
		old, cerr := s.Config()
		if cerr != nil {
			return cerr
		}
		old.BinaryPathName = quoteCmd(exePath, args)
		old.DisplayName, old.Description, old.StartType, old.DelayedAutoStart = cfg.DisplayName, cfg.Description, cfg.StartType, cfg.DelayedAutoStart
		if err := s.UpdateConfig(old); err != nil {
			return fmt.Errorf("update service: %w", err)
		}
	} else {
		s, err = m.CreateService(ServiceName, exePath, cfg, args...)
		if err != nil {
			return fmt.Errorf("create service: %w", err)
		}
		defer s.Close()
	}
	ra := []mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 60 * time.Second},
	}
	if err := s.SetRecoveryActions(ra, 86400); err != nil {
		return fmt.Errorf("set recovery actions: %w", err)
	}
	if err := s.SetRecoveryActionsOnNonCrashFailures(true); err != nil {
		return fmt.Errorf("set non-crash failure actions: %w", err)
	}
	return nil
}

func quoteCmd(exe string, args []string) string {
	s := windows.EscapeArg(exe)
	for _, a := range args {
		s += " " + windows.EscapeArg(a)
	}
	return s
}

func StartService() error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	s, err := m.OpenService(ServiceName)
	if err != nil {
		return err
	}
	defer s.Close()
	st, err := s.Query()
	if err != nil {
		return err
	}
	if st.State == wsvc.Running || st.State == wsvc.StartPending {
		return nil
	}
	return s.Start()
}

// StopService stops the service and waits up to 30s; absent => nil.
func StopService() error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	s, err := m.OpenService(ServiceName)
	if err != nil {
		if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			return nil
		}
		return err
	}
	defer s.Close()
	st, err := s.Query()
	if err != nil {
		return err
	}
	if st.State == wsvc.Stopped {
		return nil
	}
	if st, err = s.Control(wsvc.Stop); err != nil {
		return err
	}
	deadline := time.Now().Add(30 * time.Second)
	for st.State != wsvc.Stopped {
		if time.Now().After(deadline) {
			return errors.New("timed out waiting for the service to stop")
		}
		time.Sleep(300 * time.Millisecond)
		if st, err = s.Query(); err != nil {
			return err
		}
	}
	return nil
}

// RemoveService stops and deletes the service. Absent => nil.
func RemoveService() error {
	if err := StopService(); err != nil {
		return err
	}
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	s, err := m.OpenService(ServiceName)
	if err != nil {
		if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			return nil
		}
		return err
	}
	defer s.Close()
	return s.Delete()
}
