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

    /** A whole HTML tag, with quoted attribute values (which may contain ">") kept inside it. */
    private const TAG_PATTERN = '/(<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)/';

    /**
     * Tokens in text are swapped for the badge; a token inside a tag (an attribute value, a tag name) is removed instead, so
     * badge markup can never break out of an attribute and alter the DOM.
     */
    public function render(string $html): string
    {
        $parts = preg_split(self::TAG_PATTERN, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        foreach ($parts as $i => $part) {
            $parts[$i] = $i % 2 === 1
                ? (preg_replace(self::TOKEN_PATTERN, '', $part) ?? '')
                : (preg_replace_callback(self::TOKEN_PATTERN, fn (array $m) => ($this->badge)((int) $m[1]), $part) ?? '');
        }

        return implode('', $parts);
    }

    public function containsReference(string $html): bool
    {
        return preg_match(self::TOKEN_PATTERN, $html) === 1;
    }
}
