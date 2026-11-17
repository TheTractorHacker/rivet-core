package main

import "testing"

func TestCheckPinnedFile(t *testing.T) {
	cases := []struct {
		attrs, tag uint32
		ok         bool
	}{
		{0x20, 0, true},            // ordinary file (also on UNC, mapped drive or an 8.3 path: nothing path-based is compared)
		{0x420, 0xA000000C, false}, // symlink
		{0x420, 0xA0000003, false}, // junction / mount point
		{0x420, 0x9000701A, true},  // Cloud Files placeholder
		{0x20, 0xA000000C, true},   // tag without the reparse attribute is ignored
	}
	for i, c := range cases {
		if err := checkPinnedFile(c.attrs, c.tag); (err == nil) != c.ok {
			t.Errorf("case %d: %v", i, err)
		}
	}
}

func TestSameFile(t *testing.T) {
	if !sameFile(fileID{1, 2}, fileID{1, 2}) || sameFile(fileID{1, 2}, fileID{1, 3}) || sameFile(fileID{}, fileID{}) {
		t.Fatal("identity comparison")
	}
}
