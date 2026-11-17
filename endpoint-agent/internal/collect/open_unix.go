//go:build !windows

package collect

import "syscall"

// openNonblock keeps open(2) from blocking on a FIFO or a device that waits for a peer.
const openNonblock = syscall.O_NONBLOCK
