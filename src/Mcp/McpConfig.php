<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

use RivetCore\Contracts\SettingsInterface;

/**
 * Remote MCP settings. The administrator configures them in the edition's admin (module switch, issuer and
 * audience live in settings). Environment variables named <PREFIX>ISSUER / <PREFIX>AUDIENCE still win when
 * set, and <PREFIX>ENABLED=0 is a hard off switch that no setting can undo.
 *
 * Settings keys read through SettingsInterface: mcp.issuer, mcp.audience, mcp.enabled (the module switch).
 * A null `mcp.issuer` means the schema does not have the setting yet (treated as not configured).
 */
final class McpConfig
{
    public static function issuerValid(string $issuer): bool
    {
        $p = parse_url($issuer);

        return strlen($issuer) <= 255 && is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host'])
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
    }

    public static function audienceValid(string $audience): bool
    {
        return $audience !== '' && strlen($audience) <= 255 && !preg_match('/[\x00-\x20]/', $audience);
    }

    /**
     * @param callable(string):(string|false|null) $env reads an environment variable (getenv-like); false/null = unset
     * @return array{schema_ready:bool, module_on:bool, killed:bool, enabled:bool, issuer:string, audience:string, issuer_from_env:bool, audience_from_env:bool, configured:bool}
     */
    public static function resolve(SettingsInterface $settings, callable $env, string $envPrefix): array
    {
        $read = static fn (string $name): string => trim((string) ($env($envPrefix . $name) ?: ''));
        $envIssuer = $read('ISSUER');
        $envAudience = $read('AUDIENCE');
        $issuer = $envIssuer !== '' ? $envIssuer : trim((string) $settings->get('mcp.issuer', ''));
        $audience = $envAudience !== '' ? $envAudience : trim((string) $settings->get('mcp.audience', ''));
        $moduleOn = (int) $settings->get('mcp.enabled', 0) === 1;
        $killed = $env($envPrefix . 'ENABLED') === '0';

        return [
            'schema_ready' => $settings->get('mcp.issuer') !== null,
            'module_on' => $moduleOn,
            'killed' => $killed,
            'enabled' => $moduleOn && !$killed,
            'issuer' => $issuer,
            'audience' => $audience,
            'issuer_from_env' => $envIssuer !== '',
            'audience_from_env' => $envAudience !== '',
            'configured' => self::issuerValid($issuer) && self::audienceValid($audience),
        ];
    }
}
