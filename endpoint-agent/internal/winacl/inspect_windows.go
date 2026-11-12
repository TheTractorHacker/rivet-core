//go:build windows

package winacl

import (
	"errors"
	"fmt"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Inspect reads the owner, DACL and reparse-point state of path. A path that does not exist is reported through Info.NotFound; any other failure
// to read is an error, which callers treat as "not trusted". UNVERIFIED on real Windows (built and vetted, not run).
func Inspect(path string, wantDir bool) (Info, error) {
	i := Info{Path: path, WantDir: wantDir}
	p16, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return i, err
	}
	attrs, err := windows.GetFileAttributes(p16)
	if err != nil {
		if errors.Is(err, windows.ERROR_FILE_NOT_FOUND) || errors.Is(err, windows.ERROR_PATH_NOT_FOUND) {
			i.NotFound = true
			return i, nil
		}
		return i, fmt.Errorf("read attributes of %s: %w", path, err)
	}
	i.Reparse = attrs&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0
	i.IsDir = attrs&windows.FILE_ATTRIBUTE_DIRECTORY != 0
	sd, err := windows.GetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.OWNER_SECURITY_INFORMATION|windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return i, fmt.Errorf("read security descriptor of %s: %w", path, err)
	}
	if owner, _, err := sd.Owner(); err == nil && owner != nil {
		i.Owner = owner.String()
		if !Trusted(i.Owner) {
			i.OwnerIsAdmin = OwnerIsAdminMember(i.Owner)
		}
	}
	dacl, _, err := sd.DACL()
	if err != nil && !errors.Is(err, windows.ERROR_OBJECT_NOT_FOUND) {
		return i, fmt.Errorf("read DACL of %s: %w", path, err)
	}
	if err != nil || dacl == nil {
		return i, nil // HasDACL stays false: a NULL DACL
	}
	i.HasDACL = true
	for n := uint32(0); n < uint32(dacl.AceCount); n++ {
		var ace *windows.ACCESS_ALLOWED_ACE
		if err := windows.GetAce(dacl, n, &ace); err != nil {
			return i, fmt.Errorf("read ACE %d of %s: %w", n, path, err)
		}
		inheritOnly := ace.Header.AceFlags&windows.INHERIT_ONLY_ACE != 0
		switch ace.Header.AceType {
		case windows.ACCESS_ALLOWED_ACE_TYPE, 9: // 9: ACCESS_ALLOWED_CALLBACK_ACE_TYPE, same leading layout
			sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
			i.ACEs = append(i.ACEs, ACE{SID: sid.String(), Allow: true, Mask: uint32(ace.Mask), InheritOnly: inheritOnly})
		case windows.ACCESS_DENIED_ACE_TYPE, 10: // deny entries only ever restrict
			i.ACEs = append(i.ACEs, ACE{Allow: false})
		default:
			// An ACE type this code does not parse (object ACEs, ...): cannot be shown harmless, so it counts as write access for an unknown principal.
			i.ACEs = append(i.ACEs, ACE{SID: fmt.Sprintf("unparsed-ace-type-%d", ace.Header.AceType), Allow: true, Mask: WriteMask, InheritOnly: inheritOnly})
		}
	}
	return i, nil
}
