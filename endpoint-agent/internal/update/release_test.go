package update

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"net/http"
	"os"
	"runtime"
	"strings"
	"testing"

	"rivetit-agent/internal/api"
)

func relKey(t *testing.T) (ed25519.PublicKey, ed25519.PrivateKey) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return pub, priv
}

func signedManifest(priv ed25519.PrivateKey) api.UpdateManifest {
	m := api.UpdateManifest{Version: "1.2.3", SHA256: "aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899", OS: "linux", Arch: "amd64", MinVersion: "1.0.0"}
	m.ReleaseSignature = base64.StdEncoding.EncodeToString(ed25519.Sign(priv, ManifestMessage(m)))
	return m
}

// CORE-DS-26: the release signature covers kind, version, os, arch, sha256 and min_version; changing any of them breaks it.
func TestReleaseSignatureCoversEveryField(t *testing.T) {
	pub, priv := relKey(t)
	keys := []ed25519.PublicKey{pub}
	m := signedManifest(priv)
	if err := VerifyRelease(keys, m, "linux", "amd64"); err != nil {
		t.Fatal(err)
	}
	for name, mut := range map[string]func(*api.UpdateManifest){
		"version": func(m *api.UpdateManifest) { m.Version = "1.2.4" },
		"os":      func(m *api.UpdateManifest) { m.OS = "windows" },
		"arch":    func(m *api.UpdateManifest) { m.Arch = "arm64" },
		"sha256":  func(m *api.UpdateManifest) { m.SHA256 = "00" + m.SHA256[2:] },
		"min":     func(m *api.UpdateManifest) { m.MinVersion = "0.0.1" },
		"nosig":   func(m *api.UpdateManifest) { m.ReleaseSignature = "" },
	} {
		x := m
		mut(&x)
		if err := VerifyRelease(keys, x, "linux", "amd64"); err == nil {
			t.Errorf("%s: tampered manifest accepted", name)
		}
	}
	// the manifest must be for THIS platform even when correctly signed
	if err := VerifyRelease(keys, m, "windows", "amd64"); err == nil {
		t.Error("a linux package was accepted on windows")
	}
	if err := VerifyRelease(keys, m, "linux", "arm64"); err == nil {
		t.Error("an amd64 package was accepted on arm64")
	}
	// the signature of another key
	other, _ := relKey(t)
	if err := VerifyRelease([]ed25519.PublicKey{other}, m, "linux", "amd64"); err == nil {
		t.Error("signed by an untrusted key")
	}
}

func TestReleaseKeyRollover(t *testing.T) {
	oldPub, oldPriv := relKey(t)
	newPub, newPriv := relKey(t)
	b64 := func(k ed25519.PublicKey) string { return base64.StdEncoding.EncodeToString(k) }
	roll := api.ReleaseRollover{Kind: "release_key_rollover", NewKey: b64(newPub), Revoke: []string{b64(oldPub)}, Seq: 1}
	roll.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(oldPriv, RolloverMessage(roll)))
	got, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, roll)
	if err != nil || !got.NewKey.Equal(newPub) || len(got.Revoked) != 1 || got.Revoked[0] != b64(oldPub) {
		t.Fatalf("%v %+v", err, got)
	}
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 1, roll); err == nil {
		t.Error("a replayed rollover (seq not the next one) was accepted")
	}
	// review CRR-1: a huge sequence number cannot skip ahead and lock out later rollovers
	big := roll
	big.Seq = 1 << 62
	big.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(oldPriv, RolloverMessage(big)))
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, big); err == nil {
		t.Error("a rollover with a far-future sequence number was accepted")
	}
	// the revoke list is signed: dropping or adding an entry breaks the signature
	tampered := roll
	tampered.Revoke = nil
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, tampered); err == nil {
		t.Error("the revoke list is not covered by the signature")
	}
	forged := roll
	forged.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(newPriv, RolloverMessage(roll))) // signed by the NEW key, which is not trusted yet
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, forged); err == nil {
		t.Error("a rollover signed by an untrusted key was accepted")
	}
	self := roll
	self.Revoke = []string{b64(newPub)}
	self.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(oldPriv, RolloverMessage(self)))
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, self); err == nil {
		t.Error("a rollover that revokes its own new key was accepted")
	}
	bad := roll
	bad.NewKey = "!!!"
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, bad); err == nil {
		t.Error("garbage key accepted")
	}
}

// CORE-DS-26: with release keys configured Apply refuses a manifest without a valid release signature, before downloading anything.
func TestApplyRequiresTheReleaseSignatureWhenKeysAreKnown(t *testing.T) {
	e := newEnv(t)
	pub, priv := relKey(t)
	m := e.mgr("1.0.0")
	m.ReleaseKeys = func() []ed25519.PublicKey { return []ed25519.PublicKey{pub} }
	man := e.manifest("1.1.0")
	if _, err := e.apply(m, man); err == nil {
		t.Fatal("an update without a release signature was applied")
	}
	man.OS, man.Arch = runtime.GOOS, runtime.GOARCH
	man.ReleaseSignature = base64.StdEncoding.EncodeToString(ed25519.Sign(priv, ManifestMessage(man)))
	if restart, err := e.apply(m, man); err != nil || !restart {
		t.Fatalf("a correctly release-signed update was refused: %v", err)
	}
}

// review CRR-1: keys are compared by value in canonical form. A non-canonical spelling of the new key (spare padding bits) or a revoke entry with
// whitespace must not slip past the self-revoke guard or silently never match.
func TestRolloverCanonicalKeyForms(t *testing.T) {
	oldPub, oldPriv := relKey(t)
	newPub, _ := relKey(t)
	canon := base64.StdEncoding.EncodeToString(newPub)
	// same 32 bytes, different last character (the low 2 bits before "=" are padding bits)
	alt := canon[:42] + string("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"[(strings.IndexByte("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/", canon[42])+1)%64]) + "="
	if b, err := base64.StdEncoding.DecodeString(alt); err != nil || string(b) != string(newPub) {
		t.Skip("could not construct a non-canonical spelling here")
	}
	sign := func(r api.ReleaseRollover) api.ReleaseRollover {
		r.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(oldPriv, RolloverMessage(r)))
		return r
	}
	// new key canonical, revoked entry is the NON-canonical spelling of the same key: must be refused (self revoke / not canonical)
	self := sign(api.ReleaseRollover{Kind: RolloverKind, NewKey: canon, Revoke: []string{alt}, Seq: 1})
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, self); err == nil {
		t.Error("a non-canonical spelling of the new key got past the self-revoke guard")
	}
	nonCanonNew := sign(api.ReleaseRollover{Kind: RolloverKind, NewKey: alt, Seq: 1})
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, nonCanonNew); err == nil {
		t.Error("a non-canonical new key was accepted")
	}
	ws := sign(api.ReleaseRollover{Kind: RolloverKind, NewKey: canon, Revoke: []string{" " + base64.StdEncoding.EncodeToString(oldPub)}, Seq: 1})
	if _, err := VerifyRollover([]ed25519.PublicKey{oldPub}, 0, ws); err == nil {
		t.Error("a revoke entry with whitespace was accepted (it would never match)")
	}
}

// review CRR-1: with release keys ever configured an EMPTY trusted set refuses updates instead of switching verification off.
func TestEmptyKeySetDoesNotDisableReleaseVerification(t *testing.T) {
	e := newEnv(t)
	m := e.mgr("1.0.0")
	m.ReleaseKeys = func() []ed25519.PublicKey { return nil }
	m.ReleaseRequired = func() bool { return true }
	if _, err := e.apply(m, e.manifest("1.1.0")); err == nil {
		t.Fatal("an update was applied although release verification is required and no key is trusted")
	}
	m.ReleaseRequired = func() bool { return false }
	if restart, err := e.apply(m, e.manifest("1.1.0")); err != nil || !restart {
		t.Fatalf("an agent that never had release keys must keep the legacy behaviour: %v", err)
	}
}

// review R4-1: an unreadable trust state is a TRANSIENT error (retry later), not a signature failure that would be recorded for the version.
func TestUnreadableReleaseStateIsTransient(t *testing.T) {
	e := newEnv(t)
	m := e.mgr("1.0.0")
	m.ReleaseKeys = func() []ed25519.PublicKey { return nil }
	m.ReleaseRequired = func() bool { return true }
	m.ReleaseState = func() error { return errors.New("EIO") }
	_, err := e.apply(m, e.manifest("1.1.0"))
	if err == nil || !errors.Is(err, ErrReleaseKeysUnavailable) || !Transient(err) {
		t.Fatalf("got %v (transient=%v)", err, err != nil && Transient(err))
	}
	if _, serr := os.Stat(m.Paths.Staged); serr == nil {
		t.Fatal("something was downloaded")
	}
}

// review R4-1 polish: the trust state is read ONCE per Apply; a read error is transient, never "signature invalid".
func TestReleaseTrustIsReadOnce(t *testing.T) {
	e := newEnv(t)
	m := e.mgr("1.0.0")
	calls := 0
	m.ReleaseTrust = func() ([]ed25519.PublicKey, bool, error) { calls++; return nil, true, errors.New("EIO") }
	_, err := e.apply(m, e.manifest("1.1.0"))
	if calls != 1 || !errors.Is(err, ErrReleaseKeysUnavailable) || !Transient(err) {
		t.Fatalf("calls=%d err=%v", calls, err)
	}
	pub, priv := relKey(t)
	calls = 0
	m.ReleaseTrust = func() ([]ed25519.PublicKey, bool, error) { calls++; return []ed25519.PublicKey{pub}, true, nil }
	man := e.manifest("1.1.0")
	man.OS, man.Arch = runtime.GOOS, runtime.GOARCH
	man.ReleaseSignature = base64.StdEncoding.EncodeToString(ed25519.Sign(priv, ManifestMessage(man)))
	if restart, err := e.apply(m, man); err != nil || !restart || calls != 1 {
		t.Fatalf("restart=%v err=%v calls=%d", restart, err, calls)
	}
}

// CORE-AGENT-1 / Chain C step 2: when release verification is required but no release key is trusted (a release-stamped build without an anchor, or every
// key revoked), Apply refuses with ErrNoReleaseKey BEFORE downloading, even for a manifest that carries an instance signature and a release_signature,
// and the refusal is permanent for that version (not retried as transient).
func TestNoTrustedReleaseKeyRefusesBeforeDownload(t *testing.T) {
	e := newEnv(t)
	hits := 0
	e.srv.Config.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { hits++; w.Write(e.body) })
	m := e.mgr("1.0.0")
	m.ReleaseTrust = func() ([]ed25519.PublicKey, bool, error) { return nil, true, nil }
	_, priv := relKey(t)
	man := e.manifest("1.1.0")
	man.OS, man.Arch = runtime.GOOS, runtime.GOARCH
	man.ReleaseSignature = base64.StdEncoding.EncodeToString(ed25519.Sign(priv, ManifestMessage(man)))
	restart, err := e.apply(m, man)
	if restart || !errors.Is(err, ErrNoReleaseKey) || Transient(err) {
		t.Fatalf("restart=%v err=%v transient=%v", restart, err, Transient(err))
	}
	if hits != 0 {
		t.Fatalf("downloaded %d times before refusing", hits)
	}
	// an agent that never had release keys and is not a release build keeps the legacy behaviour (existing deployments)
	m.ReleaseTrust = func() ([]ed25519.PublicKey, bool, error) { return nil, false, nil }
	if restart, err := e.apply(m, e.manifest("1.1.0")); err != nil || !restart {
		t.Fatalf("legacy (no anchor, not a release build) update refused: %v", err)
	}
}

func TestReleaseBuildStamp(t *testing.T) {
	prev := ReleaseBuild
	defer func() { ReleaseBuild = prev }()
	for v, want := range map[string]bool{"": false, "0": false, "no": false, "1": true, "true": true, "TRUE": true} {
		ReleaseBuild = v
		if IsReleaseBuild() != want {
			t.Errorf("ReleaseBuild=%q: IsReleaseBuild()=%v", v, !want)
		}
	}
}
