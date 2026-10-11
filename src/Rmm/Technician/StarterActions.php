<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Technician;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Starter\StarterInstaller;
use RivetCore\Rmm\Support\RmmEventPublisher;

/**
 * The starter pack for administrators: browse the catalog, preview an install, install, uninstall. Everything needs `rmm.admin`, because it puts
 * signed scripts into the library and policy drafts into the policy list. Each item that changes gets an activity-log line, and an install or uninstall
 * that changed anything raises the `rmm.starter.installed` event (no script text anywhere).
 *
 * @api
 */
final class StarterActions
{
    public function __construct(
        private readonly RmmAuthorizer $authz,
        private readonly StarterInstaller $installer,
        private readonly RmmAuditInterface $audit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    private function admin(RmmPrincipal $who): ?ActionResult
    {
        $why = $this->authz->check($who->userId, RmmAbility::ADMIN, 0);

        return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
    }

    /** @param array<string,mixed> $filter {pack?, platform?, risk?, tags?, q?} */
    public function catalog(RmmPrincipal $who, array $filter = []): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        try {
            return ActionResult::ok('', 200, 'ok', $this->installer->catalog($filter));
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
    }

    /** @param array<string,mixed> $selection */
    public function plan(RmmPrincipal $who, array $selection): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        try {
            return ActionResult::ok('', 200, 'ok', $this->installer->plan($selection));
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
    }

    /** @param array<string,mixed> $selection */
    public function install(RmmPrincipal $who, array $selection): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        try {
            $r = $this->installer->install($selection, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $changed = 0;
        foreach ($r['items'] as $i) {
            if (in_array($i['outcome'], ['added', 'updated', 'staged'], true)) {
                ++$changed;
                $what = $i['kind'] === 'script' ? 'script' : 'policy draft';
                $this->audit->record('Starter Pack Item ' . ucfirst((string) $i['outcome']), "{$who->userName} {$i['outcome']} starter $what \"{$i['name']}\" ({$i['pack']} {$i['pack_version']})", 0, (int) ($i['script_id'] ?? $i['policy_id'] ?? 0));
            }
        }
        $this->audit->record('Starter Pack Installed', "{$who->userName} installed starter pack items: " . self::countText($r['counts']), 0, 0);
        if ($changed > 0) {
            $this->emit($who, 'install', $r['counts'], $r['items']);
        }

        return ActionResult::ok($changed > 0 ? 'Starter pack items installed.' : 'Nothing to change.', 200, 'ok', $r);
    }

    /** @param array<string,mixed> $selection */
    public function uninstall(RmmPrincipal $who, array $selection, bool $dryRun = false): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        try {
            $r = $this->installer->uninstall($selection, $who->userId, $dryRun);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        if (!$dryRun) {
            $n = 0;
            foreach ($r['items'] as $i) {
                if (in_array($i['outcome'], ['retired', 'deleted'], true)) {
                    ++$n;
                    $this->audit->record('Starter Pack Item ' . ucfirst((string) $i['outcome']), "{$who->userName} {$i['outcome']} starter {$i['kind']} \"{$i['name']}\"", 0, (int) ($i['script_id'] ?? $i['policy_id'] ?? 0));
                }
            }
            if ($n > 0) {
                $this->emit($who, 'uninstall', $r['counts'], $r['items']);
            }
        }

        return ActionResult::ok($dryRun ? 'Preview only.' : 'Done.', 200, 'ok', $r);
    }

    /**
     * @param array<string,int> $counts
     * @param list<array<string,mixed>> $items
     */
    private function emit(RmmPrincipal $who, string $action, array $counts, array $items): void
    {
        $packs = array_values(array_unique(array_map(static fn (array $i): string => (string) ($i['pack'] ?? ''), $items)));
        $this->events?->emitScoped(RmmEvent::STARTER_INSTALLED, 0, ['action' => $action, 'pack_id' => implode(',', $packs), 'added' => $counts['added'] ?? 0, 'updated' => $counts['updated'] ?? 0,
            'staged' => $counts['staged'] ?? 0, 'removed' => ($counts['retired'] ?? 0) + ($counts['deleted'] ?? 0), 'skipped' => ($counts['skipped'] ?? 0) + ($counts['blocked'] ?? 0), 'user_id' => $who->userId]);
    }

    /** @param array<string,int> $counts */
    private static function countText(array $counts): string
    {
        $p = [];
        foreach ($counts as $k => $n) {
            $p[] = "$n $k";
        }

        return $p === [] ? 'nothing selected' : implode(', ', $p);
    }
}
