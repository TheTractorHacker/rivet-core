<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

/**
 * Starter catalog TEMPLATES: data only. A template names a product, the kind of installer, a SUGGESTED silent-argument template with its placeholders, and what the
 * technician must supply. It carries no vendor binary, no download address, no hash and no vendor key, because those rot or are secret: every template says so.
 * `verified` is false for every one of them: nothing here has been run on a real PC. The arguments of a vendor template are the author's best recollection of the
 * vendor's documented silent installation and MUST be checked against the vendor's current documentation (and run in a dry run, then on one pilot machine) before use;
 * an administrator can replace them when instantiating the template. Vendors whose silent switches the author could not state with certainty (Veeam Agent, ESET,
 * Bitdefender, Sophos, SentinelOne, Datto and the like) are deliberately NOT listed: use `custom_exe` and the vendor's own documentation.
 *
 * @api
 */
final class SoftwareTemplates
{
    public const PROVIDES = ['version' => 'the version text (the vendor\'s own number)', 'download' => 'either an https address on an allowed host (add the host first) or an uploaded file (hosted installer)',
        'sha256' => 'the SHA-256 of the installer you downloaded and checked (not needed for an uploaded file)', 'size_bytes' => 'its exact size (not needed for an uploaded file)',
        'detect' => 'how to tell it is installed: the program\'s name as Windows lists it (uninstall_name) or an MSI product code'];

    /**
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $unverified = 'UNVERIFIED: never run on a real PC. Check the arguments against the vendor\'s current documentation, run a dry run, then one pilot machine.';

        return [
            [
                'key' => 'huntress_agent', 'name' => 'Huntress Agent', 'publisher' => 'Huntress', 'os' => 'windows', 'fmt' => 'exe', 'verified' => false,
                'silent_args' => '/ACCT_ID={{account_key}} /ORG_KEY={{org_key}} /S',
                'placeholders' => [
                    ['name' => 'account_key', 'secret' => true, 'what' => 'the Huntress account key (the same for every organisation of your account)'],
                    ['name' => 'org_key', 'secret' => true, 'what' => 'the organisation key of the customer (set it per client or location)'],
                ],
                'supply' => ['installer' => 'Download the Windows installer from your Huntress portal (its address is account-specific: upload it as a hosted installer, or host it on an allowed host), then record its SHA-256 and size.',
                    'detect' => 'uninstall_name, normally "Huntress Agent" (check Programs and Features on a machine that has it)'],
                'notes' => [$unverified, 'The switch names above are a placeholder to be confirmed with Huntress: their installer has changed between releases. A value may not contain spaces or quotes.',
                    'Put it first (low install order) and mark it required if the later apps must not be installed on an unprotected PC.'],
            ],
            [
                'key' => 'comet_backup_windows', 'name' => 'Comet Backup (Windows)', 'publisher' => 'Comet', 'os' => 'windows', 'fmt' => 'exe', 'verified' => false,
                'silent_args' => '/S /SERVER={{server_url}} /COMPANY={{company_id}}',
                'placeholders' => [
                    ['name' => 'server_url', 'secret' => false, 'what' => 'your Comet Server address (https://...)'],
                    ['name' => 'company_id', 'secret' => false, 'what' => 'the Comet company or policy identifier of the customer, if your installer takes one'],
                ],
                'supply' => ['installer' => 'Comet normally gives you a branded installer from your Comet Server. Upload it as a hosted installer (its address is not stable), then record its SHA-256 and size.',
                    'detect' => 'uninstall_name, as the branded product is listed in Programs and Features'],
                'notes' => [$unverified, 'Comet branded installers differ by server: replace the suggested arguments with the ones your own installer documents. Account passwords cannot be passed as a parameter (only letters, digits and . _ ~ : / = + , - are allowed in a value); prefer an auto-login or policy based installer.'],
            ],
            [
                'key' => 'comet_backup_linux', 'name' => 'Comet Backup (Linux package)', 'publisher' => 'Comet', 'os' => 'linux', 'fmt' => 'apt', 'verified' => false,
                'silent_args' => '',
                'placeholders' => [],
                'supply' => ['installer' => 'Linux packages come from a repository the machine is already configured to use; add it on the machine (the agent never adds a repository). Give the package name.',
                    'detect' => 'derived from the package name'],
                'notes' => [$unverified, 'A Linux version has no parameters: configure the server address with the vendor\'s own configuration file or a script job.'],
            ],
            [
                'key' => 'custom_exe', 'name' => 'Custom EXE installer', 'publisher' => '', 'os' => 'windows', 'fmt' => 'exe', 'verified' => false,
                'silent_args' => '/S',
                'placeholders' => [],
                'supply' => ['installer' => 'Any vendor setup program that has a documented silent mode. Upload it or host it on an allowed host.', 'silent_args' => 'the vendor\'s documented silent switches; use {{name}} straight after = or : for a vendor value',
                    'detect' => 'uninstall_name (exact DisplayName) or a product code'],
                'notes' => [$unverified, '/S is only the common default; many vendors use /silent, /quiet, /VERYSILENT or /qn. Exit codes 3010 mean "installed, restart needed"; any other non-zero code fails the install.'],
            ],
            [
                'key' => 'custom_msi', 'name' => 'Custom MSI installer', 'publisher' => '', 'os' => 'windows', 'fmt' => 'msi', 'verified' => false,
                'silent_args' => '',
                'placeholders' => [],
                'supply' => ['installer' => 'An .msi package. Upload it or host it on an allowed host.', 'detect' => 'the MSI product code, such as {12345678-ABCD-1234-ABCD-1234567890AB}'],
                'notes' => [$unverified, 'An MSI is installed with fixed options (/qn /norestart): MSI properties such as ACCT_KEY=... cannot be passed, so a vendor that needs them cannot use this template. Use the vendor\'s EXE bootstrapper (custom_exe) instead.'],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }

        return null;
    }
}
