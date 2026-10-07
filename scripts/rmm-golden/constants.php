<?php
declare(strict_types=1);

/**
 * Fixed, public test fixtures shared by golden.php (the driver) and adapter-rivetit.php (the edition seeder).
 * Nothing here is a secret: the signing key is the test key already committed in agent_job_signing_vectors.json
 * (seed = SHA-256 of the ASCII string "RivetIT-agent-TEST-seed"); every token is a throwaway value that only ever exists in a scratch database.
 */
return [
    // Instance signing key installed into the scratch database (libsodium 64-byte secret, base64) and its public half.
    'signing_secret_b64' => 'KFX0TnoDK91ROPEetLa3t9qYQdbOb+HRXSiQi6MuXN2IxvVRWzmx8eyZzy5GLjPu8+BQzACYpwx3Z0JCPwTkEQ==',
    'signing_public_b64' => 'iMb1UVs5sfHsmc8uRi4z7vPgUMwAmKcMd2dCQj8E5BE=',

    // Enrollment tokens: rvte1.<12 hex selector>.<40 hex secret>. client_id refers to the two seeded clients (1 = "Golden Dept A", 2 = "Golden Dept B").
    'tokens' => [
        'c1'        => ['token' => 'rvte1.a1a1a1a1a101.' . '1111111111111111111111111111111111111111', 'client_id' => 1, 'max_uses' => 500, 'ttl_h' => 24, 'revoked' => false, 'used' => 0, 'ring' => 'stable'],
        'c2'        => ['token' => 'rvte1.a2a2a2a2a202.' . '2222222222222222222222222222222222222222', 'client_id' => 2, 'max_uses' => 500, 'ttl_h' => 24, 'revoked' => false, 'used' => 0, 'ring' => 'stable'],
        'expired'   => ['token' => 'rvte1.a3a3a3a3a303.' . '3333333333333333333333333333333333333333', 'client_id' => 1, 'max_uses' => 5, 'ttl_h' => -1, 'revoked' => false, 'used' => 0, 'ring' => 'stable'],
        'revoked'   => ['token' => 'rvte1.a4a4a4a4a404.' . '4444444444444444444444444444444444444444', 'client_id' => 1, 'max_uses' => 5, 'ttl_h' => 24, 'revoked' => true, 'used' => 0, 'ring' => 'stable'],
        'exhausted' => ['token' => 'rvte1.a5a5a5a5a505.' . '5555555555555555555555555555555555555555', 'client_id' => 1, 'max_uses' => 1, 'ttl_h' => 24, 'revoked' => false, 'used' => 1, 'ring' => 'stable'],
        'inst'      => ['token' => 'rvte1.a6a6a6a6a606.' . '6666666666666666666666666666666666666666', 'client_id' => 1, 'max_uses' => 500, 'ttl_h' => 24, 'revoked' => false, 'used' => 0, 'ring' => 'stable'],
        'inst2'     => ['token' => 'rvte1.a7a7a7a7a707.' . '7777777777777777777777777777777777777777', 'client_id' => 2, 'max_uses' => 500, 'ttl_h' => 24, 'revoked' => false, 'used' => 0, 'ring' => 'pilot'],
        'ratelimit' => ['token' => 'rvte1.a8a8a8a8a808.' . '8888888888888888888888888888888888888888', 'client_id' => 1, 'max_uses' => 500, 'ttl_h' => 24, 'revoked' => false, 'used' => 0, 'ring' => 'stable'],
    ],

    // Technician API token of seeded user 1 (administrator).
    'admin_api_token' => '0123456789abcdef0123456789abcdef01234567',

    'clients' => [1 => 'Golden Dept A', 2 => 'Golden Dept B'],

    // Pre-existing edition assets the enrollment matcher can find.
    'assets' => [
        'linkable' => ['name' => 'GOLD-FRONTDESK', 'serial' => 'GOLD-SER-LINK', 'client_id' => 1],
        'otherdept' => ['name' => 'GOLD-OTHERDEPT', 'serial' => 'GOLD-SER-OTHER', 'client_id' => 2],
    ],

    // Fake Windows executables (valid PE header for the arch) published as hosted agent binaries.
    'binaries' => [
        ['version' => '1.0.0', 'arch' => 'amd64', 'size' => 8192, 'fill' => 'golden-100', 'current' => true, 'release' => false],
        ['version' => '1.1.0', 'arch' => 'amd64', 'size' => 12288, 'fill' => 'golden-110', 'current' => false, 'release' => 'stable'],
    ],

    'service_url_template' => '%BASE%',   // replaced with the base URL of the main server
];
