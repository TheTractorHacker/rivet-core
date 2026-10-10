<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\EnvelopeInterface;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyUnavailable;
use RivetCore\Crypto\UnknownKeyId;

/**
 * The reference envelope with one named flaw: ignores_aad, static_nonce, fail_open, swallow_errors, wrong_exception, unknown_kid_fallback,
 * rewrap_noop, needs_rewrap_never, no_legacy, empty_context_ok, no_kid, plaintext_in_output.
 */
final class FlawedEnvelope implements EnvelopeInterface
{
    private Envelope $inner;

    /** @param list<\RivetCore\Crypto\LegacyReader> $legacy */
    public function __construct(private ?string $flaw, private KeyRing $ring, array $legacy = [])
    {
        $nonce = $flaw === 'static_nonce' ? static fn (): string => str_repeat("\x07", 12) : null;
        $this->inner = new Envelope($ring, $flaw === 'no_legacy' ? [] : $legacy, $nonce);
    }

    public function seal(string $plaintext, string $context): string
    {
        if ($this->flaw === 'fail_open' && !$this->ring->hasActive()) {
            return $plaintext;
        }
        if ($this->flaw === 'empty_context_ok' && $context === '') {
            $context = '-';
        }
        $sealed = $this->inner->seal($plaintext, $this->flaw === 'ignores_aad' ? 'fixed' : $context);
        if ($this->flaw === 'no_kid') {
            return 'v3:' . substr($sealed, 3 + strlen((string) $this->ring->activeKid()) + 1);
        }
        if ($this->flaw === 'plaintext_in_output') {
            return $sealed . ':' . bin2hex($plaintext);
        }

        return $sealed;
    }

    public function open(string $stored, string $context): string
    {
        if ($this->flaw === 'empty_context_ok' && $context === '') {
            $context = '-';
        }
        try {
            return $this->inner->open($stored, $this->flaw === 'ignores_aad' ? 'fixed' : $context);
        } catch (UnknownKeyId $e) {
            if ($this->flaw === 'unknown_kid_fallback') {
                throw new DecryptionFailed('x');
            }
            throw $e;
        } catch (DecryptionFailed|KeyUnavailable $e) {
            if ($this->flaw === 'swallow_errors') {
                return '';
            }
            if ($this->flaw === 'wrong_exception') {
                throw new \RuntimeException('bad');
            }
            throw $e;
        }
    }

    public function needsRewrap(string $stored): bool
    {
        return $this->flaw === 'needs_rewrap_never' ? false : $this->inner->needsRewrap($stored);
    }

    public function rewrap(string $stored, string $context): string
    {
        return $this->flaw === 'rewrap_noop' ? $stored : $this->inner->rewrap($stored, $context);
    }

    public function label(string $stored): string
    {
        return $this->inner->label($stored);
    }
}
