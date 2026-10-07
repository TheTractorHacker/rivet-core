package buffer

import "os"

func osWrite(p string, b []byte) error { return os.WriteFile(p, b, 0o600) }
