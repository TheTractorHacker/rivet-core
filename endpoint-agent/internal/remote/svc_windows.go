//go:build windows

package remote

// Windows service backend on golang.org/x/sys/windows/svc/mgr (no command
// strings anywhere). CROSS-COMPILED AND VETTED ONLY: never executed in this
// repository's tests.

import (
	"context"
	"errors"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

const winSvcWait = 30 * time.Second

// WinServices is the Windows ServiceBackend.
type WinServices struct{}

func mapWinState(s svc.State) string {
	switch s {
	case svc.Running:
		return StateRunning
	case svc.Stopped:
		return StateStopped
	case svc.StartPending, svc.ContinuePending:
		return StateStarting
	case svc.StopPending:
		return StateStopping
	case svc.Paused, svc.PausePending:
		return StatePaused
	}
	return StateUnknown
}

func mapWinStart(t uint32) string {
	switch t {
	case mgr.StartAutomatic, windows.SERVICE_BOOT_START, windows.SERVICE_SYSTEM_START:
		return StartupAuto
	case mgr.StartManual:
		return StartupManual
	case mgr.StartDisabled:
		return StartupDisabled
	}
	return StartupUnknown
}

func openSvc(m *mgr.Mgr, name string, access uint32) (*mgr.Service, error) {
	p, err := windows.UTF16PtrFromString(name)
	if err != nil {
		return nil, err
	}
	h, err := windows.OpenService(m.Handle, p, access)
	if err != nil {
		return nil, err
	}
	return &mgr.Service{Name: name, Handle: h}, nil
}

// List implements ServiceBackend.
func (WinServices) List(ctx context.Context) ([]Service, error) {
	m, err := mgr.Connect()
	if err != nil {
		return nil, err
	}
	defer m.Disconnect()
	names, err := m.ListServices()
	if err != nil {
		return nil, err
	}
	out := make([]Service, 0, len(names))
	for _, n := range names {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		s, err := openSvc(m, n, windows.SERVICE_QUERY_CONFIG|windows.SERVICE_QUERY_STATUS)
		if err != nil {
			continue
		}
		row := Service{Name: n, State: StateUnknown, Startup: StartupUnknown}
		if cfg, err := s.Config(); err == nil {
			row.Display, row.Startup = clip(cfg.DisplayName, 160), mapWinStart(cfg.StartType)
		}
		if st, err := s.Query(); err == nil {
			row.State = mapWinState(st.State)
		}
		s.Close()
		out = append(out, row)
	}
	return out, nil
}

func (WinServices) open(m *mgr.Mgr, name string) (*mgr.Service, error) {
	s, err := openSvc(m, name, windows.SERVICE_QUERY_CONFIG|windows.SERVICE_QUERY_STATUS|windows.SERVICE_START|windows.SERVICE_STOP|windows.SERVICE_CHANGE_CONFIG)
	if err != nil {
		if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			return nil, ErrServiceNotFound
		}
		return nil, err
	}
	return s, nil
}

// Status implements ServiceBackend.
func (w WinServices) Status(ctx context.Context, name string) (string, string, error) {
	m, err := mgr.Connect()
	if err != nil {
		return "", "", err
	}
	defer m.Disconnect()
	s, err := w.open(m, name)
	if err != nil {
		return "", "", err
	}
	defer s.Close()
	st, err := s.Query()
	if err != nil {
		return "", "", err
	}
	startup := StartupUnknown
	if cfg, err := s.Config(); err == nil {
		startup = mapWinStart(cfg.StartType)
	}
	return mapWinState(st.State), startup, nil
}

func waitState(ctx context.Context, s *mgr.Service, want svc.State) error {
	deadline := time.Now().Add(winSvcWait)
	for {
		st, err := s.Query()
		if err != nil {
			return err
		}
		if st.State == want {
			return nil
		}
		if time.Now().After(deadline) {
			return errors.New("timeout waiting for the service state")
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(250 * time.Millisecond):
		}
	}
}

func stopSvc(ctx context.Context, s *mgr.Service) error {
	st, err := s.Query()
	if err != nil {
		return err
	}
	if st.State == svc.Stopped {
		return nil
	}
	if _, err := s.Control(svc.Stop); err != nil {
		return err
	}
	return waitState(ctx, s, svc.Stopped)
}

func startSvc(ctx context.Context, s *mgr.Service) error {
	st, err := s.Query()
	if err != nil {
		return err
	}
	if st.State == svc.Running {
		return nil
	}
	if err := s.Start(); err != nil {
		return err
	}
	return waitState(ctx, s, svc.Running)
}

func setStartType(s *mgr.Service, t uint32) error {
	cfg, err := s.Config()
	if err != nil {
		return err
	}
	cfg.StartType = t
	return s.UpdateConfig(cfg)
}

// Apply implements ServiceBackend.
func (w WinServices) Apply(ctx context.Context, name, action string) error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	s, err := w.open(m, name)
	if err != nil {
		return err
	}
	defer s.Close()
	switch action {
	case "start":
		return startSvc(ctx, s)
	case "stop":
		return stopSvc(ctx, s)
	case "restart":
		if err := stopSvc(ctx, s); err != nil {
			return err
		}
		return startSvc(ctx, s)
	case "enable":
		return setStartType(s, mgr.StartAutomatic)
	case "manual":
		return setStartType(s, mgr.StartManual)
	case "disable":
		return setStartType(s, mgr.StartDisabled)
	}
	return errors.New(DetailUnsupportedAct)
}

// NewServiceBackend is the production backend of this OS.
func NewServiceBackend(Runner) ServiceBackend { return WinServices{} }
