//go:build windows

package store

import (
	"encoding/base64"
	"errors"
	"os"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Directory DACL: protected (no inherited ACEs), SYSTEM and Administrators
// full control, nobody else. UNVERIFIED on real Windows (built, not run).
const dirSDDL = "D:PAI(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)"

func ensureDir(dir string) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	sd, err := windows.SecurityDescriptorFromString(dirSDDL)
	if err != nil {
		return err
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		return err
	}
	return windows.SetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION,
		nil, nil, dacl, nil)
}

func syncDir(string)                       {}
func tightenPerms(string)                  {}
func looseFiles(string, []string) []string { return nil }

var dpapiEntropy = []byte("RivetIT-Agent-v1")

func blob(b []byte) *windows.DataBlob {
	if len(b) == 0 {
		return &windows.DataBlob{}
	}
	return &windows.DataBlob{Size: uint32(len(b)), Data: &b[0]}
}

// CRYPTPROTECT_LOCAL_MACHINE lets the service (SYSTEM) read a token written
// by the installing administrator; the directory DACL is the primary control.
const cryptProtectLocalMachine = 0x4

func protect(b []byte) (string, string, error) {
	var out windows.DataBlob
	if err := windows.CryptProtectData(blob(b), nil, blob(dpapiEntropy), 0, nil, cryptProtectLocalMachine, &out); err != nil {
		return "", "", err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	enc := unsafe.Slice(out.Data, out.Size)
	return schemeDPAPI, base64.StdEncoding.EncodeToString(enc), nil
}

func unprotect(scheme, s string) ([]byte, error) {
	if scheme != schemeDPAPI {
		return nil, errors.New("unsupported credential scheme: " + scheme)
	}
	raw, err := base64.StdEncoding.DecodeString(s)
	if err != nil {
		return nil, err
	}
	var out windows.DataBlob
	if err := windows.CryptUnprotectData(blob(raw), nil, blob(dpapiEntropy), 0, nil, cryptProtectLocalMachine, &out); err != nil {
		return nil, err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	res := make([]byte, out.Size)
	copy(res, unsafe.Slice(out.Data, out.Size))
	return res, nil
}
