package main

import (
	"errors"

	"rivetit-agent/internal/winacl"
)

const attrReparsePointFile = 0x400

// checkPinnedFile decides whether a file opened (without following reparse points) for pinning is acceptable: it must not itself be a link
// (symbolic link, junction: a name-surrogate reparse tag). The handle IS the file, so no path string is compared: that comparison broke UNC, mapped
// drive and 8.3 short-name launches (review CR3), and the pin (no write/delete sharing) is what keeps the bytes from changing.
func checkPinnedFile(attrs, reparseTag uint32) error {
	if attrs&attrReparsePointFile != 0 && winacl.IsNameSurrogate(reparseTag) {
		return errors.New("the installer path is a link")
	}
	return nil
}

// fileID is a file's identity: volume serial number and 64-bit file index.
type fileID struct{ Volume, Index uint64 }

// sameFile reports whether two identities are the same file; an all-zero identity (could not be read) never matches.
func sameFile(a, b fileID) bool { return a == b && (a.Volume != 0 || a.Index != 0) }
