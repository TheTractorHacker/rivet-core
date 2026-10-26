# Core security audit (rc.42 + later), 2026-10

Status: in progress, written incrementally. No raw secret value appears in this document: a value is referred to by file:line, type, length and the first 4 hex characters of its SHA-256 (fp).

## 1. Scope and method

- Repository: rivet-core, branch `core-audit` from origin/main (v1.0.0-rc.42 plus later commits). Editions are read only.
- Scratch environment (Docker, random passwords, removed afterwards): MariaDB 10.11, PostgreSQL 18, Redis 7 plain, with auth and with TLS, php 8.5 image with pdo_pgsql, golang:1.27.
- Own scanner `scripts/secret-scan.php` (tree and every added line of history on all refs), gitleaks v8.30.0 pinned by image digest (sha256:691af3c7...) run in Docker with the repository mounted read only and `--redact`.

## 2. Part 1: secret detection

Only one `constants.php` exists in the tree: `scripts/rmm-golden/constants.php`. `RedisSurfaceTest.php` is not in the tree: it exists only in commit 9a5b455 on the never-merged branch `quality-gates-1.0`.

Verdict legend: S = synthetic test value, P = intentionally public example, F = false positive. No detection is a real or revoked credential.

| File (introduced) | Detected item (line, type, len, fp) | Verdict | Evidence (derivation based) |
|---|---|---|---|
| scripts/rmm-golden/constants.php (ec74240, 2026-10-07) | :11 signing secret, 88, 9373; :12 public key, 44, b256; :16-23 enrollment secrets 40; :27 admin API token 40, deb8 | S | Seed = SHA-256("RivetIT-agent-TEST-seed"); recomputing the Ed25519 keypair from it reproduced both the 64-byte secret and the public key (match YES/YES). Each of the 8 enrollment secrets has exactly 1 distinct character. The admin token is the hex counting sequence 0123456789abcdef repeated. Used only by golden replay on scratch databases. |
| endpoint-agent/testdata/vectors/agent_job_signing_vectors.json (4f448ea) | :5 seed hex 64 ea33, :7 secret 88 9373, signatures | S | seed_hex equals SHA-256 of the public string; public and secret key derive from it; all 4 job signatures, the check signature and the manifest signature verify under that public key. File is byte-frozen (SHA256SUMS), so it is allowlisted, not edited. |
| tests/Fixtures/crypto/legacy_samples.json and generate.php (355d7ae) | settings key 64 hex 309f; master key 16; 15 stored samples | S | settings key = SHA-256("rivetcore-fixture-settings-key") (match YES); master key and password equal the literals in generate.php; all 15 samples (ENC, ENC2, enc, vault-cbc, V1, V2) decrypt with the published fixture keys to the documented sample plaintexts (0 failures). Not made with an installation key. |
| tests/Unit/Rmm/RedactorTest.php, tests/Integration/Rmm/JobsTest.php (4f448ea, fa6b4c2) | JWT 92 chars 7c5b; ghp_ string 38; xoxb string; AKIA example; Bearer 26 | S / P | JWT header {"alg":"HS256"}, payload {"sub":"1234567890"}, no exp; not verifiable by any component here. Replaced by a token built at run time from the public string rivetcore-fixture-jwt-not-a-secret. ghp_ string is 38 chars (real: 40) and a strict alternating letter-digit run; the xoxb string has 3 segments (real: 4). AKIAIOSFODNN7EXAMPLE is the AWS documentation example. The file exists to test the redactor. |
| endpoint-agent/setup_test.go (5047674), msi_setup_test.go (3f1a60a) | token rvte1.selabc.<13 chars>, len 28, 1f77; UUID 36, 3201 | S / P | Token has a 6 char selector and 13 char secret (a real token: 12 hex plus 40 hex), so no server accepts it. Value renamed to read as fixture. The UUID is the standard documentation UUID. |
| endpoint-agent/internal/change/files_test.go, tests/Integration/Rmm/ChangeMonitorTest.php (5c93352) | PEM block, bob:tok3n123@host, paths | S / F | PEM body is the word SECRETKEYBODYLINE2 plus the public ASN.1 PKCS8 prefix (52 chars, one modulus byte); the credential is 8 chars; the "private" paths are deny-list test inputs. |
| tests/Unit/AuthenticationTest.php (cef1495) | 'password' => 'p:ss w0rd', tk_abc.DEF-123 | S | Short readable strings (max 15 chars) for header validation; no entropy. |
| docs/modules/crypto.md (40575f6) | variable name `$config_settings_enc_key`, salt string, `s3cret!` sample | F | No key material; names and a public salt label. |
| tests/Unit/Crypto/EnvelopeTest.php, KeysTest.php, MeshCookieTest.php, envelope_vectors.json | 64 hex keys (4c0e, da75, 6c86) | P / S | EnvelopeTest key equals the McGrew and Viega GCM test case 16 key (public spec vector, match YES). The others are byte counting sequences. |
| tests/Support/GoldenEdition.php, Postgres/PostgresConnectionTest.php, endpoint-agent agent_test.go, helpers_test.go, embed_test.go, e2e/test_build_host.py, scripts/endpoint-vectors.php | seed (29), wrong password (27), tokens (23-27) | S | Readable words, explicit wrong/fixture markers, or hex counting sequence; accepted only by in-test servers or scratch databases. |
| tests/Security/RedisSurfaceTest.php (9a5b455, branch quality-gates-1.0 only) | 'Sup3rSecretPw' style placeholder passwords | S | Used to prove that a config object does NOT leak; not on main. |
| golden transcripts (tests/Fixtures/rmm/golden*, 06-jobs.json) | public signing key; secret-looking job output | S | Public half of the test key; output strings are the redaction test inputs. |

Edition comparison (hash comparison only, printed match/no-match, no value shown): the public signing key stored in `endpoint_agent_settings` of the RivetIT beta database and of the RivetMSP beta database each differ from the test key (no-match). Production (mwa) and the live RivetMSP site were not touched: cannot confirm for them, by instruction. Only the public half is in the database; the private half is sealed.

Repository and history sweep: own scanner (tree, plus every added line on all refs) and gitleaks (62 raw hits on all refs, 38 in the tree) were triaged: every hit is one of the classes above, a migration-name list (F), or a branch-only file (compliance, store-info, quality-gates-1.0). No private key blocks with key material, no cloud/GitHub/Slack/Stripe/Google tokens, no connection strings with real passwords, no `.env`, config or key files were ever committed. After the allowlist, gitleaks reports "no leaks found" on core-audit.

Confirmed real or revoked exposed secrets: none. Rotation steps: not applicable. History rewrite: not warranted.

Prevention controls added: `scripts/secret-scan.php`, `secret-scan-allowlist.json` (path scoped, reason of 20+ characters each), generated `.gitleaks.toml` and `.semgrepignore` (`scripts/secret-scan-sync.php`), a fixture note at the top of each fixture file, run-time JWT and fragment-built tokens in redaction tests, the CI job `secret scan`, and `tests/Unit/SecretScanTest.php` (rule positives and negatives, src/ and docs/ clean, allowlist discipline, config drift). Mutation checks: disabling the jwt rule, disabling the placeholder filter and removing rule scoping each made the test fail.

Disclosure note: one console listing during the audit echoed the synthetic test signing key of constants.php (derivation-proven synthetic above); nothing else was printed.
