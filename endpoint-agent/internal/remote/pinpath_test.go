package remote

import "testing"

func TestCheckPinnedDir(t *testing.T) {
	cases := []struct {
		attrs    uint32
		final    string
		approved string
		ok       bool
	}{
		{attrDirectory, `C:\Users\alice\drop`, `C:\Users\alice\drop`, true},
		{attrDirectory, `C:\USERS\Alice\Drop`, `c:\users\alice\drop\`, true},
		{attrDirectory | attrReparsePoint, `C:\Users\alice\drop`, `C:\Users\alice\drop`, false},
		{attrDirectory, `C:\Windows\System32`, `C:\Users\alice\drop`, false}, // junction followed: the final path is elsewhere
		{0, `C:\Users\alice\drop`, `C:\Users\alice\drop`, false},
	}
	for i, c := range cases {
		if err := checkPinnedDir(c.attrs, c.final, c.approved); (err == nil) != c.ok {
			t.Errorf("case %d: %v", i, err)
		}
	}
}
