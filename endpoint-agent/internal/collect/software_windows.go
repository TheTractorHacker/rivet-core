//go:build windows

// Everything in this file is UNVERIFIED on real Windows: it cross-compiles
// and vets, but was never executed (no Windows host was available).
package collect

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"syscall"
	"time"

	"golang.org/x/sys/windows/registry"
)

// SoftwareSupported reports whether this build can collect the software inventory.
func SoftwareSupported() bool { return true }

func hideProc(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000} // CREATE_NO_WINDOW
}

const uninstallPath = `\Microsoft\Windows\CurrentVersion\Uninstall`

// uninstallValues are the registry values RegistryItem looks at.
var uninstallValues = []string{"DisplayName", "DisplayVersion", "Publisher", "InstallDate",
	"SystemComponent", "ParentKeyName", "ReleaseType", "WindowsInstaller", "UninstallString"}

// Software lists the machine-wide products of Programs and Features: the 64-bit
// Uninstall key (source "registry") and the 32-bit WOW6432Node one ("registry32").
// Both are opened with KEY_WOW64_64KEY so the 32-bit redirection can never hide
// or duplicate either of them.
//
// Per-user installs (HKCU, and the loaded HKU\<sid> hives) are DELIBERATELY
// skipped: the agent runs as SYSTEM, so HKCU is SYSTEM's own (empty) hive, and
// walking other users' profile hives would mean loading hives that are not ours
// to load. Products installed for a single user therefore do not appear.
func (p *WinPlatform) Software(ctx context.Context) (SoftwareList, error) {
	var items []SoftwareItem
	var errs []error
	for _, h := range []struct{ path, source string }{
		{`SOFTWARE` + uninstallPath, SrcRegistry},
		{`SOFTWARE\WOW6432Node` + uninstallPath, SrcRegistry32},
	} {
		got, err := readUninstall(ctx, h.path, h.source)
		if err != nil {
			errs = append(errs, err)
			continue
		}
		items = append(items, got...)
	}
	if len(errs) == 2 {
		return SoftwareList{}, errors.Join(errs...)
	}
	for _, e := range errs {
		slog.Warn("software inventory: registry hive unreadable", "err", e)
	}
	return SoftwareList{Items: items}, nil
}

func readUninstall(ctx context.Context, path, source string) ([]SoftwareItem, error) {
	root, err := registry.OpenKey(registry.LOCAL_MACHINE, path, registry.ENUMERATE_SUB_KEYS|registry.QUERY_VALUE|registry.WOW64_64KEY)
	if err != nil {
		return nil, fmt.Errorf("open %s: %w", path, err)
	}
	defer root.Close()
	names, err := root.ReadSubKeyNames(-1)
	if err != nil {
		return nil, fmt.Errorf("list %s: %w", path, err)
	}
	var items []SoftwareItem
	for _, n := range names {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		k, err := registry.OpenKey(root, n, registry.QUERY_VALUE|registry.WOW64_64KEY)
		if err != nil {
			continue
		}
		vals := map[string]string{}
		for _, vn := range uninstallValues {
			if s, ok := regValue(k, vn); ok {
				vals[vn] = s
			}
		}
		k.Close()
		if it, ok := RegistryItem(source, vals); ok {
			items = append(items, it)
		}
	}
	return items, nil
}

// regValue reads a REG_SZ / REG_EXPAND_SZ or REG_DWORD value as text.
func regValue(k registry.Key, name string) (string, bool) {
	if s, _, err := k.GetStringValue(name); err == nil {
		return s, true
	}
	if n, _, err := k.GetIntegerValue(name); err == nil {
		return strconv.FormatUint(n, 10), true
	}
	return "", false
}

// StoreApps lists Microsoft Store (appx) packages through PowerShell, framework
// packages excluded. Only asked for when software_store_apps is enabled.
func (p *WinPlatform) StoreApps(ctx context.Context) ([]SoftwareItem, error) {
	run := p.Run
	if run == nil {
		run = execRunner
	}
	return storeApps(ctx, run)
}

func storeApps(ctx context.Context, run CmdRunner) ([]SoftwareItem, error) {
	ps := filepath.Join(os.Getenv("SystemRoot"), "System32", "WindowsPowerShell", "v1.0", "powershell.exe")
	cctx, cancel := context.WithTimeout(ctx, 12*time.Second)
	defer cancel()
	out, err := run(cctx, ps, "-NoProfile", "-NonInteractive", "-Command", AppxCommand)
	if err != nil {
		return nil, fmt.Errorf("Get-AppxPackage: %w", err)
	}
	return ParseAppxJSON(out)
}
