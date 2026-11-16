package update

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"strings"

	"rivetit-agent/internal/api"
)

// The release signature (CORE-DS-26). The instance signature of an update only covers the sha256 text, so anyone who can write the database or holds
// the instance key can offer any previously signed binary under any version, os or arch label. A release key is held OFFLINE by the release owner and
// signs {kind, version, os, arch, sha256, min_version} under its own domain; its public half is compiled into the agent (and can be rolled over by a
// message signed with the old key). An agent that knows at least one release key refuses every update that lacks a valid release signature.
const (
	ManifestDomain = "rivetit-update-manifest-v1"
	RolloverDomain = "rivetit-release-rollover-v1"
	ManifestKind   = "agent_update"
	RolloverKind   = "release_key_rollover"
)

// EmbeddedReleaseKeys is the build-time trust anchor: comma separated base64 ed25519 public keys, set with
// -ldflags "-X rivetit-agent/internal/update.EmbeddedReleaseKeys=<key>[,<key>]". Empty (the default) leaves release-signature enforcement off, so an
// existing build keeps working with a server that does not send release signatures yet.
var EmbeddedReleaseKeys string

// ManifestMessage is the exact byte string the release key signs: the domain line, a newline and the canonical JSON (sorted keys, no whitespace) of
// {arch, kind, min_version, os, sha256, version}. The sha256 is lower-cased.
func ManifestMessage(m api.UpdateManifest) []byte {
	b, _ := json.Marshal(map[string]string{
		"kind": ManifestKind, "version": m.Version, "os": m.OS, "arch": m.Arch, "sha256": strings.ToLower(m.SHA256), "min_version": m.MinVersion,
	})
	return append([]byte(ManifestDomain+"\n"), b...)
}

// RolloverMessage is the byte string an existing release key signs to hand trust to a new one.
func RolloverMessage(r api.ReleaseRollover) []byte {
	b, _ := json.Marshal(map[string]any{"kind": RolloverKind, "new_key": r.NewKey, "seq": r.Seq})
	return append([]byte(RolloverDomain+"\n"), b...)
}

func verifyAny(keys []ed25519.PublicKey, msg []byte, sigB64 string) bool {
	sig, err := base64.StdEncoding.DecodeString(strings.TrimSpace(sigB64))
	if err != nil || len(sig) != ed25519.SignatureSize {
		return false
	}
	for _, k := range keys {
		if len(k) == ed25519.PublicKeySize && ed25519.Verify(k, msg, sig) {
			return true
		}
	}
	return false
}

// VerifyRelease checks the release signature and that the signed platform is the one this agent runs on.
func VerifyRelease(keys []ed25519.PublicKey, m api.UpdateManifest, goos, goarch string) error {
	if m.ReleaseSignature == "" {
		return errors.New("update has no release signature")
	}
	if m.OS != goos || m.Arch != goarch {
		return fmt.Errorf("update is for %s/%s, this agent runs on %s/%s", m.OS, m.Arch, goos, goarch)
	}
	if !verifyAny(keys, ManifestMessage(m), m.ReleaseSignature) {
		return errors.New("update release signature invalid")
	}
	return nil
}

// VerifyRollover checks a rollover message against the trusted keys and the highest sequence already applied, and returns the new public key.
func VerifyRollover(trusted []ed25519.PublicKey, haveSeq int, r api.ReleaseRollover) (ed25519.PublicKey, error) {
	if r.Kind != RolloverKind {
		return nil, errors.New("not a release key rollover")
	}
	if r.Seq <= haveSeq {
		return nil, errors.New("rollover is not newer than the one already applied")
	}
	nk, err := base64.StdEncoding.DecodeString(strings.TrimSpace(r.NewKey))
	if err != nil || len(nk) != ed25519.PublicKeySize {
		return nil, errors.New("rollover key is not an ed25519 public key")
	}
	if !verifyAny(trusted, RolloverMessage(r), r.Signature) {
		return nil, errors.New("rollover signature invalid")
	}
	return ed25519.PublicKey(nk), nil
}

// ParseKeys decodes a comma separated list of base64 ed25519 public keys (the build-time embedded release keys). Invalid entries are skipped.
func ParseKeys(list string) []ed25519.PublicKey {
	var out []ed25519.PublicKey
	for _, s := range strings.Split(list, ",") {
		if b, err := base64.StdEncoding.DecodeString(strings.TrimSpace(s)); err == nil && len(b) == ed25519.PublicKeySize {
			out = append(out, ed25519.PublicKey(b))
		}
	}
	return out
}
