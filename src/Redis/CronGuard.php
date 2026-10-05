<?php

declare(strict_types=1);

namespace RivetCore\Redis;

/** "Only one copy of this cron job at a time". The CLI echo/exit stays with the caller. */
final class CronGuard
{
    public function __construct(private LockManager $locks)
    {
    }

    /**
     * Returns the lock when this process may run (releasing it automatically at shutdown), or null when
     * another copy holds it.
     */
    public function acquire(string $job, int $ttlSeconds = 900): ?Lock
    {
        $lock = $this->locks->acquire('cron:' . $job, $ttlSeconds);
        if (!$lock->held()) {
            return null;
        }
        register_shutdown_function(static fn () => $lock->release());

        return $lock;
    }
}
