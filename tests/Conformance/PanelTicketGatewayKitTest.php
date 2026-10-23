<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;
use RivetCore\Testing\InMemoryPanelTicketGateway;
use RivetCore\Testing\PanelTicketGatewayConformanceTestCase;

/** The kit against the in-memory reference gateway. */
final class PanelTicketGatewayKitTest extends PanelTicketGatewayConformanceTestCase
{
    private ?InMemoryPanelTicketGateway $gw = null;
    private int $client = 0;
    /** @var array<int,int> */
    private array $clients = [];

    private function gw(): InMemoryPanelTicketGateway
    {
        return $this->gw ??= new InMemoryPanelTicketGateway();
    }

    protected function gateway(): PanelTicketGatewayInterface
    {
        return $this->gw();
    }

    protected function createClient(string $name): int
    {
        return ++$this->client;
    }

    protected function createContact(int $clientId, string $name): int
    {
        return $this->gw()->addClientContact($clientId, $name);
    }

    protected function archiveContact(int $contactId): void
    {
        $this->gw()->archiveContact($contactId);
    }

    protected function createCategory(int $clientId, string $name): int
    {
        return $this->gw()->addCategory($clientId, $name);
    }

    protected function addStaffReply(int $ticketId, string $body): void
    {
        $this->gw()->addStaffReply($ticketId, $body);
    }

    protected function addInternalNote(int $ticketId, string $body): void
    {
        $this->gw()->addInternalNote($ticketId, $body);
    }
}
