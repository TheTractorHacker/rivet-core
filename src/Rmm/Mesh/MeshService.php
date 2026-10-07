<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Webhooks\UrlPolicy;

/**
 * MeshCentral remote access: URL validation, node-id validation, the reachability probe and the launch of one remote session.
 *
 * AUTH MODEL. MeshCentral supports "login tokens" for embedding it in another application: the server holds one loginTokenKey, an
 * integrator builds a token for a MeshCentral user from that key and opens `https://<server>/?login=<token>` (see {@see MeshCookie}).
 * Because that key can sign in as ANY MeshCentral user it is stored encrypted (SecretBoxInterface) and is used only to impersonate ONE
 * limited MeshCentral account chosen by the administrator (default "rivetit-support"). The token is minted at the moment of the click and
 * is never stored or logged; only a random session id is.
 *
 * The probe goes through the injected {@see UrlPolicy}: an address that resolves to a private, loopback, link-local or metadata
 * address is refused unless the policy allows it, and the connection is pinned to the vetted addresses (no DNS rebinding).
 *
 * This service could not be exercised against a real MeshCentral server, only against tests/Support/MockMeshCentral.php (see
 * docs/modules/rmm.md, "What is verified").
 *
 * @api
 */
final class MeshService
{
    public const PROBE_TIMEOUT_S = 4;
    public const PROBE_CONNECT_TIMEOUT_S = 3;
    public const POLICY_REFUSAL = 'The MeshCentral address is not allowed by the network policy (see Administration > Webhooks > Internal network access).';
    public const UNREACHABLE = 'MeshCentral did not answer (timeout or error). Try again in a moment; if it keeps failing, check the MeshCentral server.';

    /**
     * @param bool $allowInsecureHttp accept an http:// MeshCentral address (loopback test servers only, never production)
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly DeviceRepository $devices,
        private readonly SecretBoxInterface $box,
        private readonly UrlPolicy $urlPolicy,
        private readonly bool $allowInsecureHttp = false,
    ) {
    }

    public static function validNodeId(string $id): bool
    {
        return DeviceRepository::validMeshNodeId($id);
    }

    /** Validates and normalises the MeshCentral base URL: https (http only when the module allows it), no userinfo, query or fragment. */
    public function normalizeUrl(string $url): ?string
    {
        return self::normalizeUrlWith($url, $this->allowInsecureHttp);
    }

    public static function normalizeUrlWith(string $url, bool $allowInsecureHttp): ?string
    {
        $url = rtrim(trim($url), '/');
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $scheme = strtolower($p['scheme'] ?? '');
        if ($scheme !== 'https' && !($scheme === 'http' && $allowInsecureHttp)) {
            return null;
        }
        if (strlen($url) > 500 || preg_match('/[\x00-\x20"<>\\\\^`{|}]/', $url) === 1) {
            return null;
        }

        return $url;
    }

    /** The MeshCentral node mapped to a device, or null. */
    public function nodeFor(int $deviceId): ?string
    {
        $n = $this->sql->val('SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$deviceId]);

        return $n === null ? null : (string) $n;
    }

    /**
     * Build a launch for one device. Every precondition is enforced HERE (not in the callers) so the web handler and the REST API
     * cannot diverge. The caller has already authorized the user for this device.
     *
     * @param array<string,mixed> $dev the device row
     * @return array{ok:bool,code:string,message:string,url?:string,session_id?:string}
     */
    public function launch(array $dev, string $username, int $userId, bool $force = false): array
    {
        $cfg = $this->settings->get();
        if ((int) $cfg['mesh_enabled'] !== 1 || (string) $cfg['mesh_url'] === '' || empty($cfg['mesh_login_key_enc'])) {
            return self::fail('not_configured', 'Remote access through MeshCentral is not configured.');
        }
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
            return self::fail('device_retired', 'This device is retired or revoked.');
        }
        $node = $this->nodeFor((int) $dev['device_id']);
        if ($node === null) {
            return self::fail('unmapped', 'This device is not mapped to a MeshCentral node yet. An administrator can map it on the device page.');
        }
        $st = $this->devices->status($dev, $cfg);
        if ($st['state'] !== 'online' && !$force) {
            return self::fail('device_offline', 'The device has not checked in since ' . ($st['last_checkin_at'] ?? 'it was enrolled') . '. It looks offline; try again later or launch anyway.');
        }
        $base = $this->normalizeUrl((string) $cfg['mesh_url']);
        if ($base === null) {
            return self::fail('not_configured', 'The MeshCentral address is not valid.');
        }
        $health = $this->probe($base);
        if ($health !== null) {
            return self::fail('mesh_unavailable', $health);
        }
        $keyHex = $this->box->decrypt((string) $cfg['mesh_login_key_enc']);
        $domain = (string) $cfg['mesh_domain'];
        $account = MeshCookie::accountName((string) $cfg['mesh_account_template'], $userId, $username);
        if ($keyHex === '' || $account === '') {
            return self::fail('not_configured', 'The MeshCentral login key or account is missing.');
        }
        $cookie = MeshCookie::login($keyHex, $domain, $account, $this->sql->time());
        if ($cookie === null) {
            return self::fail('not_configured', 'The MeshCentral login key is not usable.');
        }

        return ['ok' => true, 'code' => 'ok', 'message' => 'Opening the remote session.', 'url' => MeshCookie::launchUrl($base, $domain, $cookie, $node), 'session_id' => bin2hex(random_bytes(8))];
    }

    /**
     * @return array{ok:bool,code:string,message:string}
     */
    private static function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    /**
     * Reachability probe: GET `<base>/health.ashx` with the connection pinned to the addresses the network policy vetted, a short
     * timeout and no redirects. Returns an error text or null when MeshCentral answered 2xx/3xx.
     */
    public function probe(string $base): ?string
    {
        $target = null;
        try {
            $target = $this->urlPolicy->vet($base);
        } catch (\Throwable) {
            $target = null;
        }
        if ($target === null) {
            return self::POLICY_REFUSAL;
        }
        $url = rtrim($base, '/') . '/health.ashx';
        $ch = curl_init($url);
        if ($ch === false) {
            return self::UNREACHABLE;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::PROBE_TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => self::PROBE_CONNECT_TIMEOUT_S,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->allowInsecureHttp ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // A proxy would resolve the name itself and bypass the pin: the probe always goes direct.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];
        if ($target['ips'] !== [] && filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
            $opts[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . implode(',', $target['ips'])];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);   // the handle is freed here (curl_close() has had no effect since PHP 8.0 and is deprecated in 8.5)

        return $code >= 200 && $code < 400 ? null : self::UNREACHABLE;
    }
}
