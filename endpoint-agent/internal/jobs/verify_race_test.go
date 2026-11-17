package jobs

import (
	"crypto/ed25519"
	"sync"
	"testing"
	"time"
)

// CORE-DS-52 (run with -race): syncVerifier rewrites the key and device id while workers verify jobs.
func TestVerifierIsSafeAgainstConcurrentKeyChanges(t *testing.T) {
	k := newKeys(t)
	v := &Verifier{PublicKey: k.pub, DeviceID: "dev-1"}
	job := k.signJob(t, baseJob("j1", "true"))
	var wg sync.WaitGroup
	stop := make(chan struct{})
	wg.Add(2)
	go func() {
		defer wg.Done()
		for {
			select {
			case <-stop:
				return
			default:
				v.SetKey(k.pub)
				v.SetDevice("dev-1")
				v.SetKey(nil)
				v.SetKey(ed25519.PublicKey(k.pub))
			}
		}
	}()
	go func() {
		defer wg.Done()
		for i := 0; i < 2000; i++ {
			_ = v.Verify(job)
			_ = v.VerifyObject([]byte(`{}`), "")
		}
	}()
	time.Sleep(50 * time.Millisecond)
	close(stop)
	wg.Wait()
}
