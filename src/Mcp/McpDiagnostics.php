<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

use GuzzleHttp\ClientInterface;

/**
 * "Is this actually going to work?" checks for Administration > Remote MCP. Each check returns
 * status ok|warn|fail|skip, a short label, and a plain-language detail with the fix. Network access goes
 * through an injected Guzzle client so the checks can be tested without a real identity provider.
 *
 * @api
 */
final class McpDiagnostics
{
    /** @param string $envVarName shown to the admin when the kill switch is set, e.g. MYAPP_MCP_ENABLED */
    public function __construct(private AgentDirectoryInterface $agents, private ClientInterface $http, private string $envVarName = 'MCP_ENABLED', private string $appName = 'this app') {}

    private static function check(string $status, string $label, string $detail): array
    {
        return ['status' => $status, 'label' => $label, 'detail' => $detail];
    }

    private function getJson(string $url): array
    {
        $res = $this->http->request('GET', $url, ['timeout' => 5, 'allow_redirects' => false, 'http_errors' => false, 'headers' => ['Accept' => 'application/json']]);
        if ($res->getStatusCode() !== 200) throw new \RuntimeException('HTTP ' . $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);
        if (!is_array($data)) throw new \RuntimeException('not JSON');
        return $data;
    }

    /** @return list<array{status:string,label:string,detail:string}> */
    public function run(array $cfg, string $baseHost): array
    {
        $out = [];
        $out[] = $cfg['killed']
            ? self::check('fail', 'Remote MCP switched on', 'The server has ' . $this->envVarName . '=0 set, which overrides this page. Remove it and reload PHP-FPM.')
            : ($cfg['module_on'] ? self::check('ok', 'Remote MCP switched on', 'Enabled in settings.') : self::check('warn', 'Remote MCP switched on', 'Turned off. Switch it on above when you are ready.'));

        $issuerOk = McpConfig::issuerValid($cfg['issuer']);
        $out[] = $issuerOk ? self::check('ok', 'Issuer URL', 'A valid HTTPS issuer.')
            : self::check('fail', 'Issuer URL', 'Enter the issuer exactly as your identity provider lists it. It must be an https:// address.');
        $out[] = McpConfig::audienceValid($cfg['audience']) ? self::check('ok', 'Audience', 'Set.')
            : self::check('fail', 'Audience', 'Enter the audience. In Authentik this is the provider\'s Client ID.');

        $doc = null;
        if ($issuerOk) {
            try {
                $doc = $this->getJson(rtrim($cfg['issuer'], '/') . '/.well-known/openid-configuration');
                $out[] = self::check('ok', 'Identity provider reachable', 'The discovery document loaded.');
            } catch (\Throwable $e) {
                $out[] = self::check('fail', 'Identity provider reachable', 'Could not load its discovery document (' . $e->getMessage() . '). Check the issuer and that this server can reach it over HTTPS.');
            }
        }
        if ($doc !== null) {
            $out[] = ($doc['issuer'] ?? null) === $cfg['issuer']
                ? self::check('ok', 'Issuer matches exactly', 'The provider reports the same issuer you entered.')
                : self::check('fail', 'Issuer matches exactly', 'The provider reports "' . substr((string) ($doc['issuer'] ?? 'nothing'), 0, 200) . '". Copy it character for character, including any trailing slash.');

            $jwks = (string) ($doc['jwks_uri'] ?? '');
            if (!McpConfig::issuerValid($jwks)) {
                $out[] = self::check('fail', 'Signing keys', 'The provider lists no usable HTTPS signing-key address.');
            } else {
                try {
                    $keys = $this->getJson($jwks)['keys'] ?? [];
                    $rsa = array_filter(is_array($keys) ? $keys : [], static fn($k) => is_array($k) && ($k['kty'] ?? '') === 'RSA' && (($k['use'] ?? 'sig') === 'sig'));
                    $out[] = $rsa ? self::check('ok', 'Signing keys', count($rsa) . ' RSA signing key(s) found (RS256).')
                        : self::check('fail', 'Signing keys', 'No RSA signing key. In Authentik, set a Signing Key on the provider; without one it signs with HS256, which ' . $this->appName . ' rejects.');
                } catch (\Throwable $e) {
                    $out[] = self::check('fail', 'Signing keys', 'Could not load the signing keys (' . $e->getMessage() . ').');
                }
            }
            $methods = $doc['code_challenge_methods_supported'] ?? null;
            $out[] = (is_array($methods) && in_array('S256', $methods, true)) ? self::check('ok', 'PKCE supported', 'S256 is advertised.')
                : self::check('warn', 'PKCE supported', 'The provider does not advertise S256. Most MCP clients require PKCE.');
            $scopes = $doc['scopes_supported'] ?? null;
            $out[] = (is_array($scopes) && in_array('mcp:read', $scopes, true)) ? self::check('ok', 'mcp:read scope', 'The provider offers it.')
                : self::check('warn', 'mcp:read scope', 'Not listed by the provider. Create a scope mapping named mcp:read and select it on the provider.');
        }

        if ($cfg['enabled'] && $cfg['configured']) {
            $out[] = $this->routeCheck($baseHost, $cfg['issuer']);
        } else {
            $out[] = self::check('skip', 'This server answers /mcp', 'Checked once it is switched on and configured.');
        }

        $mapped = $this->agents->linkedCount();
        $out[] = $mapped > 0 ? self::check('ok', 'Linked agents', "$mapped agent(s) linked.")
            : self::check('warn', 'Linked agents', 'No agent is linked yet. Connect once from your MCP client, then link the person below.');
        return $out;
    }

    private function routeCheck(string $host, string $issuer): array
    {
        $label = 'This server answers /mcp';
        try {
            $meta = $this->http->request('GET', "https://$host/.well-known/oauth-protected-resource", ['timeout' => 5, 'allow_redirects' => false, 'http_errors' => false]);
            if ($meta->getStatusCode() === 404) {
                return self::check('fail', $label, 'The discovery address returns 404. Add the /mcp routes to your web server (see "Web server setup" below).');
            }
            $data = json_decode((string) $meta->getBody(), true);
            if ($meta->getStatusCode() !== 200 || !in_array($issuer, (array) ($data['authorization_servers'] ?? []), true)) {
                return self::check('warn', $label, 'The discovery address answered, but did not name your issuer (HTTP ' . $meta->getStatusCode() . ').');
            }
            $mcp = $this->http->request('POST', "https://$host/mcp", ['timeout' => 5, 'allow_redirects' => false, 'http_errors' => false, 'body' => '{}', 'headers' => ['Content-Type' => 'application/json']]);
            return $mcp->getStatusCode() === 401 && $mcp->hasHeader('WWW-Authenticate')
                ? self::check('ok', $label, '/mcp challenges unsigned requests and points clients at your identity provider.')
                : self::check('warn', $label, '/mcp answered HTTP ' . $mcp->getStatusCode() . ' to an unsigned request; expected 401 with a challenge.');
        } catch (\Throwable $e) {
            return self::check('warn', $label, 'This server could not reach its own public address (' . $e->getMessage() . '). Test from another machine.');
        }
    }
}
