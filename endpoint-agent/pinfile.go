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
