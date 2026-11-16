package remote

import (
	"encoding/binary"
	"unicode/utf16"
)

// renameInfoBuffer encodes a FILE_RENAME_INFO for SetFileInformationByHandle(FileRenameInfo) with an absolute target name. The layout is
//
//	ULONG Flags/BOOLEAN ReplaceIfExists | (padding to the pointer size) | HANDLE RootDirectory | ULONG FileNameLength | WCHAR FileName[]
//
// ptrSize is 8 on amd64/arm64 (the only Windows architectures the agent ships) and 4 on 386. It is a pure function so the byte layout is unit-tested
// on every OS (CORE-DS-13); the handle that is renamed is the open temp file itself, so nothing is resolved by path between writing and renaming.
func renameInfoBuffer(target string, replace bool, ptrSize int) []byte {
	name := utf16.Encode([]rune(target))
	rootOff := ptrSize // after the 4 byte flags, padded to pointer alignment
	lenOff := rootOff + ptrSize
	nameOff := lenOff + 4
	size := nameOff + 2*len(name) + 2 // keep a terminating NUL
	if size%ptrSize != 0 {
		size += ptrSize - size%ptrSize
	}
	b := make([]byte, size)
	if replace {
		binary.LittleEndian.PutUint32(b[0:], 1)
	}
	binary.LittleEndian.PutUint32(b[lenOff:], uint32(2*len(name)))
	for i, c := range name {
		binary.LittleEndian.PutUint16(b[nameOff+2*i:], c)
	}
	return b
}
