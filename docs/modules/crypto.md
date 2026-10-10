# Crypto

## Overview

`RivetCore\Crypto` is the one encryption implementation both editions share: a versioned envelope with key ids, a key ring and key
file loader, a settings vault with per-purpose subkeys, a resumable rewrap service, and the primitives for wrapping the credentials
vault's data key. The decisions behind it are in [ADR-011](../architecture/ADR-011-envelope-and-key-standard.md); the plan it belongs to
is item 19 and 20 of the [platform gap analysis](../program/PLATFORM_GAP_ANALYSIS_2026-10.md).

- **Owns:** no tables and no migration. Progress of a rewrap job is saved through a `RewrapStateStore` (a file store and an in-memory store ship; an edition may keep the serialised state in its settings table).
- **Needs:** `ext-openssl` (AES-256-GCM) and `ext-sodium` (Argon2id, already required). PHP 8.2 and later.
- **Format:** `v3:<kid>:base64(nonce12 || tag16 || ciphertext)`, AES-256-GCM, the caller's context string (record type and id) as AAD.

| Class | Job |
|---|---|
| `Envelope` / `EnvelopeInterface` | seal, open, rewrap, label; reads legacy formats through `LegacyReader`s |
| `KeyRing`, `KeyGenerator`, `KeyPurpose` | the keys (kid to 32 bytes, one active), generation, rotation step, fingerprint, HKDF labels |
| `KeyFile`, `KeyRingLoader`, `KeyRingLoadResult` | load from a root-owned file outside the web root (JSON or PHP array), environment fallback, permission checks |
| `SettingsVault`, `SettingRead` | named secrets with context binding, lazy re-wrap, fail-closed, subkeys |
| `LegacyReaders` and the `Legacy*Reader` classes | `ENC2:`, `ENC:`, `enc:` (TOTP), `V2:` user key, old vault field, opt-in plaintext |
| `RotationPlan` | pure: what a rotation has to do, from counts per label |
| `Rewrapper`, `RewrapSource`, `RewrapItem`, `Reencryptor`, `EnvelopeReencryptor`, `RewrapOptions`, `RewrapReport`, `RewrapState`, `RewrapStateStore`, `FileRewrapStateStore` | resumable batch re-encryption |
| `VaultKeyWrap`, `KdfParams`, `VaultCipher`, `VaultFieldReencryptor` | vault data key wrapping (Argon2id or PBKDF2), credential fields, legacy migration |
| `DecryptionFailed`, `UnknownKeyId`, `KeyUnavailable`, `InvalidKeyMaterial` (all `CryptoException`) | typed failures |

## Contracts an edition must implement

Only the rewrap source, and only if it runs the rewrap tool:

| Interface | Methods | What Core expects |
|---|---|---|
| `Crypto\RewrapSource` | `name()`, `next(?string $afterId, int $limit)`, `replace(string $id, string $expected, string $replacement)` | stable order, exclusive cursor, no side effects on read, `replace` is an atomic compare-and-set (`UPDATE t SET col = ? WHERE id = ? AND col = ?`, true when one row changed) |

Run `Testing\RewrapSourceConformanceTestCase` on it and `Testing\EnvelopeConformanceTestCase` on any wrapper you put around `Envelope`
(see [../conformance.md](../conformance.md)).

## Key classes

### Envelope

```php
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyRing;

$ring = KeyGenerator::newRing();                 // one fresh random active key, kid like k20261010-3fa9
$envelope = new Envelope($ring);

$stored = $envelope->seal('hunter2', 'credential:42:password');
assert(str_starts_with($stored, 'v3:' . $ring->activeKid() . ':'));
assert($envelope->open($stored, 'credential:42:password') === 'hunter2');

// The context is authenticated: the same ciphertext does not open for another record.
try {
    $envelope->open($stored, 'credential:43:password');
    assert(false, 'must not open');
} catch (RivetCore\Crypto\DecryptionFailed) {
}
```

`seal()` and `open()` need a non-empty context. `open()` throws, it never returns `''` or partial data for a value it could not
authenticate.

### Rotation

```php
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\RotationPlan;

$old = KeyGenerator::newRing();
$stored = (new Envelope($old))->seal('secret', 'settings|smtp');

$ring = KeyGenerator::rotate($old);              // adds a key and makes it active; the old one stays decrypt-only
$envelope = new Envelope($ring);
assert($envelope->open($stored, 'settings|smtp') === 'secret');   // still readable
assert($envelope->needsRewrap($stored));

$plan = RotationPlan::build($ring, RotationPlan::inventory([$stored], $envelope));
assert($plan->pendingCount() === 1 && !$plan->isComplete());

$stored = $envelope->rewrap($stored, 'settings|smtp');            // now under the new kid
$plan = RotationPlan::build($ring, RotationPlan::inventory([$stored], $envelope));
assert($plan->isComplete() && $plan->retirable() === [$old->activeKid()]);
```

### SettingsVault (settings, TOTP seeds, tokens)

```php
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyPurpose;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\LegacyReaders;
use RivetCore\Crypto\SettingsVault;

// An install that has `$config_settings_enc_key` (64 hex characters) and old ENC2: values:
$legacyKey = bin2hex(random_bytes(32));
$nonce = random_bytes(12);
$tag = '';
$ct = openssl_encrypt('smtp-secret', 'aes-256-gcm', hash('sha256', $legacyKey, true), OPENSSL_RAW_DATA, $nonce, $tag);
$old = 'ENC2:' . base64_encode($nonce . $tag . $ct);          // what RivetIT/RivetMSP stored until now

$vault = new SettingsVault(KeyGenerator::ringFromLegacySettingsKey($legacyKey), LegacyReaders::settings($legacyKey));

$persisted = [];
$plain = $vault->decrypt('smtp_password', $old, function (string $new, string $previous) use (&$persisted): void {
    $persisted[] = [$new, $previous];   // UPDATE settings SET col = :new WHERE col = :previous
});
assert($plain === 'smtp-secret');
assert(count($persisted) === 1 && str_starts_with($persisted[0][0], 'v3:k1:'));

// Per-purpose subkeys come from the same master key.
$totp = new SettingsVault(KeyGenerator::ringFromLegacySettingsKey($legacyKey), [], KeyPurpose::TOTP);
$seed = $totp->encrypt('user:7', 'JBSWY3DPEHPK3PXP');
assert($totp->decrypt('user:7', $seed) === 'JBSWY3DPEHPK3PXP');
$hmacKey = $vault->subkey(KeyPurpose::MEDIA_TOKEN);             // 32 raw bytes for hash_hmac()
assert(strlen($hmacKey) === 32);
```

Fail-closed: with an empty ring `encrypt()` and the reading of any encrypted value throw `KeyUnavailable`; nothing is ever stored in
cleartext and nothing is turned into `''`. A value in none of the registered formats is `DecryptionFailed`; cleartext legacy values
are only accepted when you register `LegacyReaders::plaintext()`.

### Key files

```php
use RivetCore\Crypto\KeyFile;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyRingLoader;

$dir = sys_get_temp_dir() . '/crypto-doc-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$path = $dir . '/keys.json';

KeyFile::write($path, KeyGenerator::newRing());          // atomic, mode 0640, refuses to overwrite
$loaded = (new KeyRingLoader())->load($path);
assert($loaded->source === 'file' && $loaded->ring->hasActive());
echo 'fingerprint ', $loaded->ring->fingerprint((string) $loaded->ring->activeKid()), "\n";

chmod($path, 0644);                                      // world-readable: loaded, with a warning
assert((new KeyRingLoader())->load($path)->warnings !== []);
chmod($path, 0666);                                      // world-writable: refused
try {
    (new KeyRingLoader())->load($path);
    assert(false);
} catch (RivetCore\Crypto\KeyUnavailable) {
}
unlink($path);
rmdir($dir);
```

File format (`.json`; a `.php` file may `return` the same array): `{"version":1,"active":"k2","keys":{"k1":{"key":"<64 hex>","created":"..."},"k2":"<64 hex>"}}`.
Install it as `root:www-data` mode `0640` outside the web root. Without a file the loader reads `RIVETCORE_KEYRING` (the same JSON) or
`RIVETCORE_KEK` (one hex key, id from `RIVETCORE_KEK_ID`, default `k1`). A file that exists but is broken is an error, never silently
replaced by the environment. `new KeyRingLoader(strict: true)` refuses a file with any permission warning.

`KeyGenerator::fingerprint()` is the formula of the editions' backup manifests (`substr(sha256('rivetit-settings-key-fingerprint|v1|' . $key), 0, 16)`):
a manifest can name the key without containing it. For a ring built with `ringFromLegacySettingsKey()` from a 64-hex settings key the
fingerprint equals the one the manifest already records.

### Rewrapper (resumable `rewrap` / `vault-rewrap`)

```php
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\EnvelopeReencryptor;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\RewrapOptions;
use RivetCore\Crypto\Rewrapper;
use RivetCore\Testing\InMemoryRewrapSource;
use RivetCore\Testing\InMemoryRewrapStateStore;

$k1 = KeyGenerator::newRing();
$rows = [];
for ($i = 1; $i <= 7; $i++) {
    $rows[$i] = ['ciphertext' => (new Envelope($k1))->seal("secret-$i", "row:$i"), 'context' => "row:$i"];
}
$source = new InMemoryRewrapSource('demo', $rows);       // an edition implements RewrapSource over its table
$envelope = new Envelope(KeyGenerator::rotate($k1));
$rewrapper = new Rewrapper(new InMemoryRewrapStateStore());   // pass an AuditService as the second argument to log crypto.rewrap
$reencryptor = new EnvelopeReencryptor($envelope);

$dry = $rewrapper->run($source, $reencryptor, new RewrapOptions(dryRun: true));
assert($dry->rewrapped === 7 && $source->get('1') === $rows[1]['ciphertext']);   // nothing was written

$part = $rewrapper->run($source, $reencryptor, new RewrapOptions(batchSize: 3, maxBatches: 1));
assert(!$part->completed && $part->cursor === '3');        // stopped cleanly; progress is saved

$rest = $rewrapper->run($source, $reencryptor, new RewrapOptions(batchSize: 3));   // resumes after row 3
assert($rest->isClean() && $rest->rewrapped === 7 && $rest->scanned === 7);

$again = $rewrapper->run($source, $reencryptor);
assert($again->rewrapped === 0, 'idempotent');
```

Behaviour: every write is a compare-and-set (a row edited meanwhile is a `conflict`, left for the next pass); a value that cannot be
read is counted in `failed` with its id and left as it was; `isClean()` is true only when the end was reached with no failures and no
conflicts, so run again until it reports `rewrapped = 0`. With an `AuditService` one `crypto.rewrap` event is logged per run (action
`dry_run`, `partial` or `complete`; counts and ids only, never values).

### The credentials vault: DEK, user wraps, fields

```php
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\KdfParams;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\VaultCipher;
use RivetCore\Crypto\VaultKeyWrap;

$wrap = new VaultKeyWrap(KdfParams::argon2id(19456, 2));      // production: new VaultKeyWrap() = preferred(): Argon2id 64 MiB x 3, else PBKDF2 600000
$dek = VaultKeyWrap::generateDek();                           // 32 random bytes, the key of every credential field

$forAlice = $wrap->wrap($dek, 'alice password', 'user:7');    // stored in users.user_specific_encryption_ciphertext
assert($wrap->unwrap($forAlice, 'alice password', 'user:7') === $dek);

$forAlice = $wrap->rewrap($forAlice, 'alice password', 'a new password', 'user:7');   // password change: same DEK, no credential touched
assert($wrap->unwrap($forAlice, 'a new password', 'user:7') === $dek);

$kek = new Envelope(KeyRing::single(random_bytes(32)));
$byInstance = $wrap->wrapForInstance($dek, $kek, 'vault');    // recovery path: the KEK can re-issue a user's wrap
assert($wrap->unwrapForInstance($byInstance, $kek, 'vault') === $dek);

$cipher = new VaultCipher($dek);
$field = $cipher->seal('hunter2', 42, 'password');            // AAD credential:42:password
assert($cipher->open($field, 42, 'password') === 'hunter2');
try {
    $cipher->open($field, 43, 'password');                    // moved to another credential
    assert(false);
} catch (RivetCore\Crypto\DecryptionFailed) {
}
```

A wrap is `vw3:<kdf>:<params>:<salt>:<nonce||tag||ct>`; the parameters travel with it, so they can be raised later
(`needsUpgrade()` tells you to re-wrap at the next login) and an old wrap still opens. The whole header and the context are
authenticated. Parameters below Argon2id 19 MiB x 2 or PBKDF2 600000, or above sanity caps, are refused when writing and when reading.

Legacy migration, for a vault whose master key is the old 16-character string:

```php
use RivetCore\Crypto\VaultCipher;
use RivetCore\Crypto\VaultKeyWrap;

$legacyMasterKey = 'Qm9y_8cVx-12AbZk';
$iv = 'abcdefghijklmnop';
$legacyField = $iv . openssl_encrypt('s3cret!', 'aes-128-cbc', $legacyMasterKey, 0, $iv);   // what encryptCredentialEntry() stored

$cipher = new VaultCipher(VaultKeyWrap::generateDek());
assert(VaultCipher::isLegacy($legacyField));
$v3 = $cipher->migrate($legacyField, 42, $legacyMasterKey, 'password');
assert($cipher->open($v3, 42, 'password') === 's3cret!');
assert($cipher->migrate($v3, 42, $legacyMasterKey, 'password') === $v3);   // already v3: unchanged
```

For a whole column use `Rewrapper` with `new VaultFieldReencryptor($cipher, legacyDek: $legacyMasterKey)` (legacy to v3) or
`new VaultFieldReencryptor($newCipher, from: $oldCipher)` (DEK rotation); the item context must be `VaultCipher::contextFor($id, 'password')`.
The legacy format is unauthenticated CBC, so a wrong master key can occasionally "decrypt" to garbage: verify one known credential
before a bulk run. `VaultKeyWrap::openLegacyUserKey($v2, $password)` returns the legacy master key held by a user's `V2:` wrap.

## Configuration

None in Core. The edition decides where the key file lives (`/etc/<product>/keys.json`), which purposes it uses, the Argon2id cost
(`KdfParams::argon2id($memoryKib, $ops)`) and where rewrap progress is kept.

## How it fails

| Situation | Result |
|---|---|
| No key configured, or the ring has no active key, when sealing | `KeyUnavailable`; nothing is written |
| No key configured when opening a v3 value | `KeyUnavailable` |
| v3 value naming a kid that is not in the ring | `UnknownKeyId` (carries `->kid`) |
| Wrong key, wrong context, edited, truncated, extended, non-canonical base64, unknown format, wrong password | `DecryptionFailed` (the cause is not distinguishable) |
| Empty context or empty setting name | `\InvalidArgumentException` (a programming error, not data) |
| Weak, short or duplicated key, bad kid, broken key file | `InvalidKeyMaterial` |
| World-writable key file | `KeyUnavailable` (always); world-readable: a warning (`strict: true`: refused) |
| A persist callback that throws (lazy re-wrap) | logged by class name only; the read still succeeds |
| A row that cannot be read during a rewrap | counted in `failed` with its id; left untouched |

## Security notes

- AES-256-GCM through OpenSSL: tag verification is OpenSSL's constant-time check and nothing in this namespace compares secrets itself.
  Nonces are 96-bit random per call; keep one key under about 2^32 messages. HKDF purpose subkeys and rotation keep a key far below that.
- Messages and logs never contain plaintext, key bytes or ciphertext. `KeyRing` hides its keys from `var_dump`.
- The `nonceSource` constructor argument exists only for known-answer tests. Never set it.
- A key that comes from the environment is visible to every process of the user and to `phpinfo()`; prefer the key file.
- The KEK file, the database dump and the backup passphrase must not share a copy or a disk ([ADR-011](../architecture/ADR-011-envelope-and-key-standard.md)).

## Used by (RivetIT and RivetMSP)

Not adopted yet: this release adds the code. The adoption steps are in [UPGRADING.md](../../UPGRADING.md) ("Crypto adoption") and
ADR-011. RivetIT's `encryptSetting`/`decryptSetting`, `encryptOtpSecret` and the `V2:` vault functions are the code it replaces.

## Links

[ADR-011](../architecture/ADR-011-envelope-and-key-standard.md) | [Gap analysis](../program/PLATFORM_GAP_ANALYSIS_2026-10.md) |
[Conformance kit](../conformance.md) | [Performance](../PERFORMANCE.md) | [Threat model](../security/threat-model.md)
