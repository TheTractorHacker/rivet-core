package jobs

import (
	"errors"
	"fmt"
	"testing"
	"time"
)

// CORE-DS-76: a signed job that names no device (or a verifier that does not know its own device) is refused, not accepted everywhere.
func TestVerifyRequiresDeviceBinding(t *testing.T) {
	k := newKeys(t)
	v := &Verifier{PublicKey: k.pub, DeviceID: "dev-1"}
	f := baseJob("j1", "echo hi")
	delete(f, "device_id")
	if err := v.Verify(k.signJob(t, f)); !errors.Is(err, ErrWrongDevice) {
		t.Fatalf("unbound job: %v", err)
	}
	f = baseJob("j2", "echo hi")
	if err := (&Verifier{PublicKey: k.pub}).Verify(k.signJob(t, f)); !errors.Is(err, ErrWrongDevice) {
		t.Fatalf("verifier without a device id: %v", err)
	}
	if err := v.Verify(k.signJob(t, baseJob("j3", "echo hi"))); err != nil {
		t.Fatalf("bound job: %v", err)
	}
}

// CORE-DS-76: dedupe records of jobs that have not expired yet survive both the count and the age prune.
func TestDedupeRecordsLiveUntilTheJobExpires(t *testing.T) {
	s := OpenStore(t.TempDir() + "/jobs.json")
	future := time.Now().Add(48 * time.Hour).UTC().Format(time.RFC3339)
	if err := s.Put(Record{JobID: "keep", State: StateSucceeded, Reported: true, ExpiresAt: future}); err != nil {
		t.Fatal(err)
	}
	for i := 0; i < maxRecords+20; i++ {
		if err := s.Put(Record{JobID: fmt.Sprintf("old-%d", i), State: StateSucceeded, Reported: true}); err != nil {
			t.Fatal(err)
		}
	}
	if _, ok := s.Get("keep"); !ok {
		t.Fatal("a record of an unexpired job was pruned")
	}
	if len(s.recs) > maxRecords+1 {
		t.Errorf("%d records kept", len(s.recs))
	}
}
