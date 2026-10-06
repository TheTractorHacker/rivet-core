<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditService;
use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Cron\JobRunner;
use RivetCore\Jobs\JobQueue;
use RivetCore\Knowledge\CredentialReferenceRenderer;
use RivetCore\Retention\RetentionService;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Webhooks\UrlPolicy;
use RivetCore\Webhooks\WebhookDispatcher;

/** Regression tests for the 2026-10 security review (RivetCore 0.17.2). */
final class SecurityFixes202610Test extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function nonPublicIps(): iterable
    {
        foreach ([
            '2002:7f00:1::', '2002:a00:1::', '2002:5db8:d822::',
            '64:ff9b:1::7f00:1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '198.18.0.1', '198.19.255.255', '192.0.0.1',
            '192.0.2.1', '192.88.99.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.250', '100::1',
            '2001:db8::1', 'ff02::1', '::ffff:198.18.0.1', '64:ff9b::c000:201',
        ] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('nonPublicIps')]
    public function testTransitionAndSpecialRangesAreNotPublic(string $ip): void
    {
        self::assertFalse(UrlPolicy::isPublicIp($ip), $ip);
    }

    public function testRealPublicAddressesStayPublic(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '198.20.0.1', '2606:2800:220:1:248:1893:25c8:1946', '2a00:1450:4001::1'] as $ip) {
            self::assertTrue(UrlPolicy::isPublicIp($ip), $ip);
        }
    }

    public function testPinnedRequestsBypassProxiesAndUseTheVettedHost(): void
    {
        $target = ['host' => 'example.com', 'port' => 80, 'ips' => ['93.184.216.34']];
        $o = WebhookDispatcher::curlOptions('{}', [], 5, $target);
        self::assertSame('', $o[CURLOPT_PROXY]);
        self::assertSame('*', $o[CURLOPT_NOPROXY]);
        self::assertArrayNotHasKey(CURLOPT_PROXY, WebhookDispatcher::curlOptions('{}', [], 5));

        $vetted = (new UrlPolicy(false, static fn (): array => ['93.184.216.34']))->vet('http://Example.com../x?y=1#f');
        self::assertNotNull($vetted);
        $url = WebhookDispatcher::pinnedUrl('http://Example.com../x?y=1#f', $vetted);
        self::assertSame('http://example.com/x?y=1', $url);
        self::assertSame('example.com', parse_url($url, PHP_URL_HOST));
        self::assertSame('http://example.com:8080/a', WebhookDispatcher::pinnedUrl('http://example.com.:8080/a', ['host' => 'example.com', 'port' => 8080, 'ips' => ['1.1.1.1']]));
        self::assertSame('https://[2606:2800::1]/', WebhookDispatcher::pinnedUrl('https://[2606:2800::1]/', ['host' => '2606:2800::1', 'port' => 443, 'ips' => ['2606:2800::1']]));
    }

    public function testRequeueStaleDeadLettersExhaustedJobs(): void
    {
        $db = new FakeDatabase();
        $db->rows = [['c' => 0]];
        (new JobQueue($db))->requeueStale(15);
        $sql = $db->calls[array_key_last($db->calls)]['sql'];
        self::assertStringContainsString("IF(attempts >= max_attempts, 'dead_letter', 'pending')", $sql);
    }

    public function testCompletionAndFailureAreFencedByStatusAndAttempt(): void
    {
        $db = new FakeDatabase();
        $db->rows = [['c' => 0, 'n' => '2026-01-01 00:00:00']];
        $q = new JobQueue($db);
        $q->markCompleted(5, ['a' => 1], 2);
        $c = $db->calls[array_key_last($db->calls)];
        self::assertStringContainsString("status = 'running' AND attempts = ?", $c['sql']);
        self::assertSame(2, end($c['params']));

        $q->markFailed(5, 'x', 2, 5, 2);
        $f = $db->calls[array_key_last($db->calls)];
        self::assertStringContainsString("status = 'running' AND attempts = ?", $f['sql']);
        self::assertSame(2, end($f['params']));

        $q->markCompleted(5);
        $n = $db->calls[array_key_last($db->calls)];
        self::assertStringContainsString("AND status = 'running'", $n['sql']);
        self::assertStringNotContainsString('attempts = ?', $n['sql']);
    }

    public function testJobRunnerRefusesAGroupOrWorldWritableStateDir(): void
    {
        $root = sys_get_temp_dir() . '/rc-sec-' . bin2hex(random_bytes(4));
        mkdir($root . '/cron', 0777, true);
        mkdir($root . '/state', 0777);
        chmod($root . '/state', 0777);
        file_put_contents($root . '/cron/a.php', '<?php echo 1;');
        try {
            $res = (new JobRunner($root, $root . '/state'))->start('a', $root . '/cron/a.php', [], PHP_BINARY);
            self::assertFalse($res['ok']);
            self::assertStringContainsString('not private', $res['message']);
        } finally {
            @unlink($root . '/cron/a.php');
            @rmdir($root . '/cron');
            @rmdir($root . '/state');
            @rmdir($root);
        }
    }

    public function testJobRunnerDoesNotWriteThroughAPlantedLogSymlink(): void
    {
        $root = sys_get_temp_dir() . '/rc-sec-' . bin2hex(random_bytes(4));
        mkdir($root . '/cron', 0777, true);
        mkdir($root . '/state', 0700);
        file_put_contents($root . '/cron/a.php', '<?php echo "x";');
        file_put_contents($root . '/victim.txt', 'keep me');
        symlink($root . '/victim.txt', $root . '/state/a.log');
        try {
            $runner = new JobRunner($root, $root . '/state');
            $runner->start('a', $root . '/cron/a.php', [], PHP_BINARY);
            for ($i = 0; $i < 60 && $runner->state('a', $root . '/cron/a.php')['running']; $i++) {
                usleep(100000);
            }
            self::assertSame('keep me', file_get_contents($root . '/victim.txt'));
            self::assertFalse(is_link($root . '/state/a.log'));
        } finally {
            foreach (glob($root . '/{state,cron}/*', GLOB_BRACE) ?: [] as $f) {
                @unlink($f);
            }
            @unlink($root . '/victim.txt');
            @rmdir($root . '/state');
            @rmdir($root . '/cron');
            @rmdir($root);
        }
    }

    private function ctx(): RequestContextInterface
    {
        return new class implements RequestContextInterface {
            public function ipAddress(): ?string
            {
                return str_repeat('1', 100);
            }

            public function userAgent(): ?string
            {
                return 'UA';
            }

            public function requestId(): ?string
            {
                return str_repeat('r', 100);
            }
        };
    }

    public function testAuditFieldsAreClampedToColumnWidths(): void
    {
        $db = new FakeDatabase();
        (new AuditService($db, $this->ctx()))->log(str_repeat('e', 300), 1, str_repeat('t', 300), str_repeat('9', 300), str_repeat('a', 300));
        $p = $db->calls[0]['params'];
        self::assertSame(100, strlen($p[0]));
        self::assertSame(100, strlen($p[2]));
        self::assertSame(64, strlen($p[3]));
        self::assertSame(50, strlen($p[4]));
        self::assertSame(64, strlen($p[7]));
        self::assertSame(64, strlen($p[9]));
    }

    public function testAuditMetadataNeverThrowsAndRedactsSecrets(): void
    {
        $db = new FakeDatabase();
        $svc = new AuditService($db, $this->ctx());
        $svc->log('x', 1, null, null, 'a', null, ['bad' => NAN]);
        self::assertSame('{"_error":"metadata could not be encoded"}', $db->calls[0]['params'][6]);

        $fh = fopen('php://memory', 'r');
        $svc->log('x', 1, null, null, 'a', null, ['h' => $fh, 'nested' => ['Password' => 'p', 'ok' => 1], 'token' => 't']);
        fclose($fh);
        self::assertSame('{"h":"[unserializable]","nested":{"Password":"[redacted]","ok":1},"token":"[redacted]"}', $db->calls[1]['params'][6]);
    }

    public function testRetentionClampsToTheComplianceFloorWhenAProfileIsSet(): void
    {
        $profile = array_key_first(\RivetCore\Compliance\RetentionPolicy::PROFILES);
        foreach (array_keys(\RivetCore\Compliance\RetentionPolicy::PROFILES) as $p) {
            if (\RivetCore\Compliance\RetentionPolicy::floorDays($p) > 30) {
                $profile = $p;
                break;
            }
        }
        $floor = \RivetCore\Compliance\RetentionPolicy::floorDays($profile);
        self::assertGreaterThan(30, $floor, 'need a preset with a floor above 30 days');

        $db = new FakeDatabase();
        $db->rows = [['n' => '2030-01-01 00:00:00']];
        (new RetentionService($db, $profile))->prune(1);
        $delete = null;
        foreach ($db->calls as $c) {
            if (str_starts_with($c['sql'], 'DELETE FROM audit_events')) {
                $delete = $c;
            }
        }
        self::assertNotNull($delete);
        $expected = (new \DateTimeImmutable('2030-01-01 00:00:00'))->modify("-{$floor} days")->format('Y-m-d H:i:s');
        self::assertSame([$expected], $delete['params']);
    }

    public function testCredentialTokensInsideTagsAreRemovedNotExpanded(): void
    {
        $r = new CredentialReferenceRenderer(static fn (int $id): string => "<a data-id=\"$id\">reveal</a>");
        $out = $r->render('<p title="x>[[credential:1]]">see [[credential:2]]</p><img alt=\'[[credential:3]]\'>');
        self::assertSame('<p title="x>">see <a data-id="2">reveal</a></p><img alt=\'\'>', $out);
    }
}
