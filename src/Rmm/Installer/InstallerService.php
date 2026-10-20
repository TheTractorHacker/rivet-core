<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Installer;

use RivetCore\Rmm\Assets\DeviceTypeClassifier;
use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Mesh\MeshAgentService;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Update\UpdateService;

/**
 * The administrator side of installers: issue a per-client installer (an audited enrollment token plus the stamp payload built from
 * the current base binary, the service URL and the optional CA certificate), hand it out as a download, and write the deployment
 * snippets (PowerShell for an RMM, an Intune platform script or a GPO startup script; a shell one-liner for Linux with
 * install-linux.sh). The token-gated download that scripts call is {@see InstallerDownload} (`POST agent_installer`).
 *
 * Nothing is created when a precondition fails, and a token whose installer could not be served is revoked again.
 *
 * @api
 */
final class InstallerService
{
    /** Names an Intune detection rule or a GPO check can use (the Windows service and the installed file). */
    public const WINDOWS_SERVICE = 'RivetITAgent';
    public const WINDOWS_INSTALLED_FILE = '%ProgramFiles%\\RivetIT\\Agent\\rivetit-agent.exe';

    public function __construct(
        private readonly Sql $sql,
        private readonly UpdateService $updates,
        private readonly InstallerDownload $installer,
        private readonly EnrollmentService $enrollment,
        private readonly RmmTenancyInterface $tenancy,
        private readonly RmmAuditInterface $audit,
        private readonly BinaryStore $binaries,
        private readonly string $clientLabel = 'client',
        private readonly ?MeshAgentService $meshAgents = null,
        private readonly ?\RivetCore\Rmm\AutoInstall\AutoInstallService $autoInstall = null,
    ) {
    }

    // ---------------------------------------------------------------- issuing

    /**
     * Create the enrollment token and the stamp payload for a client. Nothing is created when a precondition fails.
     *
     * $installMeshAgent is the tick-box "also install the MeshCentral agent (after the device is approved)" (off by default). Since rc.27 the installer carries NOTHING of
     * the Mesh settings (the device group id is an invitation secret): the choice is only remembered for the token, and the server pushes the sealed `mesh_install` job once the device is
     * linked ({@see \RivetCore\Rmm\Mesh\MeshAfterApproval}). Tokens issued before rc.27 (option value 1) keep the old install-time Mesh block. It is
     * refused up front when the Mesh settings cannot support it (switched off, no https address, no device group id). The RivetIT agent never bundles a
     * Mesh binary and a failed Mesh install never fails the RivetIT install.
     *
     * $format is `exe` (default) or `msi`: with `msi` the precondition is a published MSI template for the architecture instead of a published agent executable;
     * the token and the payload are the same either way.
     *
     * $appIds are the catalog apps a technician ticked to install with the agent ({@see \RivetCore\Rmm\AutoInstall\AutoInstallService}); the caller has already checked the
     * `rmm.software.deploy` ability. They are stored on the token (additive table), count as explicit approval for the devices it enrolls, and who ticked what is audited.
     * Refused up front when software deployment is off, an app is unknown or switched off, or its vendor parameters have no value for this client.
     *
     * @param list<int> $appIds
     * @param string $deviceType 'auto' or a DeviceTypeClassifier label: stored by token (never in the installer file), audited, validated against the fixed list
     * @return array{ok:bool,error?:string,token?:array<string,mixed>,token_plain?:string,payload?:string,installer_id?:string,department?:string,mesh_after_approval?:bool}
     */
    public function issue(int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, string $arch, int $userId, string $actorName, bool $installMeshAgent = false, string $format = InstallerFormat::EXE, array $appIds = [], string $deviceType = 'auto'): array
    {
        if (!InstallerFormat::valid($format)) {
            return ['ok' => false, 'error' => 'Choose the installer format: exe or msi.'];
        }
        $refusal = $this->installer->preflight($arch, $format);
        if ($refusal !== null) {
            return ['ok' => false, 'error' => $refusal];
        }
        if ($installMeshAgent) {
            $why = $this->meshAgents === null ? 'The MeshCentral agent install is not available.' : $this->meshAgents->installRefusal();
            if ($why !== null) {
                return ['ok' => false, 'error' => $why];
            }
        }
        if ($deviceType !== 'auto' && !DeviceTypeClassifier::validLabel($deviceType)) {
            return ['ok' => false, 'error' => 'Choose a device type from the list, or leave it on automatic detection.'];
        }
        if ($clientId <= 0 || $this->tenancy->clientName($clientId) === null) {
            return ['ok' => false, 'error' => 'Choose the ' . $this->clientLabel . ' the devices belong to.'];
        }
        if ($locationId > 0 && !$this->tenancy->locationInClient($locationId, $clientId)) {
            $locationId = 0;
        }
        if ($appIds !== [] && $this->autoInstall === null) {
            return ['ok' => false, 'error' => 'Installing apps with the agent is not available on this module.'];
        }
        $installerId = JobService::uuid();
        $t = $this->enrollment->createToken($clientId, $locationId, $ring, $ttlHours, $maxUses, $label !== '' ? $label : 'Installer ' . substr($installerId, 0, 8), $userId);
        $row = $this->sql->one('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_id = ?', [$t['token_id']]);
        if ($row === null) {
            return ['ok' => false, 'error' => 'The enrollment token could not be created.'];
        }
        if ($installMeshAgent) {
            $this->sql->run('INSERT INTO endpoint_agent_installer_options (token_id, install_mesh_agent, created_at) VALUES (?, ' . \RivetCore\Rmm\Mesh\MeshAfterApproval::OPTION_AFTER_APPROVAL . ', ?) ON DUPLICATE KEY UPDATE install_mesh_agent = ' . \RivetCore\Rmm\Mesh\MeshAfterApproval::OPTION_AFTER_APPROVAL, [$t['token_id'], $this->sql->utcNow()]);
        }
        if ($deviceType !== 'auto') {
            try {
                $this->sql->run('INSERT INTO endpoint_agent_installer_options (token_id, device_type, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE device_type = VALUES(device_type)', [$t['token_id'], $deviceType, $this->sql->utcNow()]);
            } catch (\Throwable) {
                $this->enrollment->revokeToken($t['token_id'], $userId);   // migration 0035 not applied: never hand out an installer that would silently ignore the choice

                return ['ok' => false, 'error' => 'The device type option is not available until the database is updated.'];
            }
        }
        if ($appIds !== []) {
            $why = $this->autoInstall->recordSelection((int) $t['token_id'], $appIds, $clientId, $locationId, $userId, $actorName);
            if ($why !== null) {
                $this->enrollment->revokeToken($t['token_id'], $userId);

                return ['ok' => false, 'error' => $why];
            }
        }
        [$payload, $err] = $this->installer->payloadFor($row, $t['token'], $installerId);
        if ($payload === null) {
            $this->enrollment->revokeToken($t['token_id'], $userId);

            return ['ok' => false, 'error' => (string) $err];
        }
        $dept = $this->installer->departmentName($clientId);
        $this->audit->record('Installer Created', "$actorName created installer $installerId ($arch" . ($format === InstallerFormat::MSI ? ', msi' : '') . ") for {$this->clientLabel} \"$dept\" with enrollment token #{$t['token_id']} ({$row['token_selector']}), "
            . "{$row['max_uses']} uses, expires {$row['expires_at']} UTC" . ($installMeshAgent ? ', installs the MeshCentral agent after the device is approved' : '') . ($deviceType !== 'auto' ? ", new assets get the type \"$deviceType\"" : ''), $clientId, 0);

        return ['ok' => true, 'token' => $row, 'token_plain' => $t['token'], 'payload' => $payload, 'installer_id' => $installerId, 'department' => $dept, 'mesh_after_approval' => $installMeshAgent];
    }

    /**
     * The administrator's "download installer": {@see issue()} then the stamped base binary as a download response. When the file
     * cannot be served the token is revoked again (nothing was handed out, so no live token is left behind).
     *
     * @param list<int> $appIds see {@see issue()}
     * @return RmmResponse|string the download, or the refusal message
     */
    public function issueDownload(int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, string $arch, int $userId, string $actorName, bool $installMeshAgent = false, string $format = InstallerFormat::EXE, array $appIds = [], string $deviceType = 'auto'): RmmResponse|string
    {
        $r = $this->issue($clientId, $locationId, $ring, $ttlHours, $maxUses, $label, $arch, $userId, $actorName, $installMeshAgent, $format, $appIds, $deviceType);
        if (!$r['ok'] || !isset($r['token'], $r['payload'], $r['department'])) {
            return (string) ($r['error'] ?? 'The installer could not be created.');
        }
        $name = $this->installer->filename($r['department'], $arch, $format);
        if ($format === InstallerFormat::MSI) {
            // A COPY of the template with the payload appended: the stored file is only read.
            $tpl = $this->installer->msiTemplate($arch);
            $out = $tpl === null ? 'No MSI template is published for ' . $arch . '.' : $this->binaries->downloadMsi($tpl, $name, InstallerStamp::trailer($r['payload']));
        } else {
            $bin = $this->updates->currentBinary($arch);
            $out = $bin === null ? 'No agent binary is published for ' . $arch . '.'
                : $this->binaries->download($bin, $name, InstallerStamp::trailer($r['payload']));
        }
        if (is_string($out)) {
            $this->enrollment->revokeToken((int) $r['token']['token_id'], $userId);
        }

        return $out;
    }

    // ---------------------------------------------------------------- snippets

    /**
     * Everything the "show deployment commands" panel needs for an issued installer, or null when no service URL is set.
     *
     * @param array{token:array<string,mixed>,token_plain:string,department:string} $issued the result of {@see issue()}
     * @return array{server:string,filename:string,powershell:string,linux:string,detection:array{service:string,file:string},msi?:array{filename:string,powershell:string,msiexec:string,properties:string,uninstall:string,detection:array{product_name:string,upgrade_code:string}}}|null
     *         the `msi` member is present only for `$format = msi`
     */
    public function deploymentCommands(array $issued, string $arch, bool $installMeshAgent = false, string $format = InstallerFormat::EXE): ?array
    {
        $server = $this->updates->serviceBase();
        if ($server === null) {
            return null;
        }
        $out = [
            'server' => $server,
            'filename' => $this->installer->filename($issued['department'], $arch),
            'powershell' => self::powershellSnippet($server, $issued['token_plain'], $arch, $issued['department']),
            'linux' => self::linuxSnippet($server, $issued['token_plain'], $arch, $issued['department'], $this->installer->caPem()),   // never the Mesh address, group id or pin: the server pushes the Mesh install once the device is approved
            'detection' => ['service' => self::WINDOWS_SERVICE, 'file' => self::WINDOWS_INSTALLED_FILE],
            'mesh_after_approval' => $installMeshAgent,
        ];
        if ($format === InstallerFormat::MSI) {
            $name = $this->installer->filename($issued['department'], $arch, InstallerFormat::MSI);
            $out['msi'] = [
                'filename' => $name,
                'powershell' => self::powershellMsiSnippet($server, $issued['token_plain'], $arch, $issued['department']),
                'msiexec' => self::msiexecCommand($name),
                'properties' => self::msiPropertiesCommand($server, $issued['token_plain']),
                'uninstall' => self::MSI_UNINSTALL_POWERSHELL,
                'detection' => ['product_name' => 'RivetIT Agent', 'upgrade_code' => self::MSI_UPGRADE_CODE_AMD64],
            ];
        }

        return $out;
    }

    /** The UpgradeCode of the MSI template the agent build makes (a constant of packaging/msi/build.sh; an Intune detection rule or a GPO can match on it). */
    public const MSI_UPGRADE_CODE_AMD64 = '{364538BF-62D7-4685-84C5-43B83B318852}';
    /** Remove the MSI product (the ProductCode changes with every version, so it is looked up by name; Add/Remove Programs does the same). The Mesh agent stays unless REMOVE_MESHAGENT=1 is appended to the msiexec arguments. */
    public const MSI_UNINSTALL_POWERSHELL = "Get-ItemProperty 'HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*' | Where-Object { \$_.DisplayName -eq 'RivetIT Agent' -and \$_.WindowsInstaller -eq 1 } | ForEach-Object { Start-Process msiexec.exe -ArgumentList '/x', \$_.PSChildName, '/qn', '/norestart' -Wait }";

    /** msiexec for a stamped MSI (the payload is inside the file). Quiet, logged; the token is hidden from the log by the package itself. */
    public static function msiexecCommand(string $filename): string
    {
        return 'msiexec /i "' . self::cmdSafe($filename) . '" /qn /norestart /l*v "%TEMP%\\rivetit-agent-msi.log"';
    }

    /**
     * msiexec for the UNSTAMPED template with public properties: no payload in the file, the server and the enrollment token on the command line (use ENROLL_TOKEN_FILE
     * when a file with restricted rights is available; the token is a one-shot secret either way and is shown once). CA_PEM_FILE is optional.
     */
    public static function msiPropertiesCommand(string $serverUrl, string $token): string
    {
        return 'msiexec /i "rivetit-agent-windows-amd64.msi" /qn /norestart /l*v "%TEMP%\\rivetit-agent-msi.log" SERVER="' . self::cmdSafe(rtrim($serverUrl, '/')) . '" ENROLL_TOKEN="' . self::cmdSafe($token) . '" CA_PEM_FILE="C:\\path\\ca.pem"';
    }

    /** Only the characters of a file name or URL reach a command line; anything else becomes `?`. */
    private static function cmdSafe(string $v): string
    {
        return (string) preg_replace('/[^A-Za-z0-9 ._:\/-]+/', '?', $v);
    }

    /**
     * Unattended MSI deployment for an RMM, an Intune platform script or a GPO startup script: download the stamped MSI from the token-gated endpoint (format msi), install it
     * with msiexec, delete it. msiexec's exit code (0 or 3010) is the result.
     */
    public static function powershellMsiSnippet(string $serverUrl, string $token, string $arch, string $department): string
    {
        $arch = $arch === 'arm64' ? 'arm64' : 'amd64';
        $comment = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '?', $department));

        return "# RivetIT agent silent MSI install for client: $comment\n"
            . "# Run as SYSTEM or an administrator (RMM, Intune platform script, GPO startup script). The token is a secret: do not paste it into tickets or chat.\n"
            . "\$ErrorActionPreference = 'Stop'\n"
            . '$Server = ' . self::psQuote(rtrim($serverUrl, '/')) . "\n"
            . '$Token  = ' . self::psQuote($token) . "\n"
            . '$Arch   = ' . self::psQuote($arch) . "\n"
            . "\$Msi = Join-Path \$env:TEMP ('RivetIT-Agent-' + [guid]::NewGuid().ToString('N') + '.msi')\n"
            . "try {\n"
            . "    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12\n"
            . "    \$body = @{ token = \$Token; arch = \$Arch; format = 'msi' } | ConvertTo-Json -Compress\n"
            . "    Invoke-WebRequest -Uri (\$Server + '/api/v1/agent_installer') -Method Post -ContentType 'application/json' -Body \$body -OutFile \$Msi -UseBasicParsing\n"
            . "    \$p = Start-Process -FilePath 'msiexec.exe' -ArgumentList '/i', ('\"' + \$Msi + '\"'), '/qn', '/norestart' -Wait -PassThru\n"
            . "    if (\$p.ExitCode -ne 0 -and \$p.ExitCode -ne 3010) { throw \"RivetIT agent MSI install failed with exit code \$(\$p.ExitCode)\" }\n"
            . "    Write-Output 'RivetIT agent installed.'\n"
            . "} finally {\n"
            . "    Remove-Item -LiteralPath \$Msi -Force -ErrorAction SilentlyContinue\n"
            . "}\n";
    }

    /** A PowerShell single-quoted string literal. Control characters are refused outright. */
    public static function psQuote(string $v): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $v) === 1) {
            throw new \InvalidArgumentException('control character in a script value');
        }

        return "'" . str_replace("'", "''", $v) . "'";
    }

    /** A POSIX shell single-quoted word. Control characters are refused (a newline only when $allowNewline is set, for PEM text). */
    public static function shQuote(string $v, bool $allowNewline = false): string
    {
        $bad = $allowNewline ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
        if (preg_match($bad, $v) === 1) {
            throw new \InvalidArgumentException('control character in a script value');
        }

        return "'" . str_replace("'", "'\\''", $v) . "'";
    }

    /** Unattended deployment snippet for an RMM, an Intune platform script or a GPO startup script. */
    public static function powershellSnippet(string $serverUrl, string $token, string $arch, string $department): string
    {
        $arch = $arch === 'arm64' ? 'arm64' : 'amd64';
        $comment = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '?', $department));

        return "# RivetIT agent silent install for client: $comment\n"
            . "# Run as SYSTEM or an administrator (RMM, Intune platform script, GPO startup script). The token is a secret: do not paste it into tickets or chat.\n"
            . "\$ErrorActionPreference = 'Stop'\n"
            . '$Server = ' . self::psQuote(rtrim($serverUrl, '/')) . "\n"
            . '$Token  = ' . self::psQuote($token) . "\n"
            . '$Arch   = ' . self::psQuote($arch) . "\n"
            . "\$Exe = Join-Path \$env:TEMP ('RivetIT-Agent-Setup-' + [guid]::NewGuid().ToString('N') + '.exe')\n"
            . "try {\n"
            . "    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12\n"
            . "    \$body = @{ token = \$Token; arch = \$Arch } | ConvertTo-Json -Compress\n"
            . "    Invoke-WebRequest -Uri (\$Server + '/api/v1/agent_installer') -Method Post -ContentType 'application/json' -Body \$body -OutFile \$Exe -UseBasicParsing\n"
            . "    \$p = Start-Process -FilePath \$Exe -ArgumentList 'setup', '--silent' -Wait -PassThru\n"
            . "    if (\$p.ExitCode -ne 0) { throw \"RivetIT agent setup failed with exit code \$(\$p.ExitCode)\" }\n"
            . "    Write-Output 'RivetIT agent installed.'\n"
            . "} finally {\n"
            . "    Remove-Item -LiteralPath \$Exe -Force -ErrorAction SilentlyContinue\n"
            . "}\n";
    }

    /**
     * Install snippet for a Linux (systemd) host, to run as root next to `install-linux.sh` from the agent's release tarball
     * (`rivetit-agent-linux-<arch>.tar.gz`). The enrollment token reaches the script through a root-only temporary file, never a
     * command line, and the file is removed afterwards whatever the result. With $mesh (the installer's Mesh block) the script is also told to install the
     * MeshCentral agent from the customer's own Mesh server; that never changes the result of the RivetIT install.
     *
     * @param array<string,mixed>|null $mesh url, meshid, optional pin
     */
    public static function linuxSnippet(string $serverUrl, string $token, string $arch, string $department, ?string $caPem = null, ?array $mesh = null): string
    {
        $arch = $arch === 'arm64' ? 'arm64' : 'amd64';
        $comment = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '?', $department));
        $ca = $caPem !== null && trim($caPem) !== '';
        $s = "# RivetIT agent install (Linux, systemd) for client: $comment\n"
            . "# Run as root from the unpacked rivetit-agent-linux-$arch.tar.gz (it contains rivetit-agent and install-linux.sh). The token is a secret: do not paste it into tickets or chat.\n"
            . "set -eu\numask 077\n"
            . 'T="$(mktemp)"' . "\n"
            . ($ca ? 'C="$(mktemp)"' . "\n" : '')
            . 'trap \'rm -f "$T"' . ($ca ? ' "$C"' : '') . '\' EXIT' . "\n"
            . 'printf \'%s\' ' . self::shQuote($token) . ' > "$T"' . "\n"
            . ($ca ? 'printf \'%s\' ' . self::shQuote(rtrim((string) $caPem) . "\n", true) . ' > "$C"' . "\n" : '')
            . './install-linux.sh --server ' . self::shQuote(rtrim($serverUrl, '/')) . ' --token-file "$T"' . ($ca ? ' --ca "$C"' : '')
            . ($mesh !== null && is_string($mesh['url'] ?? null) && is_string($mesh['meshid'] ?? null)
                ? ' --install-mesh-agent --mesh-url ' . self::shQuote($mesh['url']) . ' --mesh-group-id ' . self::shQuote($mesh['meshid']) . (is_string($mesh['pin'] ?? null) && $mesh['pin'] !== '' ? ' --mesh-cert-pin ' . self::shQuote($mesh['pin']) : '')
                : '')
            . "\n";

        return $s;
    }

    // ---------------------------------------------------------------- CA certificate

    /**
     * Normalise and validate pasted PEM certificates (one to five, at most 8000 characters).
     *
     * @return array{0:?string,1:?string} [normalised PEM or null (empty input clears the setting), error]
     */
    public static function normalizeCa(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [null, null];
        }
        if (strlen($text) > 8000) {
            return [null, 'The CA certificate text is too long (at most 8000 characters).'];
        }
        if (preg_match_all('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $text, $m) === 0 || count($m[1]) > 5) {
            return [null, 'Paste one to five PEM certificates (-----BEGIN CERTIFICATE----- ... -----END CERTIFICATE-----).'];
        }
        $out = [];
        foreach ($m[1] as $b64) {
            $b64 = (string) preg_replace('/\s+/', '', $b64);
            $der = base64_decode($b64, true);
            if ($der === false || $der === '') {
                return [null, 'A certificate is not valid base64.'];
            }
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64, 64, "\n") . "-----END CERTIFICATE-----\n";
            if (function_exists('openssl_x509_read') && @openssl_x509_read($pem) === false) {
                return [null, 'A pasted certificate could not be parsed.'];
            }
            $out[] = $pem;
        }

        return [implode('', $out), null];
    }
}
