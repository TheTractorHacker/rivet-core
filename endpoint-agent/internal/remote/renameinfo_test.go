package remote

import (
	"encoding/binary"
	"testing"
)

func TestRenameInfoBufferLayout(t *testing.T) {
	b := renameInfoBuffer(`C:\d\x.bin`, true, 8)
	if binary.LittleEndian.Uint32(b[0:]) != 1 {
		t.Error("ReplaceIfExists not set")
	}
	if got := binary.LittleEndian.Uint32(b[16:]); got != 2*10 {
		t.Errorf("FileNameLength %d", got)
	}
	if b[20] != 'C' || b[21] != 0 || b[22] != ':' {
		t.Errorf("name bytes %v", b[20:26])
	}
	if len(b)%8 != 0 {
		t.Errorf("size %d not pointer aligned", len(b))
	}
	if b32 := renameInfoBuffer(`C:\a`, false, 4); binary.LittleEndian.Uint32(b32[0:]) != 0 || binary.LittleEndian.Uint32(b32[8:]) != 8 || b32[12] != 'C' {
		t.Errorf("386 layout %v", b32)
	}
}
