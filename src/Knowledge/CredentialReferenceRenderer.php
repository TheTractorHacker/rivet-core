<?php

declare(strict_types=1);

namespace RivetCore\Knowledge;

/**
 * Secure credential references in knowledge-base articles. Convention: an article body may contain the literal
 * token "[[credential:123]]" where 123 is a credential id. This class only swaps that token, once an article's
 * already-purified HTML is about to be displayed, for whatever the edition renders as a "reveal" control - it
 * never looks the credential up, never decrypts anything, and the token is never rewritten in storage (the
 * stored article and every version snapshot keep the raw text forever, only the display copy changes). The
 * edition's reveal screen must re-check permissions itself; this class has no opinion on who may see a secret.
 *
 * @api
 */
class CredentialReferenceRenderer
{
    private const TOKEN_PATTERN = '/\[\[credential:(\d+)\]\]/i';

    /** @param \Closure(int):string $badge renders the edition's reveal control for a credential id */
    public function __construct(private \Closure $badge)
    {
    }

    public function render(string $html): string
    {
        return preg_replace_callback(
            self::TOKEN_PATTERN,
            fn (array $m) => ($this->badge)((int) $m[1]),
            $html
        );
    }

    public function containsReference(string $html): bool
    {
        return preg_match(self::TOKEN_PATTERN, $html) === 1;
    }
}
