<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\ITSM\ChangeService;
use RivetCore\ITSM\Migration\Migration0004ProblemsAndChanges;
use RivetCore\ITSM\ProblemService;
use RivetCore\ITSM\TicketProblemLinkInterface;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class ItsmTest extends TestCase
{
    private MysqliDatabase $db;
    private object $tickets;
    private ProblemService $problems;
    private ChangeService $changes;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        $this->db->execute('DROP TABLE IF EXISTS problems');
        $this->db->execute('DROP TABLE IF EXISTS changes');
        (new Migration0004ProblemsAndChanges())->up($this->db);
        $this->tickets = new class implements TicketProblemLinkInterface {
            public array $links = [];

            public function link(int $ticketId, int $problemId): void
            {
                $this->links[$ticketId] = $problemId;
            }

            public function unlink(int $ticketId, int $problemId): void
            {
                if (($this->links[$ticketId] ?? null) === $problemId) {
                    unset($this->links[$ticketId]);
                }
            }
        };
        $this->problems = new ProblemService($this->db, $this->tickets);
        $this->changes = new ChangeService($this->db);
    }

    public function testProblemLifecycleAndResolvedAt(): void
    {
        $id = $this->problems->create('Printer jams', 'Root cause unknown', 3);
        $row = $this->db->fetchOne('SELECT * FROM problems WHERE problem_id = ?', [$id]);
        $this->assertSame('open', $row['status']);
        $this->assertNull($row['resolved_at']);
        $this->problems->setStatus($id, 'investigating');
        $this->problems->setStatus($id, 'resolved');
        $first = $this->db->fetchOne('SELECT resolved_at FROM problems WHERE problem_id = ?', [$id])['resolved_at'];
        $this->assertNotNull($first);
        $this->db->execute("UPDATE problems SET resolved_at = '2020-01-01 00:00:00' WHERE problem_id = ?", [$id]);
        $this->problems->setStatus($id, 'closed');
        $this->assertSame('2020-01-01 00:00:00', $this->db->fetchOne('SELECT resolved_at FROM problems WHERE problem_id = ?', [$id])['resolved_at'], 'closing keeps the original resolve time');
        $this->problems->setStatus($id, 'open');
        $this->assertNull($this->db->fetchOne('SELECT resolved_at FROM problems WHERE problem_id = ?', [$id])['resolved_at'], 'reopening clears it');
    }

    public function testProblemRejectsBadTransitionsStatusesAndMissing(): void
    {
        $id = $this->problems->create('P', null, null);
        $this->problems->setStatus($id, 'closed');
        $this->problems->setStatus($id, 'closed'); // same status: no-op
        foreach ([['bogus', 'Invalid problem status: bogus'], ['resolved', "Cannot move a problem from 'closed' to 'resolved'"]] as [$to, $msg]) {
            try {
                $this->problems->setStatus($id, $to);
                $this->fail('expected exception');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($msg, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('Problem 999 not found');
        $this->problems->setStatus(999, 'open');
    }

    public function testProblemTicketAndChangeLinks(): void
    {
        $p = $this->problems->create('P', null, null);
        $c = $this->changes->create('Fix it', null, null, 'low', null, null, '', null);
        $this->problems->linkChange($p, $c);
        $this->assertEquals($c, $this->db->fetchOne('SELECT change_problem_id FROM problems WHERE problem_id = ?', [$p])['change_problem_id']);
        $this->problems->linkChange($p, null);
        $this->assertNull($this->db->fetchOne('SELECT change_problem_id FROM problems WHERE problem_id = ?', [$p])['change_problem_id']);
        $this->problems->linkTicket($p, 55);
        $this->assertSame([55 => $p], $this->tickets->links);
        $this->problems->unlinkTicket($p + 1, 55);
        $this->assertCount(1, $this->tickets->links, 'unlink only removes the link to that problem');
        $this->problems->unlinkTicket($p, 55);
        $this->assertSame([], $this->tickets->links);
    }

    public function testChangeCreateValidatesRiskAndBlankSchedule(): void
    {
        $id = $this->changes->create('Upgrade', 'why', 'impact', 'high', 'plan', 'rollback', '', 4);
        $row = $this->db->fetchOne('SELECT * FROM changes WHERE change_id = ?', [$id]);
        $this->assertSame('draft', $row['status']);
        $this->assertNull($row['scheduled_at'], 'blank schedule becomes NULL');
        $this->assertSame('high', $row['risk']);
        $this->expectExceptionMessage('Invalid change risk: extreme');
        $this->changes->create('x', null, null, 'extreme', null, null, null, null);
    }

    public function testChangeWorkflow(): void
    {
        $id = $this->changes->create('Upgrade', null, null, 'medium', null, null, null, null);
        $this->changes->setStatus($id, 'awaiting_approval');
        $this->changes->setStatus($id, 'approved');
        try {
            $this->changes->setStatus($id, 'scheduled');
            $this->fail('a scheduled change needs a time');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('A scheduled change needs a scheduled_at time', $e->getMessage());
        }
        $this->changes->setStatus($id, 'scheduled', '2030-01-02 03:04:05');
        $this->assertSame('2030-01-02 03:04:05', $this->db->fetchOne('SELECT scheduled_at FROM changes WHERE change_id = ?', [$id])['scheduled_at']);
        $this->changes->reschedule($id, '2030-02-02 00:00:00');
        $this->changes->setStatus($id, 'in_progress');
        $this->changes->setStatus($id, 'successful');
        $this->changes->setStatus($id, 'rolled_back');
        try {
            $this->changes->setStatus($id, 'draft');
            $this->fail('rolled_back is terminal');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("Cannot move a change from 'rolled_back' to 'draft'", $e->getMessage());
        }
    }

    public function testTransitionTablesCoverEveryStatus(): void
    {
        $this->assertEqualsCanonicalizing(ChangeService::STATUSES, array_keys(ChangeService::TRANSITIONS));
        $this->assertEqualsCanonicalizing(ProblemService::STATUSES, array_keys(ProblemService::TRANSITIONS));
        foreach (ChangeService::TRANSITIONS as $targets) {
            $this->assertEmpty(array_diff($targets, ChangeService::STATUSES));
        }
    }
}
