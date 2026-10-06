<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\EventCatalog;
use RivetCore\Webhooks\EventDefinition;

final class EventCatalogTest extends TestCase
{
    public function testEntriesAreCompleteAndUnique(): void
    {
        $ids = [];
        foreach (EventCatalog::all() as $e) {
            self::assertInstanceOf(EventDefinition::class, $e);
            self::assertMatchesRegularExpression('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $e->id, 'dotted lowercase id, no wildcard');
            self::assertStringNotContainsString('*', $e->id);
            self::assertNotSame('', $e->group);
            self::assertNotSame('', $e->groupLabel);
            self::assertNotSame('', $e->label);
            self::assertNotSame('', $e->description);
            self::assertContains($e->severity, ['info', 'warning', 'critical']);
            self::assertContains($e->since, [null, 'planned']);
            foreach ($e->payloadFields as $f) {
                self::assertNotSame('', $f['path']);
                self::assertNotSame('', $f['type']);
                self::assertNotSame('', $f['description']);
            }
            self::assertSame($e->tags, array_values(array_filter($e->tags, static fn ($t) => $t !== '' && $t === trim($t))));
            $ids[] = $e->id;
        }
        self::assertSame($ids, array_values(array_unique($ids)), 'ids are unique');
        self::assertGreaterThanOrEqual(90, count($ids));
    }

    public function testEveryEventTheEditionsEmitTodayIsListed(): void
    {
        foreach ([
            'ticket.created', 'ticket.replied', 'ticket.assigned', 'ticket.status_changed', 'ticket.resolved', 'workflow.onboarding_started',
            'workflow.offboarding_started', 'workflow.cancelled', 'workflow.action_executed', 'workflow.action_failed', 'workflow.approval_approved',
            'workflow.approval_rejected', 'workflow.approval_overridden', 'workflow.task_webhook', 'workflow.completed', 'workflow.task_completed',
            'workflow.template_created', 'employee.hired', 'employee.terminated', 'people.import_approved', 'problem.created', 'problem.status_changed',
            'change.created', 'change.status_changed', 'kb_article.restored', 'vault.credential_revealed', 'auth.login_success', 'auth.login_failed',
            'auth.mfa_failed', 'auth.login_blocked', 'integration.microsoft.test', 'integration.odoo.test', 'audit.exported', 'webhooks.networks_changed',
            'redis.cleared', 'job.retried', 'cron.job_started', 'automation.rule_toggled', 'compliance.snapshot_taken', 'mcp.settings_changed',
        ] as $id) {
            self::assertTrue(EventCatalog::has($id), $id);
            self::assertNull(EventCatalog::get($id)?->since, $id);
        }
        self::assertNull(EventCatalog::get('nope.nothing'));
    }

    public function testGroupsAndCounts(): void
    {
        $groups = EventCatalog::groups();
        $by = EventCatalog::byGroup();
        self::assertSame(array_keys($groups), array_keys($by));
        $sum = 0;
        foreach ($groups as $key => $g) {
            self::assertNotSame('', $g['label']);
            self::assertSame($g['count'], count($by[$key]));
            self::assertGreaterThan(0, $g['count']);
            $sum += $g['count'];
        }
        self::assertSame(count(EventCatalog::all()), $sum);
        foreach (['tickets', 'sla', 'approvals', 'workflows', 'assets', 'clients', 'billing', 'security', 'audit', 'system', 'automation', 'integrations'] as $k) {
            self::assertArrayHasKey($k, $groups);
        }
        self::assertSame('Tickets', $groups['tickets']['label']);
    }

    public function testSearchMatchesAndRanks(): void
    {
        $ids = static fn (array $r): array => array_map(static fn (EventDefinition $e): string => $e->id, $r);
        // id prefix beats label/description matches
        self::assertSame('ticket.created', $ids(EventCatalog::search('ticket.cre'))[0]);
        self::assertSame('ticket.created', $ids(EventCatalog::search('TICKET.CREATED'))[0], 'case-insensitive, exact id first');
        // label prefix
        self::assertSame('sla.warning', $ids(EventCatalog::search('SLA at'))[0]);
        // tag synonym
        self::assertContains('auth.login_failed', $ids(EventCatalog::search('brute force')));
        self::assertContains('workflow.onboarding_started', $ids(EventCatalog::search('new hire')));
        // description
        self::assertContains('redis.cleared', $ids(EventCatalog::search('flushed')));
        // all terms must match
        self::assertSame([], EventCatalog::search('ticket zzzzqqq'));
        // id/label hit outranks a description-only hit
        $r = $ids(EventCatalog::search('backup'));
        self::assertLessThan(array_search('integration.odoo.test', $r) === false ? 999 : array_search('integration.odoo.test', $r), array_search('backup.failed', $r));
        // group filter and limit
        $g = EventCatalog::search('', 'tickets');
        self::assertSame(array_map(static fn ($e) => $e->id, EventCatalog::byGroup()['tickets']), $ids($g));
        self::assertSame([], EventCatalog::search('auth', 'tickets'));
        self::assertCount(3, EventCatalog::search('', null, 3));
        self::assertSame([], EventCatalog::search('', null, 0));
        self::assertCount(count(EventCatalog::all()), EventCatalog::search('   '));
    }

    public function testSearchIsDeterministic(): void
    {
        self::assertEquals(EventCatalog::search('training'), EventCatalog::search('training'));
        $a = array_map(static fn ($e) => $e->id, EventCatalog::search('changed'));
        $b = array_map(static fn ($e) => $e->id, EventCatalog::search('changed'));
        self::assertSame($a, $b);
        self::assertNotSame([], $a);
    }

    public function testWildcardPatterns(): void
    {
        $all = array_map(static fn ($e) => $e->id, EventCatalog::all());
        self::assertSame($all, EventCatalog::matchPattern('*'));
        $t = EventCatalog::matchPattern('ticket.*');
        self::assertContains('ticket.created', $t);
        self::assertContains('ticket.resolved', $t);
        self::assertNotContains('workflow.completed', $t);
        foreach ($t as $id) {
            self::assertStringStartsWith('ticket.', $id);
        }
        self::assertSame(['auth.login_success', 'auth.login_failed', 'auth.login_blocked'], array_values(array_intersect($all, EventCatalog::matchPattern('auth.login_*'))));
        self::assertContains('integration.microsoft.test', EventCatalog::matchPattern('integration.*'));
        self::assertContains('ticket.created', EventCatalog::matchPattern('*.created'));
        self::assertSame(['ticket.created'], EventCatalog::matchPattern('ticket.created'));
        self::assertSame([], EventCatalog::matchPattern('ticket.nope'));
        self::assertSame([], EventCatalog::matchPattern('nothing.*'));
        self::assertSame([], EventCatalog::matchPattern(''));
        self::assertSame([], EventCatalog::matchPattern('Ticket.*'), 'uppercase is not a valid id');
        self::assertSame([], EventCatalog::matchPattern('ticket.*; DROP'), 'invalid characters');
        self::assertSame([], EventCatalog::matchPattern(str_repeat('a', 200) . '*'));
        self::assertSame($all, EventCatalog::matchPattern('**'));
        self::assertTrue(EventCatalog::isPattern('a.*'));
        self::assertFalse(EventCatalog::isPattern('a.b'));
    }

    public function testUnknown(): void
    {
        self::assertSame(['made.up'], EventCatalog::unknown(['ticket.created', 'made.up', 'ticket.*', '*']));
        self::assertSame(['nothing.*'], EventCatalog::unknown(['nothing.*']));
        self::assertSame([], EventCatalog::unknown([]));
    }

    public function testJson(): void
    {
        $j = json_decode(EventCatalog::toJson(), true);
        self::assertSame(count(EventCatalog::all()), count($j['events']));
        self::assertSame(EventCatalog::groups(), $j['groups']);
        self::assertSame('ticket.created', $j['events'][0]['id']);
        self::assertArrayHasKey('payloadFields', $j['events'][0]);
        self::assertSame(EventCatalog::toArray(), $j['events']);
    }
}
