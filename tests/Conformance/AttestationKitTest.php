<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Compliance\AttestationProviderInterface;
use RivetCore\Testing\AttestationProviderConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\InMemoryAttestationProvider;

/** The kit against an in-memory attestation log (and the harness target for the attestation mutants). */
final class AttestationKitTest extends AttestationProviderConformanceTestCase
{
    use Flaw;

    private ?InMemoryAttestationProvider $store = null;

    private function store(): InMemoryAttestationProvider
    {
        return $this->store ??= new InMemoryAttestationProvider(self::$flaw);
    }

    protected function provider(): AttestationProviderInterface
    {
        return $this->store();
    }

    protected function storeAttestation(string $itemId, string $reviewedOn, ?string $nextDueOn, string $reviewer, ?string $note): void
    {
        $this->store()->record($itemId, $reviewedOn, $nextDueOn, $reviewer, $note);
    }

    protected function deleteAttestations(array $itemIds): void
    {
        $this->store()->purge($itemIds);
    }
}
