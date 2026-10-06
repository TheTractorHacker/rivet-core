<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

/**
 * Extra checks on an access token that already passed signature, issuer and expiry validation: the MCP
 * audience must be the token's only recipient, the required scope must be present, and the lifetime must be
 * sane (issued no more than 60s in the future, expiry after issue, at most one hour long).
 *
 * @api
 */
final class TokenClaimsGuard
{
    /** The MCP audience must be the token's only intended recipient. */
    public static function hasDedicatedAudience(array $claims, string $audience): bool
    {
        $tokenAudience = $claims['aud'] ?? null;

        return $tokenAudience === $audience || $tokenAudience === [$audience];
    }

    public static function acceptable(mixed $subject, mixed $scopes, mixed $claims, string $audience, string $requiredScope = 'mcp:read', ?int $now = null): bool
    {
        $now ??= time();

        return is_string($subject) && $subject !== '' && strlen($subject) <= 255
            && is_array($scopes) && in_array($requiredScope, $scopes, true)
            && is_array($claims) && self::hasDedicatedAudience($claims, $audience)
            && is_int($claims['exp'] ?? null)
            && is_int($claims['iat'] ?? null)
            && $claims['iat'] <= $now + 60
            && $claims['exp'] > $claims['iat']
            && $claims['exp'] - $claims['iat'] <= 3600;
    }
}
