package update

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
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
	rev := append([]string{}, r.Revoke...)
	sort.Strings(rev) // sorted, so the signed bytes do not depend on the order the server listed them in
	b, _ := json.Marshal(map[string]any{"kind": RolloverKind, "new_key": r.NewKey, "revoke": rev, "seq": r.Seq})
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

// RolloverResult is what a verified rollover changes: the key to trust from now on and the keys to stop trusting.
type RolloverResult struct {
	NewKey  ed25519.PublicKey
	Revoked []string // base64 public keys
}

// VerifyRollover checks a rollover message against the currently trusted keys and the sequence already applied. The sequence must be EXACTLY the next
// one (haveSeq+1): a message signed with a huge sequence number can neither skip ahead nor lock out the legitimate later ones (review CRR-1). The new
// key cannot be in its own revoke list.
func VerifyRollover(trusted []ed25519.PublicKey, haveSeq int, r api.ReleaseRollover) (RolloverResult, error) {
	var zero RolloverResult
	if r.Kind != RolloverKind {
		return zero, errors.New("not a release key rollover")
	}
	if r.Seq != haveSeq+1 {
		return zero, fmt.Errorf("rollover sequence %d is not the next one (%d)", r.Seq, haveSeq+1)
	}
	nk, err := base64.StdEncoding.DecodeString(strings.TrimSpace(r.NewKey))
	if err != nil || len(nk) != ed25519.PublicKeySize {
		return zero, errors.New("rollover key is not an ed25519 public key")
	}
	for _, rv := range r.Revoke {
		if strings.TrimSpace(rv) == strings.TrimSpace(r.NewKey) {
			return zero, errors.New("rollover revokes its own new key")
		}
		if b, err := base64.StdEncoding.DecodeString(strings.TrimSpace(rv)); err != nil || len(b) != ed25519.PublicKeySize {
			return zero, errors.New("rollover revokes something that is not an ed25519 public key")
		}
	}
	if len(r.Revoke) > 16 {
		return zero, errors.New("rollover revokes too many keys")
	}
	if !verifyAny(trusted, RolloverMessage(r), r.Signature) {
		return zero, errors.New("rollover signature invalid")
	}
	return RolloverResult{NewKey: ed25519.PublicKey(nk), Revoked: append([]string(nil), r.Revoke...)}, nil
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
