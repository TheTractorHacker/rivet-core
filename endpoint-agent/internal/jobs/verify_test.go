package jobs

import (
	"strings"
	"testing"
	"time"

	"errors"
)

func TestCanonicalRules(t *testing.T) {
	in := `{"b":1.50,"a":{"z":[3,2,{"y":null,"x":true}],"c":"<&>/é"},"signature":"zzz","n":1e3}`
	got, err := Canonical([]byte(in))
	if err != nil {
		t.Fatal(err)
	}
	want := `{"a":{"c":"<&>/é","z":[3,2,{"x":true,"y":null}]},"b":1.50,"n":1e3}`
	if string(got) != want {
		t.Fatalf("got  %s\nwant %s", got, want)
	}
}

func TestCanonicalRejects(t *testing.T) {
	for _, in := range []string{`{"a":1,"a":2}`, `[1]`, `{"a":1} x`, `{"a":`, ``, `"s"`} {
		if _, err := Canonical([]byte(in)); err == nil {
			t.Errorf("expected error for %q", in)
		}
	}
	deep := strings.Repeat(`{"a":`, 100) + `1` + strings.Repeat(`}`, 100)
	if _, err := Canonical([]byte(deep)); err == nil {
		t.Error("deep nesting accepted")
	}
}

func TestVerify(t *testing.T) {
	k, other := newKeys(t), newKeys(t)
	v := &Verifier{PublicKey: k.pub, DeviceID: "dev-1"}

	good := k.signJob(t, baseJob("j1", "echo hi"))
	if err := v.Verify(good); err != nil {
		t.Fatalf("valid job rejected: %v", err)
	}

	tampered := good
	tampered.Raw = []byte(strings.Replace(string(good.Raw), "echo hi", "echo pwned", 1))
	if err := v.Verify(tampered); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("tampered: %v", err)
	}

	if err := (&Verifier{PublicKey: other.pub, DeviceID: "dev-1"}).Verify(good); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("wrong key: %v", err)
	}
	if err := (&Verifier{DeviceID: "dev-1"}).Verify(good); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("no key: %v", err)
	}

	f := baseJob("j2", "echo hi")
	f["expires_at"] = time.Now().Add(-time.Minute).UTC().Format(time.RFC3339)
	if err := v.Verify(k.signJob(t, f)); !errors.Is(err, ErrExpired) {
		t.Fatalf("expired: %v", err)
	}
	// skew-corrected clock makes an otherwise valid job expired
	vs := &Verifier{PublicKey: k.pub, DeviceID: "dev-1", Now: func() time.Time { return time.Now().Add(2 * time.Hour) }}
	if err := vs.Verify(good); !errors.Is(err, ErrExpired) {
		t.Fatalf("skewed clock: %v", err)
	}

	f = baseJob("j3", "echo hi")
	f["device_id"] = "dev-OTHER"
	if err := v.Verify(k.signJob(t, f)); !errors.Is(err, ErrWrongDevice) {
		t.Fatalf("wrong device: %v", err)
	}

	f = baseJob("j4", "echo hi")
	f["type"] = "format_disk"
	if err := v.Verify(k.signJob(t, f)); !errors.Is(err, ErrInvalidJob) {
		t.Fatalf("unknown type: %v", err)
	}

	f = baseJob("j5", "echo hi")
	delete(f, "expires_at")
	if err := v.Verify(k.signJob(t, f)); !errors.Is(err, ErrInvalidJob) {
		t.Fatalf("missing expiry: %v", err)
	}

	unsigned := good
	unsigned.Signature = ""
	if err := v.Verify(unsigned); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("unsigned: %v", err)
	}
	garbage := good
	garbage.Signature = "!!!not base64!!!"
	if err := v.Verify(garbage); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("garbage sig: %v", err)
	}
}

func TestLimitsClamp(t *testing.T) {
	k := newKeys(t)
	f := baseJob("j", "x")
	f["timeout_s"], f["max_output_bytes"] = 999999, 99999999
	d, m := Limits(k.signJob(t, f))
	if d != time.Duration(MaxTimeoutS)*time.Second || m != HardMaxOutputBytes {
		t.Fatalf("%v %v", d, m)
	}
}

// device_id arrives as an integer inside the signed canonical JSON; the
// signature must verify over the bytes as written and scoping must compare
// numerically-written ids with the stored (text) device id.
func TestVerifyIntegerDeviceID(t *testing.T) {
	k := newKeys(t)
	v := &Verifier{PublicKey: k.pub, DeviceID: "7"}
	f := baseJob("j1", "echo hi")
	f["device_id"] = 7
	job := k.signJob(t, f)
	if !strings.Contains(string(job.Raw), `"device_id":7,`) {
		t.Fatalf("integer lost: %s", job.Raw)
	}
	if err := v.Verify(job); err != nil {
		t.Fatalf("integer device_id job rejected: %v", err)
	}
	canon, _ := Canonical(job.Raw)
	if !strings.Contains(string(canon), `"device_id":7,`) {
		t.Fatalf("canonical changed the integer: %s", canon)
	}
	f = baseJob("j2", "echo hi")
	f["device_id"] = 8
	if err := v.Verify(k.signJob(t, f)); !errors.Is(err, ErrWrongDevice) {
		t.Fatalf("wrong integer device: %v", err)
	}
	// a server that signed the string form still works, and the forms are NOT
	// interchangeable under the signature (7 vs "7" canonicalise differently)
	f = baseJob("j3", "echo hi")
	f["device_id"] = "7"
	sj := k.signJob(t, f)
	if err := v.Verify(sj); err != nil {
		t.Fatal(err)
	}
	sj.Raw = []byte(strings.Replace(string(sj.Raw), `"device_id":"7"`, `"device_id":7`, 1))
	if err := v.Verify(sj); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("form swap must break the signature: %v", err)
	}
}
