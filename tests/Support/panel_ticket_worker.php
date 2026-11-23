<?php

declare(strict_types=1);

/**
 * One racer of tests/Integration/Panel/PanelTicketsTest.php (CORE-API-8): waits until argv[5] (a unix time with microseconds), then creates argv[6] tickets for the panel
 * device argv[1] (client argv[2], contact argv[3]) through PanelService::createTicket() with a daily cap of argv[4], on the scratch database named by RIVETCORE_TEST_DB_*,
 * each with a fresh client_request_id. Prints one HTTP-like status per attempt: 201 created, 429 refused by the cap, or `E:<message>` for anything else.
 */

use RivetCore\Panel\PanelLimits;
use RivetCore\Panel\PanelPrincipal;
use RivetCore\Panel\PanelService;
use RivetCore\Panel\PanelSettings;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Support\SystemClock;
use RivetCore\Testing\InMemoryPanelTicketGateway;
use RivetCore\Testing\InMemoryRmmAudit;
use RivetCore\Testing\InMemoryRmmTenancy;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$deviceId, $clientId, $contactId, $cap, $startAt, $tries] = [(int) ($argv[1] ?? 0), (int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0), (int) ($argv[4] ?? 0), (float) ($argv[5] ?? 0), (int) ($argv[6] ?? 1)];
$m = ScratchDb::connect();
if ($m === null || !str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch') || $deviceId <= 0 || $cap <= 0) {
    fwrite(STDERR, "refused\n");
    exit(2);
}
$sql = new Sql(new MysqliDatabase($m), new SystemClock());
$audit = new InMemoryRmmAudit();
// ticket ids must not collide between the racing processes (panel_ticket_owner has a unique key on ticket_id)
$gateway = new class (getmypid()) extends InMemoryPanelTicketGateway {
    public function __construct(int $pid)
    {
        $this->nextId = 1_000_000 + $pid * 100;
    }
};
$service = new PanelService($sql, $gateway, new PanelSettings($sql, $audit), new InMemoryRmmTenancy(), $audit, new PanelLimits(ticketsPerDayMax: $cap));
$p = new PanelPrincipal($deviceId, $clientId, $contactId, 'racer');
while (microtime(true) < $startAt) {
    usleep(100);
}
for ($i = 0; $i < $tries; ++$i) {
    try {
        $service->createTicket($p, ['subject' => 's', 'body' => 'b', 'client_request_id' => sprintf('%08x-0000-4000-8000-%012x', getmypid(), $i)]);
        echo "201\n";
    } catch (ApiError $e) {
        echo $e->http, "\n";
    } catch (\Throwable $e) {
        echo 'E:', get_class($e), ' ', str_replace("\n", ' ', $e->getMessage()), "\n";
    }
}
