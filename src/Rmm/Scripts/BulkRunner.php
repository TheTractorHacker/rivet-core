<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

/**
 * Queues one library script on every device of a target, in pages. Used for a bulk run by a technician and for the execution of an approved run.
 * With an approval id each device's job carries the idempotency key `approval:<id>:device:<device>`, so executing the same approval twice (a crash
 * half way, a retry) queues each device once.
 *
 * @api
 */
final class BulkRunner
{
    public function __construct(private readonly TargetResolver $targets, private readonly ScriptRunner $runner, private readonly ?\RivetCore\Rmm\Support\Sql $sql = null)
    {
    }

    /**
     * @param array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>} $loaded
     * @param array{type:string,id:int} $target
     * @param array<array-key,mixed> $params
     * @param list<int>|null $visible client ids the run is restricted to (null = all)
     * @param (\Closure(int):bool)|null $allowed whether the caller may run jobs on a client (null = yes)
     * @return array{device_count:int,created:int,skipped_denied:int,skipped_error:int,errors:list<string>}
     */
    public function run(array $loaded, array $target, array $params, ?int $timeoutS, int $userId, ?array $visible, ?\Closure $allowed, string $origin, ?int $approvalId, int $max): array
    {
        $platforms = ScriptLanguage::platforms((string) $loaded['script']['language']);
        $out = ['device_count' => 0, 'created' => 0, 'skipped_denied' => 0, 'skipped_error' => 0, 'errors' => []];
        $cursor = 0;
        $clientOk = [];
        while ($out['device_count'] < $max) {
            $page = $this->targets->devices($target['type'], $target['id'], $visible, $platforms, $cursor, min(500, $max - $out['device_count']));
            if ($page === []) {
                break;
            }
            $work = function () use ($page, &$out, &$cursor, &$clientOk, $allowed, $loaded, $params, $timeoutS, $userId, $origin, $approvalId): bool {
                foreach ($page as $d) {
                    $cursor = (int) $d['device_id'];
                    ++$out['device_count'];
                    $client = (int) $d['client_id'];
                    if ($allowed !== null) {
                        $clientOk[$client] ??= $allowed($client);
                        if (!$clientOk[$client]) {
                            ++$out['skipped_denied'];
                            continue;
                        }
                    }
                    $prep = $this->runner->prepare($loaded, $d, $params, $timeoutS);
                    if (!$prep['ok']) {
                        ++$out['skipped_error'];
                        if (count($out['errors']) < 5) {
                            $out['errors'][] = ($d['hostname'] ?? 'device ' . $d['device_id']) . ': ' . $prep['error'];
                        }
                        continue;
                    }
                    $opts = $approvalId === null ? [] : ['approval_id' => $approvalId, 'idem_key' => hash('sha256', "approval:$approvalId:device:" . $d['device_id'])];
                    $r = $this->runner->dispatch($loaded, $d, $prep, $userId, $origin, $opts);
                    if (!$r['ok']) {
                        ++$out['skipped_error'];
                        if (count($out['errors']) < 5) {
                            $out['errors'][] = ($d['hostname'] ?? 'device ' . $d['device_id']) . ': ' . ($r['error'] ?? 'could not be queued');
                        }
                    } elseif (empty($r['duplicate'])) {
                        ++$out['created'];
                    }
                }

                return true;
            };
            // One transaction per page: a commit per job would cost a log flush each.
            if ($this->sql === null) {
                $work();
            } else {
                $sql = $this->sql;
                $this->runner->batch(static fn (): mixed => $sql->transaction($work));
            }
        }

        return $out;
    }
}
