//go:build windows

package change

import (
	"runtime"
	"strings"
	"sync"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

const (
	maxNotifyRoots  = 16
	notifyDebounce  = 2 * time.Second
	notifyFilterAll = windows.REG_NOTIFY_CHANGE_NAME | windows.REG_NOTIFY_CHANGE_LAST_SET
)

// StartNotifier listens for registry changes under the given keys and calls wake
// (non-blocking, rate-limited by the caller) when one happens. The poll stays the
// source of truth: a notification only makes it run sooner, so a missed or failed
// notification costs a delay, never a change. One goroutine per key, at most 16;
// a key that cannot be opened is skipped. CROSS-COMPILED AND VETTED ONLY.
func StartNotifier(roots []NotifyRoot, wake func()) (stop func()) {
	stopEvt, err := windows.CreateEvent(nil, 1, 0, nil)
	if err != nil {
		return func() {}
	}
	var wg sync.WaitGroup
	for i, r := range roots {
		if i >= maxNotifyRoots {
			break
		}
		wg.Add(1)
		go func(r NotifyRoot) {
			defer wg.Done()
			watchKey(r, stopEvt, wake)
		}(r)
	}
	var once sync.Once
	return func() {
		once.Do(func() {
			_ = windows.SetEvent(stopEvt)
			wg.Wait()
			_ = windows.CloseHandle(stopEvt)
		})
	}
}

func watchKey(r NotifyRoot, stopEvt windows.Handle, wake func()) {
	root, rest, ok := splitRoot(r.Key)
	if !ok || !RegistryKeyAllowed(r.Key) || strings.Contains(rest, "*") {
		return
	}
	k, err := registry.OpenKey(root, rest, registry.NOTIFY|registry.QUERY_VALUE|registry.WOW64_64KEY)
	if err != nil {
		return
	}
	defer k.Close()
	evt, err := windows.CreateEvent(nil, 1, 0, nil)
	if err != nil {
		return
	}
	defer windows.CloseHandle(evt)
	// RegNotifyChangeKeyValue with an event signals as long as the thread that called it lives: keep this goroutine on one OS thread
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	for {
		if err := windows.RegNotifyChangeKeyValue(windows.Handle(k), r.Subtree, notifyFilterAll, evt, true); err != nil {
			return
		}
		ev, err := windows.WaitForMultipleObjects([]windows.Handle{evt, stopEvt}, false, windows.INFINITE)
		if err != nil || ev != windows.WAIT_OBJECT_0 {
			return // the stop event, or a failed wait
		}
		wake()
		_ = windows.ResetEvent(evt)
		// debounce: a burst of writes is one wake-up; also the guard against a spin if a key keeps signalling
		if w, err := windows.WaitForSingleObject(stopEvt, uint32(notifyDebounce/time.Millisecond)); err != nil || w == windows.WAIT_OBJECT_0 {
			return
		}
	}
}
