package remote

import (
	"errors"
	"testing"

	"rivetit-agent/internal/winacl"
)

func TestCheckPinnedDir(t *testing.T) {
	const (
		tagSymlink    = 0xA000000C // IO_REPARSE_TAG_SYMLINK
		tagMountPoint = 0xA0000003 // IO_REPARSE_TAG_MOUNT_POINT (junction)
		tagCloud      = 0x9000701A // IO_REPARSE_TAG_CLOUD_7: OneDrive / Cloud Files placeholder directory
	)
	cases := []struct {
		attrs    uint32
		tag      uint32
		final    string
		approved string
		ok       bool
	}{
		{attrDirectory, 0, `C:\Users\alice\drop`, `C:\Users\alice\drop`, true},
		{attrDirectory, 0, `C:\USERS\Alice\Drop`, `c:\users\alice\drop\`, true},
		{attrDirectory | attrReparsePoint, tagSymlink, `C:\Users\alice\drop`, `C:\Users\alice\drop`, false},
		{attrDirectory | attrReparsePoint, tagMountPoint, `C:\Users\alice\drop`, `C:\Users\alice\drop`, false},
		{attrDirectory | attrReparsePoint, tagCloud, `C:\Users\alice\OneDrive\Desktop`, `C:\Users\alice\OneDrive\Desktop`, true}, // review CR2
		{attrDirectory, 0, `C:\Windows\System32`, `C:\Users\alice\drop`, false},                                                  // junction followed: the final path is elsewhere
		{0, 0, `C:\Users\alice\drop`, `C:\Users\alice\drop`, false},
	}
	for i, c := range cases {
		if err := checkPinnedDir(c.attrs, c.tag, c.final, c.approved); (err == nil) != c.ok {
			t.Errorf("case %d: %v", i, err)
		}
	}
}

func TestUnreadableReparseTagFailsClosed(t *testing.T) {
	if !winacl.IsNameSurrogate(tagOrSurrogate(0, errors.New("query failed"))) {
		t.Fatal("a failed tag query was treated as no link")
	}
	if winacl.IsNameSurrogate(tagOrSurrogate(0x9000701A, nil)) {
		t.Fatal("a Cloud Files tag was treated as a link")
	}
}

func TestSameFileIdentity(t *testing.T) {
	if !sameFileIdentity(1, 2, 1, 2) || sameFileIdentity(1, 2, 1, 3) || sameFileIdentity(1, 2, 2, 2) || sameFileIdentity(0, 0, 0, 0) {
		t.Fatal("identity comparison")
	}
}
