# ITSM (problems and changes)

`RivetCore\ITSM`: `ProblemService` and `ChangeService`, deliberately lightweight. A problem is a root-cause record that tickets
(incidents) link to through the edition; a problem can point forward to the change meant to fix it. There is no incident table:
an incident is a ticket, and tickets belong to the edition.

## What it owns

- `problems` (migration 0004): `problem_id`, `title`, `description`, `status`, `change_problem_id`, `created_by`, `created_at`, `resolved_at`.
- `changes` (0004): `change_id`, `title`, `reason`, `impact`, `risk`, `implementation_plan`, `rollback_plan`, `scheduled_at`, `status`, `created_by`, `created_at`.

Statuses and transition tables are public constants (`ProblemService::STATUSES/TRANSITIONS`, `ChangeService::STATUSES/RISKS/TRANSITIONS`)
so a UI renders from them instead of mirroring them by hand.

## You supply

A `TicketProblemLinkInterface` (`link`, `unlink`) over wherever the edition stores tickets. Core never touches the tickets table.

## Flags

None.

## Use it

<!-- run -->
```php
use RivetCore\ITSM\{ChangeService, ProblemService, TicketProblemLinkInterface};

$link = new class implements TicketProblemLinkInterface {
    public function link(int $ticketId, int $problemId): void {}
    public function unlink(int $ticketId, int $problemId): void {}
};
$problems = new ProblemService($db, $link);
$id = $problems->create('Printer keeps jamming', 'Seen on three tickets', 7);
$problems->setStatus($id, 'investigating');
$changeId = (new ChangeService($db))->create('Replace fuser', 'Root cause', 'One floor offline', 'low', 'Swap unit', 'Swap back', null, 7);
$problems->linkChange($id, $changeId);
echo "problem $id -> change $changeId\n";
```

## How it fails

An unknown status or a transition the table does not allow (for example `closed -> resolved`, or `draft -> in_progress` for a
change) throws `InvalidArgumentException`/`RuntimeException` and writes nothing. `resolved_at` is stamped the first time a problem reaches `resolved` or `closed` and cleared when it is reopened. Storage failures throw `DatabaseException`.
