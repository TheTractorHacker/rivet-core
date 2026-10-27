package jobs

import (
	"testing"

	"rivetit-agent/internal/api"
)

// FuzzCanonical: the canonicaliser/signature path must never panic and must
// be idempotent: canonicalising canonical output is a fixed point.
func FuzzCanonical(f *testing.F) {
	for _, s := range []string{
		`{"a":1}`, `{"b":[1,2,{"c":null}],"a":"x","signature":"s"}`, `{"a":1,"a":2}`, `[]`, `{"n":1e400,"s":"é\ud800"}`,
		`{"job_id":"j","attempt":1,"type":"powershell","script":"x","params":{},"timeout_s":5}`,
	} {
		f.Add([]byte(s))
	}
	k := newKeys(f)
	v := &Verifier{PublicKey: k.pub, DeviceID: "d"}
	f.Fuzz(func(t *testing.T, in []byte) {
		c, err := Canonical(in)
		if err == nil {
			c2, err2 := Canonical(c)
			if err2 != nil || string(c2) != string(c) {
				t.Fatalf("not a fixed point: %q -> %q (%v)", c, c2, err2)
			}
		}
		// arbitrary bytes must never verify
		_ = v.Verify(api.Job{Raw: in, Signature: "AAAA"})
		jobs, _ := api.UnmarshalJobs(in)
		for _, j := range jobs {
			if v.Verify(j) == nil {
				t.Fatal("random input verified")
			}
		}
	})
}
