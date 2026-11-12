//go:build windows

package winacl

import (
	"strings"
	"sync"
	"unsafe"

	"golang.org/x/sys/windows"
)

var (
	netapi                  = windows.NewLazySystemDLL("netapi32.dll")
	procNetUserGetLocalGrps = netapi.NewProc("NetUserGetLocalGroups")
	procNetLocalGroupGetMem = netapi.NewProc("NetLocalGroupGetMembers")
	adminCache              sync.Map // SID string -> bool
)

const (
	lgIncludeIndirect = 1
	maxPreferred      = 0xFFFFFFFF
)

// OwnerIsAdminMember reports whether sid is a member of the local Administrators group: a user account through NetUserGetLocalGroups with
// LG_INCLUDE_INDIRECT (nested and domain groups count), any other account type through the group's direct member list. A lookup that fails (an
// unreachable domain controller, an unknown SID) is "no", so the directory is refused rather than adopted. UNVERIFIED on real Windows (built, not run).
func OwnerIsAdminMember(sid string) bool {
	if v, ok := adminCache.Load(sid); ok {
		return v.(bool)
	}
	r := ownerIsAdminMember(sid)
	adminCache.Store(sid, r)
	return r
}

func ownerIsAdminMember(sidStr string) bool {
	sid, err := windows.StringToSid(sidStr)
	if err != nil {
		return false
	}
	// the group name is localised: take it from the well-known SID
	admins, err := windows.StringToSid(SIDAdministrator)
	if err != nil {
		return false
	}
	groupName, _, _, err := admins.LookupAccount("")
	if err != nil {
		return false
	}
	account, domain, typ, err := sid.LookupAccount("")
	if err != nil {
		return false
	}
	if typ == windows.SidTypeUser {
		name := account
		if domain != "" {
			name = domain + `\` + account
		}
		n16, err := windows.UTF16PtrFromString(name)
		if err != nil {
			return false
		}
		var buf *byte
		var entries, total uint32
		r, _, _ := procNetUserGetLocalGrps.Call(0, uintptr(unsafe.Pointer(n16)), 0, lgIncludeIndirect,
			uintptr(unsafe.Pointer(&buf)), maxPreferred, uintptr(unsafe.Pointer(&entries)), uintptr(unsafe.Pointer(&total)))
		if r != 0 || buf == nil {
			return false
		}
		defer windows.NetApiBufferFree(buf)
		// LOCALGROUP_USER_INFO_0 is one *uint16 name
		for _, p := range unsafe.Slice((**uint16)(unsafe.Pointer(buf)), entries) {
			if strings.EqualFold(windows.UTF16PtrToString(p), groupName) {
				return true
			}
		}
		return false
	}
	g16, err := windows.UTF16PtrFromString(groupName)
	if err != nil {
		return false
	}
	var buf *byte
	var entries, total uint32
	r, _, _ := procNetLocalGroupGetMem.Call(0, uintptr(unsafe.Pointer(g16)), 0,
		uintptr(unsafe.Pointer(&buf)), maxPreferred, uintptr(unsafe.Pointer(&entries)), uintptr(unsafe.Pointer(&total)), 0)
	if r != 0 || buf == nil {
		return false
	}
	defer windows.NetApiBufferFree(buf)
	// LOCALGROUP_MEMBERS_INFO_0 is one *SID
	for _, m := range unsafe.Slice((**windows.SID)(unsafe.Pointer(buf)), entries) {
		if m != nil && m.String() == sidStr {
			return true
		}
	}
	return false
}
