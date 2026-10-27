<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemorySecretBox;

/** The reference secret box with an optional named flaw: plain_in_cipher, throws_garbage, garbage_echo, lossy, unauthenticated, fixed_output. */
final class FlawedSecretBox extends InMemorySecretBox
{
    public function __construct(private ?string $flaw = null)
    {
        parent::__construct();
    }

    public function encrypt(string $plaintext): string
    {
        return match ($this->flaw) {
            'plain_in_cipher' => 'enc:' . $plaintext,
            'lossy' => parent::encrypt(trim($plaintext)),
            'unauthenticated' => base64_encode($plaintext),
            'fixed_output' => 'AAAA',
            default => parent::encrypt($plaintext),
        };
    }

    public function decrypt(string $ciphertext): string
    {
        switch ($this->flaw) {
            case 'plain_in_cipher':
                return str_starts_with($ciphertext, 'enc:') ? substr($ciphertext, 4) : '';
            case 'throws_garbage':
                $out = parent::decrypt($ciphertext);
                if ($out === '' && $ciphertext !== '') {
                    throw new \RuntimeException('cannot decrypt');
                }

                return $out;
            case 'garbage_echo':
                $out = parent::decrypt($ciphertext);

                return $out === '' ? $ciphertext : $out;
            case 'unauthenticated':
                $d = base64_decode($ciphertext);

                return $d === false ? '' : $d;
            case 'fixed_output':
                return 'x';
            default:
                return parent::decrypt($ciphertext);
        }
    }
}
