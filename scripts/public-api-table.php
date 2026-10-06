<?php

declare(strict_types=1);

/*
 * Prints the per-type table for docs/PUBLIC-API.md from tests/api-surface.json (types, kind, who calls or implements it,
 * proposed stability, owning module). The stability column is a PROPOSAL held in the map below, for the owner to confirm.
 *   php scripts/public-api-table.php            print
 *   php scripts/public-api-table.php --write    replace the block between the markers in docs/PUBLIC-API.md
 */

$snap = json_decode((string) file_get_contents(__DIR__ . '/../tests/api-surface.json'), true, 512, JSON_THROW_ON_ERROR);

/** Types whose shape is still settling (young, or no real adopter yet). Everything else is proposed Stable. */
$provisional = [
    'Contracts\\AccessPolicyInterface' => 'ADR-003: no service enforces it yet',
    'Contracts\\AccessDenied' => 'ADR-003',
    'Support\\AllowAllPolicy' => 'ADR-003',
    'Support\\DenyAllPolicy' => 'ADR-003',
    'Webhooks\\Destination' => 'preset catalogue added in 0.21',
    'Webhooks\\DestinationField' => 'preset catalogue added in 0.21',
    'Webhooks\\Destinations' => 'preset catalogue added in 0.21',
    'Webhooks\\EventCatalog' => 'event list grows every release; ids are stable, the class shape is new in 0.21',
    'Webhooks\\EventDefinition' => 'new in 0.21',
    'Webhooks\\EventSummary' => 'new in 0.21',
    'Webhooks\\FormattedPayload' => 'new in 0.21',
    'Webhooks\\PayloadFormatter' => 'new in 0.21',
    'Webhooks\\PayloadTemplate' => 'new in 0.21',
    'Webhooks\\Authentication' => 'new in 0.21',
    'Webhooks\\WebhookDispatcher' => 'long positional constructor; options object proposed in the freeze review',
    'Ui\\IconCatalog' => 'new in 0.19',
    'Ui\\DateRange' => 'new in 0.20',
    'Cron\\JobRunner' => 'subclassed by editions; process-spawning details may change',
    'Mcp\\ToolPipeline' => 'closure-or-logger union (freeze review item 4)',
    'Mcp\\UnlinkedIdentityStore' => 'closure-or-logger union',
    'Mcp\\IdentityLinker' => 'closure-or-logger union',
    'Redis\\RedisConnectionConfig' => 'ten positional parameters; use named arguments',
    'Compliance\\SubjectCompliance' => 'MSP-only consumer so far',
    'Compliance\\ResponsibilityStore' => 'one consumer so far',
    'Compliance\\ClientChecklist' => 'one consumer so far',
];
$module = static fn (string $t): string => explode('\\', $t)[2] ?? '';
$rows = [];
foreach ($snap['types'] as $fqcn => $t) {
    $short = substr($fqcn, strlen('RivetCore\\'));
    $isInterface = str_starts_with($t['kind'], 'interface');
    $edition = $isInterface && !in_array($short, ['Contracts\\AccessPolicyInterface'], true) ? 'implement' : ($isInterface ? 'implement (optional)' : 'call');
    if (str_contains($short, 'Exception') || $short === 'Database\\DatabaseException' || $short === 'Contracts\\AccessDenied' || $short === 'Jobs\\PermanentJobFailure' || $short === 'Jobs\\JobTimeout' || $short === 'Mcp\\NotFoundException') {
        $edition = 'catch / throw';
    }
    if (in_array($short, ['Compliance\\Check\\AuditTrailRecordingCheck', 'Compliance\\Check\\RetentionMeetsPresetCheck'], true)) {
        $edition = 'register as a check';
    }
    $stab = isset($provisional[$short]) ? 'Provisional' : 'Stable';
    $rows[] = sprintf('| `%s` | %s | %s | %s | %s |', $short, $t['kind'], $edition, $stab, $provisional[$short] ?? '');
}
$table = "| Type | Kind | Edition does | Proposed stability | Why provisional |\n|---|---|---|---|---|\n" . implode("\n", $rows);

if (($argv[1] ?? '') === '--write') {
    $file = __DIR__ . '/../docs/PUBLIC-API.md';
    $doc = (string) file_get_contents($file);
    $new = preg_replace_callback('/<!-- types:begin -->.*<!-- types:end -->/s', static fn (): string => "<!-- types:begin -->\n" . $table . "\n<!-- types:end -->", $doc, 1, $n);
    if ($n !== 1) {
        fwrite(STDERR, "markers not found in docs/PUBLIC-API.md\n");
        exit(1);
    }
    file_put_contents($file, $new);
    echo "updated docs/PUBLIC-API.md (" . count($rows) . " types)\n";
    exit(0);
}
echo $table, "\n";
