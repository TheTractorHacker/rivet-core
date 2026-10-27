package embed

import (
	"errors"
	"fmt"
	"io"
	"os"
)

// WriteUnstamped copies only the original exe bytes of src (everything before
// the payload) to dst, mode 0755, fsynced, and verifies the result carries no
// footer. The payload and its token are never written. dst is created/truncated;
// callers pass a temporary name and rename it into place.
func WriteUnstamped(src, dst string) (written int64, err error) {
	in, err := os.Open(src)
	if err != nil {
		return 0, err
	}
	defer in.Close()
	fi, err := in.Stat()
	if err != nil {
		return 0, err
	}
	n, err := UnstampedSize(in, fi.Size())
	if err != nil {
		return 0, err
	}
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o755)
	if err != nil {
		return 0, err
	}
	ok := false
	defer func() {
		out.Close()
		if !ok {
			os.Remove(dst)
		}
	}()
	if written, err = io.Copy(out, io.NewSectionReader(in, 0, n)); err != nil {
		return 0, err
	}
	if written != n {
		return 0, fmt.Errorf("short copy: %d of %d bytes", written, n)
	}
	if err := out.Sync(); err != nil {
		return 0, err
	}
	if err := out.Close(); err != nil {
		return 0, err
	}
	// Verify: the staged file must read as not stamped, and have the exact size.
	chk, err := os.Open(dst)
	if err != nil {
		return 0, err
	}
	defer chk.Close()
	st, err := chk.Stat()
	if err != nil {
		return 0, err
	}
	if st.Size() != n {
		return 0, fmt.Errorf("staged copy has size %d, want %d", st.Size(), n)
	}
	if _, _, _, lerr := locate(chk, st.Size()); !errors.Is(lerr, ErrNotStamped) {
		return 0, errors.New("staged copy still carries an embedded payload; refusing to install it")
	}
	ok = true
	return written, nil
}
