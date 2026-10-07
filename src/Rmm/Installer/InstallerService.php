<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Installer;

use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Job\JobService;
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
    ) {
    }

    // ---------------------------------------------------------------- issuing

    /**
     * Create the enrollment token and the stamp payload for a client. Nothing is created when a precondition fails.
     *
     * @return array{ok:bool,error?:string,token?:array<string,mixed>,token_plain?:string,payload?:string,installer_id?:string,department?:string}
     */
    public function issue(int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, string $arch, int $userId, string $actorName): array
    {
        $refusal = $this->installer->preflight($arch);
        if ($refusal !== null) {
            return ['ok' => false, 'error' => $refusal];
        }
        if ($clientId <= 0 || $this->tenancy->clientName($clientId) === null) {
            return ['ok' => false, 'error' => 'Choose the client the devices belong to.'];
        }
        if ($locationId > 0 && !$this->tenancy->locationInClient($locationId, $clientId)) {
            $locationId = 0;
        }
        $installerId = JobService::uuid();
        $t = $this->enrollment->createToken($clientId, $locationId, $ring, $ttlHours, $maxUses, $label !== '' ? $label : 'Installer ' . substr($installerId, 0, 8), $userId);
        $row = $this->sql->one('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_id = ?', [$t['token_id']]);
        if ($row === null) {
            return ['ok' => false, 'error' => 'The enrollment token could not be created.'];
        }
        [$payload, $err] = $this->installer->payloadFor($row, $t['token'], $installerId);
        if ($payload === null) {
            $this->enrollment->revokeToken($t['token_id'], $userId);

            return ['ok' => false, 'error' => (string) $err];
        }
        $dept = $this->installer->departmentName($clientId);
        $this->audit->record('Installer Created', "$actorName created installer $installerId ($arch) for client \"$dept\" with enrollment token #{$t['token_id']} ({$row['token_selector']}), "
            . "{$row['max_uses']} uses, expires {$row['expires_at']} UTC", $clientId, 0);

        return ['ok' => true, 'token' => $row, 'token_plain' => $t['token'], 'payload' => $payload, 'installer_id' => $installerId, 'department' => $dept];
    }

    /**
     * The administrator's "download installer": {@see issue()} then the stamped base binary as a download response. When the file
     * cannot be served the token is revoked again (nothing was handed out, so no live token is left behind).
     *
     * @return RmmResponse|string the download, or the refusal message
     */
    public function issueDownload(int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, string $arch, int $userId, string $actorName): RmmResponse|string
    {
        $r = $this->issue($clientId, $locationId, $ring, $ttlHours, $maxUses, $label, $arch, $userId, $actorName);
        if (!$r['ok'] || !isset($r['token'], $r['payload'], $r['department'])) {
            return (string) ($r['error'] ?? 'The installer could not be created.');
        }
        $bin = $this->updates->currentBinary($arch);
        $out = $bin === null ? 'No agent binary is published for ' . $arch . '.'
            : $this->binaries->download($bin, $this->installer->filename($r['department'], $arch), InstallerStamp::trailer($r['payload']));
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
     * @return array{server:string,filename:string,powershell:string,linux:string,detection:array{service:string,file:string}}|null
     */
    public function deploymentCommands(array $issued, string $arch): ?array
    {
        $server = $this->updates->serviceBase();
        if ($server === null) {
            return null;
        }

        return [
            'server' => $server,
            'filename' => $this->installer->filename($issued['department'], $arch),
            'powershell' => self::powershellSnippet($server, $issued['token_plain'], $arch, $issued['department']),
            'linux' => self::linuxSnippet($server, $issued['token_plain'], $arch, $issued['department'], $this->installer->caPem()),
            'detection' => ['service' => self::WINDOWS_SERVICE, 'file' => self::WINDOWS_INSTALLED_FILE],
        ];
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
     * command line, and the file is removed afterwards whatever the result.
     */
    public static function linuxSnippet(string $serverUrl, string $token, string $arch, string $department, ?string $caPem = null): string
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
            . './install-linux.sh --server ' . self::shQuote(rtrim($serverUrl, '/')) . ' --token-file "$T"' . ($ca ? ' --ca "$C"' : '') . "\n";

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
