package embed

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"strings"

	"rivetit-agent/internal/jobs"
)

// TrailerDomain is the first line of the signed message of an installer payload.
const TrailerDomain = "rivetit-installer-payload-v1"

// EmbeddedTrustKeys is the build-time trust anchor of the installer trailer: comma separated base64 Ed25519 public keys of the instance that stamps
// installers for this build, set with -ldflags "-X rivetit-agent/internal/embed.EmbeddedTrustKeys=<key>". Empty (the default, every existing build)
// leaves the trailer protected only by its SHA-256, exactly as before; set, the trailer MUST be signed by one of the keys (CORE-DS-20).
var EmbeddedTrustKeys string

func trustKeys() []ed25519.PublicKey {
	var out []ed25519.PublicKey
	for _, s := range strings.Split(EmbeddedTrustKeys, ",") {
		if b, err := base64.StdEncoding.DecodeString(strings.TrimSpace(s)); err == nil && len(b) == ed25519.PublicKeySize {
			out = append(out, ed25519.PublicKey(b))
		}
	}
	return out
}

func signedMessage(payload []byte) ([]byte, string, error) {
	var w struct {
		Signature string `json:"signature"`
	}
	if err := json.Unmarshal(payload, &w); err != nil {
		return nil, "", errors.New("payload is not a JSON object")
	}
	canon, err := jobs.Canonical(payload) // removes the "signature" member
	if err != nil {
		return nil, "", err
	}
	return append([]byte(TrailerDomain+"\n"), canon...), w.Signature, nil
}

// verifyTrailer enforces the trust anchor. A no-op when none is compiled in.
func verifyTrailer(payload []byte) error {
	keys := trustKeys()
	if len(keys) == 0 {
		return nil
	}
	msg, sigB64, err := signedMessage(payload)
	if err != nil || sigB64 == "" {
		return ErrUnsigned
	}
	sig, err := base64.StdEncoding.DecodeString(sigB64)
	if err != nil || len(sig) != ed25519.SignatureSize {
		return ErrUnsigned
	}
	for _, k := range keys {
		if ed25519.Verify(k, msg, sig) {
			return nil
		}
	}
	return ErrUnsigned
}

// SignPayload adds the signature member to a payload JSON object (used by the dev stamp tool and tests; the server does the same in PHP).
func SignPayload(payload []byte, priv ed25519.PrivateKey) ([]byte, error) {
	var m map[string]json.RawMessage
	if err := json.Unmarshal(payload, &m); err != nil {
		return nil, errors.New("payload is not a JSON object")
	}
	delete(m, "signature")
	stripped, _ := json.Marshal(m)
	msg, _, err := signedMessage(stripped)
	if err != nil {
		return nil, err
	}
	sig, _ := json.Marshal(base64.StdEncoding.EncodeToString(ed25519.Sign(priv, msg)))
	m["signature"] = sig
	out, err := json.Marshal(m)
	if err != nil {
		return nil, err
	}
	if len(out) > MaxPayload {
		return nil, invalid("payload exceeds %d bytes", MaxPayload)
	}
	return out, nil
}
