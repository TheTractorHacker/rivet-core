<?php

declare(strict_types=1);

/**
 * Regenerates legacy_samples.json from the editions' own function bodies (LegacyEditionFunctions.php). Fixed secrets, random IVs/salts,
 * so the output differs run to run; the committed file is one run. Each sample is checked against the editions' own reader before it is
 * written. Usage: php tests/Fixtures/crypto/generate.php > tests/Fixtures/crypto/legacy_samples.json
 */

require __DIR__ . '/LegacyEditionFunctions.php';

use RivetCore\Tests\Fixtures\LegacyEditions\LegacyEditionFunctions as F;

$settingsKey = bin2hex(hash('sha256', 'rivetcore-fixture-settings-key', true)); // 64 hex characters, like bin2hex(random_bytes(32))
$masterKey = 'Qm9y_8cVx-12AbZk';                                                 // 16 base64url characters, like randomString()
$password = 'Correct Horse 9 Battery';
$out = ['settings_key' => $settingsKey, 'master_key' => $masterKey, 'password' => $password, 'samples' => []];

$add = function (string $format, string $plain, string $stored, array $extra = []) use (&$out): void {
    $out['samples'][] = ['format' => $format, 'plaintext' => $plain, 'stored' => $stored] + $extra;
};

foreach (['smtp-pass', "unicode pässwörd \u{1F511}", '0', str_repeat('long ', 200)] as $p) {
    $s = F::encryptSetting($p, $settingsKey);
    assert(F::decryptSetting($s, $settingsKey) === $p);
    $add('ENC2', $p, $s);
    $s = F::encryptSettingCbc($p, $settingsKey);
    assert(F::decryptSetting($s, $settingsKey) === $p);
    $add('ENC', $p, $s);
}
foreach (['JBSWY3DPEHPK3PXP', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'] as $p) {
    $s = F::encryptOtpSecret($p, $masterKey);
    assert(F::decryptOtpSecret($s, $masterKey) === $p);
    $add('enc', $p, $s);
}
foreach (['admin:hunter2', "pässwörd \u{1F511}", 'x'] as $p) {
    $s = F::encryptCredentialEntryWithKey($p, $masterKey);
    assert(F::decryptCredentialEntryWithKey($s, $masterKey) === $p);
    $add('vault-cbc', $p, $s);
}
$v2 = F::setupFirstUserSpecificKey($password, $masterKey);
assert(F::decryptUserSpecificKey($v2, $password) === $masterKey);
$add('V2', $masterKey, $v2);
$v1 = F::setupFirstUserSpecificKeyV1($password, $masterKey);
assert(F::decryptUserSpecificKey($v1, $password) === $masterKey);
$add('V1', $masterKey, $v1);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
