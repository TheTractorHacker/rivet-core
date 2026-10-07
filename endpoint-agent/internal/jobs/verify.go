package jobs

import (
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"fmt"
	"strings"
	"time"

	"rivetit-agent/internal/api"
)

// Verification errors. Callers must not execute a job unless Verify is nil.
var (
	ErrBadSignature = errors.New("job signature invalid")
	ErrExpired      = errors.New("job expired")
	ErrWrongDevice  = errors.New("job is scoped to a different device")
	ErrInvalidJob   = errors.New("job malformed")
)

// Hard limits applied after the signature has been verified.
const (
	MinTimeoutS           = 1
	MaxTimeoutS           = 3600
	DefaultTimeoutS       = 300
	DefaultMaxOutputBytes = 64 << 10
	HardMaxOutputBytes    = 1 << 20
	MaxScriptBytes        = 256 << 10
	MaxParamsBytes        = 64 << 10
	futureSkew            = 5 * time.Minute
)

// Verifier checks a job against the pinned signing key BEFORE anything runs.
type Verifier struct {
	PublicKey ed25519.PublicKey
	DeviceID  string
	Now       func() time.Time // skew-corrected clock
}

func decodeB64(s string) ([]byte, error) {
	s = strings.TrimSpace(s)
	for _, enc := range []*base64.Encoding{base64.StdEncoding, base64.RawStdEncoding, base64.URLEncoding, base64.RawURLEncoding} {
		if b, err := enc.DecodeString(s); err == nil {
			return b, nil
		}
	}
	return nil, errors.New("not base64")
}

// VerifyObject checks an ed25519 signature over the canonical form of raw
// (minus its "signature" member). It applies no job policy, so it also
// serves signed check definitions.
func (v *Verifier) VerifyObject(raw []byte, sigB64 string) error {
	if len(v.PublicKey) != ed25519.PublicKeySize {
		return fmt.Errorf("%w: no pinned signing key", ErrBadSignature)
	}
	if len(raw) == 0 || sigB64 == "" {
		return fmt.Errorf("%w: missing signature", ErrBadSignature)
	}
	sig, err := decodeB64(sigB64)
	if err != nil || len(sig) != ed25519.SignatureSize {
		return fmt.Errorf("%w: undecodable signature", ErrBadSignature)
	}
	canon, err := Canonical(raw)
	if err != nil {
		return fmt.Errorf("%w: %v", ErrInvalidJob, err)
	}
	if !ed25519.Verify(v.PublicKey, canon, sig) {
		return ErrBadSignature
	}
	return nil
}

// Verify order: key sanity, signature over canonical JSON, then expiry,
// device scope, type and bounds. All must pass.
func (v *Verifier) Verify(j api.Job) error {
	if len(v.PublicKey) != ed25519.PublicKeySize {
		return fmt.Errorf("%w: no pinned signing key", ErrBadSignature)
	}
	if len(j.Raw) == 0 || j.Signature == "" {
		return fmt.Errorf("%w: missing signature", ErrBadSignature)
	}
	sig, err := decodeB64(j.Signature)
	if err != nil || len(sig) != ed25519.SignatureSize {
		return fmt.Errorf("%w: undecodable signature", ErrBadSignature)
	}
	canon, err := Canonical(j.Raw)
	if err != nil {
		return fmt.Errorf("%w: %v", ErrInvalidJob, err)
	}
	if !ed25519.Verify(v.PublicKey, canon, sig) {
		return ErrBadSignature
	}
	// ---- signature OK; everything below is policy on authentic data ----
	if j.JobID == "" || j.Attempt < 0 {
		return fmt.Errorf("%w: job_id/attempt", ErrInvalidJob)
	}
	now := time.Now()
	if v.Now != nil {
		now = v.Now()
	}
	if !j.ExpiresAt.OK {
		return fmt.Errorf("%w: missing expires_at", ErrInvalidJob)
	}
	if !now.Before(j.ExpiresAt.T) {
		return ErrExpired
	}
	if j.IssuedAt.OK && j.IssuedAt.T.After(now.Add(futureSkew)) {
		return fmt.Errorf("%w: issued_at in the future", ErrInvalidJob)
	}
	if j.DeviceID != "" && v.DeviceID != "" && string(j.DeviceID) != v.DeviceID {
		return ErrWrongDevice
	}
	switch j.Type {
	case TypePowerShell, TypeShell:
		if j.Script == "" || len(j.Script) > MaxScriptBytes {
			return fmt.Errorf("%w: script size", ErrInvalidJob)
		}
	case "reboot", "collect":
	default:
		return fmt.Errorf("%w: unknown type %q", ErrInvalidJob, j.Type)
	}
	if len(j.Params) > MaxParamsBytes {
		return fmt.Errorf("%w: params too large", ErrInvalidJob)
	}
	return nil
}

// Limits returns clamped timeout and output cap for a verified job.
func Limits(j api.Job) (time.Duration, int) {
	t := j.TimeoutS
	if t == 0 {
		t = DefaultTimeoutS
	}
	if t < MinTimeoutS {
		t = MinTimeoutS
	}
	if t > MaxTimeoutS {
		t = MaxTimeoutS
	}
	m := j.MaxOutputBytes
	if m <= 0 {
		m = DefaultMaxOutputBytes
	}
	if m > HardMaxOutputBytes {
		m = HardMaxOutputBytes
	}
	return time.Duration(t) * time.Second, m
}
