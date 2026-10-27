<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Technician;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Policy\StarterPack\PolicyPackInstaller;

/**
 * The policy starter pack for administrators: list the items with their state, preview, install, update, reset to the pack version and uninstall. Needs
 * `rmm.admin` (the same grant as editing policies, and the pack also writes patch policies, maintenance windows and, on explicit confirmation, the change
 * monitoring presets). Every changed item writes an audit row; nothing carries a setting body.
 *
 * @api
 */
final class PolicyPackActions
{
    public function __construct(
        private readonly RmmAuthorizer $authz,
        private readonly PolicyPackInstaller $installer,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    private function admin(RmmPrincipal $who): ?ActionResult
    {
        $why = $this->authz->check($who->userId, RmmAbility::ADMIN, 0);

        return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
    }

    public function catalog(RmmPrincipal $who): ActionResult
    {
        return $this->admin($who) ?? ActionResult::ok('', 200, 'ok', $this->installer->catalog());
    }

    /**
     * @param array<string,mixed> $selection {ids?, params?, include_live?, confirm_instance_wide?}
     */
    public function run(RmmPrincipal $who, string $op, array $selection, bool $dryRun = false): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }

        return $this->go($who->userName, $who->userId, $op, $selection, $dryRun);
    }

    /**
     * The operator's own shell (scripts/policy-pack.php): no ability check, because whoever can run PHP on the server already owns the database; the audit rows say so.
     *
     * @param array<string,mixed> $selection
     */
    public function runFromCli(string $op, array $selection, int $userId = 0, bool $dryRun = false): ActionResult
    {
        return $this->go('CLI', $userId, $op, $selection, $dryRun);
    }

    /** @param array<string,mixed> $selection */
    private function go(string $userName, int $userId, string $op, array $selection, bool $dryRun): ActionResult
    {
        try {
            $r = $this->installer->run($op, $selection, $userId, $dryRun);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        if (!$dryRun) {
            $n = 0;
            foreach ($r['items'] as $i) {
                if (in_array($i['outcome'], ['added', 'updated', 'reset', 'removed'], true)) {
                    ++$n;
                    $this->audit->record('Policy Pack Item ' . ucfirst((string) $i['outcome']), "$userName {$i['outcome']} policy pack item \"{$i['name']}\" ({$i['kind']}, {$r['pack_id']} {$r['pack_version']})", 0, (int) ($i['entity_id'] ?? 0));
                }
            }
            if ($n > 0) {
                $this->audit->record('Policy Pack ' . ucfirst($op), "$userName ran policy pack $op: " . json_encode($r['counts']), 0, 0);
            }
        }

        return ActionResult::ok($dryRun ? 'Preview only.' : 'Done.', 200, 'ok', $r);
    }
}
