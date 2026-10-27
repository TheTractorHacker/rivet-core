<?php

declare(strict_types=1);

namespace RivetCore\Tests\Security;

use RivetCore\Cron\JobRunner;
use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\JobWorker;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Tests\Support\FixedClock;

/** Migration, job queue and cron-runner surface: RC-SR2-01, -16, -22. */
final class JobsCronSurfaceTest extends SecurityTestCase
{
    /**
     * Documents RC-SR2-01: MigrationRunner serialises runs with GET_LOCK('rivet_core_migrations'). MySQL/MariaDB lock
     * names are SERVER-wide, not per database, so two installations on one server (or any account that may call
     * GET_LOCK) block each other: here a connection with no database selected holds the name and the runner on the
     * scratch database gives up with "Another Core migration run is in progress". It is a cross-installation
     * availability problem for updates, not a data risk. Flip: assert the runner succeeds (lock name must include the
     * database name, e.g. 'rivet_core_migrations:' . DATABASE()).
     */
    public function testRc01MigrationLockNameIsServerWide(): void
    {
        $db = $this->db();
        $this->assertSame('rivet_core_migrations', MigrationRunner::LOCK_NAME);
        $holder = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '');
        try {
            $got = $holder->query("SELECT GET_LOCK('rivet_core_migrations', 0)")->fetch_row()[0];
            $this->assertSame('1', (string) $got);
            $runner = new MigrationRunner($db, CoreMigrations::all(), new FixedClock(), 1);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Another Core migration run is in progress');
            $runner->run();
        } finally {
            $holder->query("SELECT RELEASE_LOCK('rivet_core_migrations')");
            $holder->close();
        }
    }

    /**
     * Documents RC-SR2-16: JobWorker stores the first 2000 characters of whatever a handler's exception says in
     * integration_jobs.error, unredacted, where the edition lists it for admins and retention keeps it for the job
     * horizon. Exception text from HTTP clients routinely contains the full request URL including query tokens. Flip:
     * assert the token is masked (strip userinfo/query from URLs, or mask common secret patterns).
     */
    public function testRc16HandlerExceptionTextIsPersistedVerbatim(): void
    {
        $db = $this->db('integration_jobs');
        $queue = new JobQueue($db);
        $queue->enqueue('sync.directory', ['x' => 1], null, null, 0, 1);
        (new JobWorker($queue))->register('sync.directory', function (): array {
            throw new \RuntimeException('cURL error 28 for https://api.vendor.example/v1/users?api_key=SUPERSECRETKEY123: timed out');
        })->run(5, 5);
        $row = $db->fetchOne('SELECT status, error FROM integration_jobs');
        $this->assertSame('dead_letter', $row['status']);
        $this->assertStringContainsString('SUPERSECRETKEY123', (string) $row['error']);
    }

    /**
     * Documents RC-SR2-22 (INFO): JobRunner hardening notes. (1) keys are sanitised by deleting characters, so "a.b" and
     * "ab" share one pid/log file and state; (2) argumentsFromCommand()/start() accept ANY option name of the form
     * --name[=value] (the per-script option allow-list is the edition's job); (3) the default state directory lives in the
     * shared system temp dir, so a local user who pre-creates it makes start() refuse (fail closed, availability only),
     * and a group-writable directory is refused. start() itself is only reached for scripts under <root>/cron or <root>/scripts.
     */
    public function testRc22JobRunnerNotes(): void
    {
        $root = sys_get_temp_dir() . '/rivetcore-sr2-root-' . getmypid();
        @mkdir($root . '/cron', 0700, true);
        file_put_contents($root . '/cron/x.php', '<?php echo "hi";');
        $state = $root . '/state';
        @mkdir($state, 0700);
        try {
            $runner = new JobRunner($root, $state);
            $files = new \ReflectionMethod($runner, 'files');
            $this->assertSame($files->invoke($runner, 'a.b')['pid'], $files->invoke($runner, 'ab')['pid'], 'colliding keys');
            $this->assertSame(['--config=evil.ini', '--no-limit'], JobRunner::argumentsFromCommand('/usr/bin/php /app/cron/x.php --config=evil.ini --no-limit >> /var/log/x.log 2>&1', '/app/cron/x.php'));
            $this->assertNull(JobRunner::argumentsFromCommand('/usr/bin/php /app/cron/x.php --config=/etc/passwd', '/app/cron/x.php'), 'a path value is refused');
            $this->assertFalse($runner->start('k', '/etc/passwd')['ok'], 'only scripts under cron/ or scripts/ run');
            $this->assertFalse($runner->start('k', $root . '/cron/../../../etc/passwd')['ok']);

            chmod($state, 0775);
            $unsafe = new JobRunner($root, $state);
            $res = $unsafe->start('k', $root . '/cron/x.php', [], PHP_BINARY);
            $this->assertFalse($res['ok']);
            $this->assertStringContainsString('not private', $res['message']);
        } finally {
            foreach (glob($state . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($state);
            @unlink($root . '/cron/x.php');
            @rmdir($root . '/cron');
            @rmdir($root);
        }
    }

    /** Guard (no finding): the queue claims atomically and a stale worker cannot overwrite a newer attempt. */
    public function testGuardClaimFenceStopsAReclaimedWorkerOverwriting(): void
    {
        $db = $this->db('integration_jobs');
        $q = new JobQueue($db);
        $id = $q->enqueue('t', [], null, null, 0, 5);
        $first = $q->claim(1);
        $this->assertCount(1, $first);
        $this->assertSame([], $q->claim(1), 'a running job is not claimed twice');
        $db->execute("UPDATE integration_jobs SET status = 'pending' WHERE job_id = ?", [$id]);
        $second = $q->claim(1);
        $this->assertSame(2, $second[0]['attempts']);
        $this->assertFalse($q->markCompleted($id, ['late' => true], 1), 'the first attempt no longer owns the job');
        $this->assertTrue($q->markCompleted($id, ['ok' => true], 2));
    }
}
