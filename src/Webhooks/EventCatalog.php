<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * Searchable catalog of the events a webhook (or automation rule) can subscribe to, in the shape of a permission picker:
 * groups you can expand, each event with a name and a one-line description. Core owns the data; editions render the UI.
 *
 * Event ids are dotted lowercase ("ticket.created"). A subscription may also store a wildcard PATTERN ("ticket.*", "*");
 * matchPattern() expands it against this catalog. Events an install has recorded that are not listed here are the
 * edition's "other events seen on this server" (see unknown()).
 *
 * @api
 */
final class EventCatalog
{
    /** @var array<string,string> group key => label, in display order */
    private const GROUPS = [
        'tickets' => 'Tickets',
        'sla' => 'SLA & escalations',
        'approvals' => 'Approvals & service catalog',
        'workflows' => 'Workflows & lifecycle',
        'itil' => 'Problems, changes & knowledge',
        'assets' => 'Assets & network',
        'clients' => 'Clients & contacts',
        'billing' => 'Billing & invoices',
        'security' => 'Security & sign-in',
        'audit' => 'Audit & compliance',
        'system' => 'Backups & system',
        'automation' => 'Automation & jobs',
        'integrations' => 'Integrations',
        'training' => 'Training',
    ];

    private const TICKET_FIELDS = [
        ['ticket_id', 'integer', 'Internal ticket id'],
        ['ticket_number', 'string', 'Display number including prefix'],
        ['ticket_subject', 'string', 'Subject line'],
        ['ticket_priority', 'string', 'Low, Medium, High or Critical'],
        ['ticket_status', 'string', 'Status name'],
        ['client_id', 'integer', 'Client id (0 when none)'],
        ['client_name', 'string', 'Client name'],
        ['contact_id', 'integer', 'Contact id (0 when none)'],
        ['contact_name', 'string', 'Contact name'],
        ['assigned_to_user_id', 'integer', 'Assigned technician id (0 when unassigned)'],
        ['assigned_to_user_name', 'string', 'Assigned technician name'],
    ];

    private const AUDIT_FIELDS = [
        ['summary', 'string', 'One-line human summary'],
        ['action', 'string', 'Action verb, e.g. create, update, delete'],
        ['entity_type', 'string', 'Kind of object affected'],
        ['entity_id', 'string', 'Id of the object affected'],
    ];

    /** @var list<EventDefinition>|null */
    private static ?array $all = null;

    /** @var array<string,EventDefinition>|null */
    private static ?array $index = null;

    /** @return list<EventDefinition> */
    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    /** @return array<string,array{label:string,count:int}> group key => label and number of events (groups with no events are omitted) */
    public static function groups(): array
    {
        $out = [];
        foreach (self::all() as $e) {
            $out[$e->group] ??= ['label' => $e->groupLabel, 'count' => 0];
            $out[$e->group]['count']++;
        }

        return $out;
    }

    /** @return array<string,list<EventDefinition>> */
    public static function byGroup(): array
    {
        $out = [];
        foreach (self::all() as $e) {
            $out[$e->group][] = $e;
        }

        return $out;
    }

    public static function get(string $id): ?EventDefinition
    {
        return self::index()[$id] ?? null;
    }

    public static function has(string $id): bool
    {
        return isset(self::index()[$id]);
    }

    /**
     * Case-insensitive search over id, label, description and tags. Every whitespace-separated term must match somewhere;
     * ranking: id exact, id prefix, label prefix, id/label contains, tag, description. Ties keep catalog order, so the
     * result is deterministic. An empty query lists everything (optionally within one group).
     *
     * @return list<EventDefinition>
     */
    public static function search(string $q, ?string $group = null, int $limit = 200): array
    {
        $limit = max(0, min(1000, $limit));
        $terms = preg_split('/\s+/', mb_strtolower(trim($q)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_slice($terms, 0, 8);
        $scored = [];
        foreach (self::all() as $i => $e) {
            if ($group !== null && $group !== '' && $e->group !== $group) {
                continue;
            }
            $score = 0;
            foreach ($terms as $t) {
                $s = self::termScore($e, $t);
                if ($s === 0) {
                    continue 2;
                }
                $score += $s;
            }
            $scored[] = [$score, $i, $e];
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_slice(array_map(static fn (array $r): EventDefinition => $r[2], $scored), 0, $limit);
    }

    /**
     * Expand a stored pattern against the catalog. "*" matches any run of characters (dots included): "*" is everything,
     * "ticket.*" every ticket event, "auth.login_*" the login events. A plain id returns itself if it is in the catalog.
     *
     * @return list<string> event ids in catalog order
     */
    public static function matchPattern(string $pattern): array
    {
        $pattern = trim($pattern);
        if ($pattern === '' || strlen($pattern) > 150 || !preg_match('/^[a-z0-9_.*-]+$/', $pattern)) {
            return [];
        }
        if (!str_contains($pattern, '*')) {
            return self::has($pattern) ? [$pattern] : [];
        }
        $re = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        $out = [];
        foreach (self::all() as $e) {
            if (preg_match($re, $e->id) === 1) {
                $out[] = $e->id;
            }
        }

        return $out;
    }

    public static function isPattern(string $value): bool
    {
        return str_contains($value, '*');
    }

    /**
     * Ids (or patterns) that are not part of the catalog; a pattern counts as known when it matches at least one entry.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public static function unknown(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (self::matchPattern($id) === []) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<array<string,mixed>> */
    public static function toArray(): array
    {
        return array_map(static fn (EventDefinition $e): array => $e->toArray(), self::all());
    }

    /** JSON for a picker: {groups:{key:{label,count}}, events:[...]}. */
    public static function toJson(): string
    {
        return (string) json_encode(['groups' => self::groups(), 'events' => self::toArray()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------------------------------------------------

    private static function termScore(EventDefinition $e, string $t): int
    {
        $id = strtolower($e->id);
        $label = mb_strtolower($e->label);
        if ($id === $t) {
            return 100;
        }
        if (str_starts_with($id, $t)) {
            return 80;
        }
        if (str_starts_with($label, $t)) {
            return 70;
        }
        if (str_contains($id, $t)) {
            return 50;
        }
        if (str_contains($label, $t)) {
            return 40;
        }
        foreach ($e->tags as $tag) {
            if (str_contains(mb_strtolower($tag), $t)) {
                return 30;
            }
        }
        if (str_contains(mb_strtolower($e->groupLabel), $t)) {
            return 15;
        }
        if (str_contains(mb_strtolower($e->description), $t)) {
            return 10;
        }

        return 0;
    }

    /** @return array<string,EventDefinition> */
    private static function index(): array
    {
        if (self::$index === null) {
            self::$index = [];
            foreach (self::all() as $e) {
                self::$index[$e->id] = $e;
            }
        }

        return self::$index;
    }

    /** @return list<EventDefinition> */
    private static function build(): array
    {
        $out = [];
        foreach (self::rows() as [$group, $id, $label, $desc, $sev, $fields, $tags, $since]) {
            $pf = [];
            foreach ($fields === 'ticket' ? self::TICKET_FIELDS : ($fields === 'audit' ? self::AUDIT_FIELDS : []) as [$p, $ty, $d]) {
                $pf[] = ['path' => $p, 'type' => $ty, 'description' => $d];
            }
            $out[] = new EventDefinition($id, $group, self::GROUPS[$group], $label, $desc, $sev, $pf, $since, $tags === '' ? [] : explode(',', $tags));
        }
        // Catalog order = group order, then definition order.
        $order = array_flip(array_keys(self::GROUPS));
        usort($out, static fn (EventDefinition $a, EventDefinition $b): int => $order[$a->group] <=> $order[$b->group]);

        return $out;
    }

    /**
     * group, id, label, description, severity, payload shape (ticket|audit|none), search tags, since.
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:?string}>
     */
    private static function rows(): array
    {
        $p = 'planned';

        return [
            ['tickets', 'ticket.created', 'Ticket created', 'A new ticket was opened by a client, a technician, email or the API.', 'info', 'ticket', 'new,open,incident,request', null],
            ['tickets', 'ticket.replied', 'Ticket reply', 'A reply or note was added to a ticket.', 'info', 'ticket', 'comment,response,message,note', null],
            ['tickets', 'ticket.assigned', 'Ticket assigned', 'A ticket was assigned or reassigned to a technician.', 'info', 'ticket', 'owner,dispatch,reassign', null],
            ['tickets', 'ticket.status_changed', 'Ticket status changed', 'A ticket moved to a different status.', 'info', 'ticket', 'state,transition,reopen', null],
            ['tickets', 'ticket.resolved', 'Ticket resolved', 'A ticket was marked resolved.', 'info', 'ticket', 'closed,done,complete,solved', null],
            ['tickets', 'ticket.escalated', 'Ticket escalated', 'A ticket was escalated to a higher tier or priority.', 'warning', 'ticket', 'escalation,tier,urgent', $p],

            ['sla', 'sla.warning', 'SLA at risk', 'A ticket is close to breaching its response or resolution target.', 'warning', 'ticket', 'sla,at risk,approaching,timer', $p],
            ['sla', 'sla.breached', 'SLA breached', 'A ticket missed its response or resolution target.', 'critical', 'ticket', 'sla,violation,overdue,missed', $p],

            ['approvals', 'approval.requested', 'Approval requested', 'An approval was requested from an approver, e.g. for a service catalog item.', 'info', 'audit', 'approve,request,catalog,pending', $p],
            ['approvals', 'approval.decided', 'Approval decided', 'An approval was approved or rejected.', 'info', 'audit', 'approved,rejected,decision', $p],

            ['workflows', 'workflow.onboarding_started', 'Onboarding started', 'An onboarding workflow was started for a new employee.', 'info', 'audit', 'new hire,joiner,employee', null],
            ['workflows', 'workflow.offboarding_started', 'Offboarding started', 'An offboarding workflow was started for a departing employee.', 'warning', 'audit', 'leaver,termination,employee,exit', null],
            ['workflows', 'workflow.completed', 'Workflow completed', 'Every task of a workflow run is done.', 'info', 'audit', 'finished,done', null],
            ['workflows', 'workflow.cancelled', 'Workflow cancelled', 'A running workflow was cancelled.', 'warning', 'audit', 'abort,stopped', null],
            ['workflows', 'workflow.task_completed', 'Workflow task completed', 'A task in a workflow run was completed.', 'info', 'audit', 'step,checklist', null],
            ['workflows', 'workflow.action_executed', 'Workflow action executed', 'An automated workflow action ran successfully.', 'info', 'audit', 'step,automation', null],
            ['workflows', 'workflow.action_failed', 'Workflow action failed', 'An automated workflow action failed and needs attention.', 'warning', 'audit', 'error,step,automation', null],
            ['workflows', 'workflow.approval_approved', 'Workflow approval approved', 'An approval gate in a workflow was approved.', 'info', 'audit', 'approve,gate', null],
            ['workflows', 'workflow.approval_rejected', 'Workflow approval rejected', 'An approval gate in a workflow was rejected.', 'warning', 'audit', 'reject,deny,gate', null],
            ['workflows', 'workflow.approval_overridden', 'Workflow approval overridden', 'An administrator overrode a pending workflow approval.', 'warning', 'audit', 'override,admin,gate', null],
            ['workflows', 'workflow.task_webhook', 'Workflow task webhook', 'A workflow task that calls a webhook was dispatched.', 'info', 'audit', 'outbound,call', null],
            ['workflows', 'workflow.template_created', 'Workflow template created', 'A new workflow template was created.', 'info', 'audit', 'blueprint', null],
            ['workflows', 'employee.hired', 'Employee hired', 'An employee record reached its hire date.', 'info', 'audit', 'new hire,joiner,onboarding,start date', null],
            ['workflows', 'employee.terminated', 'Employee terminated', 'An employee record reached its termination date.', 'warning', 'audit', 'leaver,offboarding,exit,end date', null],
            ['workflows', 'contact.hire_date_set', 'Hire date set', 'A hire date was set on a contact.', 'info', 'audit', 'start date,employee', null],
            ['workflows', 'people.import_approved', 'People import approved', 'A directory or CSV import of people was approved and applied.', 'info', 'audit', 'sync,csv,directory,users', null],

            ['itil', 'problem.created', 'Problem created', 'A problem record was opened to track a root cause.', 'info', 'audit', 'itil,root cause', null],
            ['itil', 'problem.status_changed', 'Problem status changed', 'A problem moved to a different status.', 'info', 'audit', 'itil,root cause', null],
            ['itil', 'problem.ticket_linked', 'Ticket linked to problem', 'A ticket was linked to a problem.', 'info', 'audit', 'itil,attach', null],
            ['itil', 'problem.ticket_unlinked', 'Ticket unlinked from problem', 'A ticket was removed from a problem.', 'info', 'audit', 'itil,detach', null],
            ['itil', 'change.created', 'Change created', 'A change request was created.', 'info', 'audit', 'itil,rfc,change management', null],
            ['itil', 'change.status_changed', 'Change status changed', 'A change request moved to a different status.', 'info', 'audit', 'itil,rfc,change management', null],
            ['itil', 'kb_article.restored', 'Knowledge article restored', 'A knowledge base article was restored from a previous revision.', 'info', 'audit', 'kb,wiki,revision,documentation', null],

            ['assets', 'asset.created', 'Asset created', 'A new asset was added to the inventory.', 'info', 'audit', 'device,inventory,cmdb', $p],
            ['assets', 'asset.retired', 'Asset retired', 'An asset was archived or retired.', 'info', 'audit', 'decommission,device,inventory', $p],
            ['assets', 'asset.warranty_expiring', 'Asset warranty expiring', 'An asset warranty is about to expire.', 'warning', 'audit', 'renewal,expiry,device', $p],

            ['clients', 'client.created', 'Client created', 'A new client was added.', 'info', 'audit', 'customer,organization,company', $p],
            ['clients', 'client.archived', 'Client archived', 'A client was archived.', 'warning', 'audit', 'customer,offboard,delete', $p],
            ['clients', 'contact.created', 'Contact created', 'A new contact was added to a client.', 'info', 'audit', 'person,user,customer', $p],

            ['billing', 'invoice.created', 'Invoice created', 'A new invoice was generated.', 'info', 'audit', 'bill,accounting', $p],
            ['billing', 'invoice.paid', 'Invoice paid', 'An invoice was paid in full.', 'info', 'audit', 'payment,accounting', $p],
            ['billing', 'invoice.overdue', 'Invoice overdue', 'An invoice passed its due date unpaid.', 'warning', 'audit', 'late,collections,accounting', $p],
            ['billing', 'payment.received', 'Payment received', 'A payment was recorded.', 'info', 'audit', 'money,accounting,stripe', $p],

            ['security', 'auth.login_success', 'Sign-in succeeded', 'A user signed in.', 'info', 'audit', 'login,logon,authentication', null],
            ['security', 'auth.login_failed', 'Sign-in failed', 'A sign-in attempt failed (wrong password or unknown user).', 'warning', 'audit', 'login,brute force,authentication,password', null],
            ['security', 'auth.login_blocked', 'Sign-in blocked', 'A sign-in was blocked, for example by rate limiting or an access rule.', 'warning', 'audit', 'lockout,rate limit,brute force', null],
            ['security', 'auth.mfa_failed', 'MFA check failed', 'A multi-factor authentication code was wrong.', 'warning', 'audit', '2fa,totp,authentication', null],
            ['security', 'vault.credential_revealed', 'Credential revealed', 'A stored credential was revealed to a user.', 'warning', 'audit', 'password,vault,secret,view', null],
            ['security', 'mcp.identity_linked', 'MCP identity linked', 'An AI/MCP client identity was linked to a user.', 'info', 'audit', 'ai,agent,token', null],
            ['security', 'mcp.identity_unlinked', 'MCP identity unlinked', 'An AI/MCP client identity was unlinked from a user.', 'info', 'audit', 'ai,agent,token', null],
            ['security', 'mcp.settings_changed', 'MCP settings changed', 'The MCP server settings were changed.', 'warning', 'audit', 'ai,agent,config', null],
            ['security', 'webhooks.networks_changed', 'Webhook internal networks changed', 'The list of internal networks webhooks may reach was changed.', 'warning', 'audit', 'ssrf,allowlist,cidr,lan', null],

            ['audit', 'audit.exported', 'Audit log exported', 'The audit trail was exported.', 'info', 'audit', 'download,csv,log', null],
            ['audit', 'compliance.report_exported', 'Compliance report exported', 'A compliance report was exported.', 'info', 'audit', 'download,evidence', null],
            ['audit', 'compliance.report_published', 'Compliance report published', 'A compliance report was published to its audience.', 'info', 'audit', 'share,evidence', null],
            ['audit', 'compliance.report_unpublished', 'Compliance report unpublished', 'A published compliance report was withdrawn.', 'info', 'audit', 'share,evidence', null],
            ['audit', 'compliance.responsibilities_changed', 'Compliance responsibilities changed', 'Control owners or responsibilities were changed.', 'info', 'audit', 'owner,controls', null],
            ['audit', 'compliance.review_recorded', 'Compliance review recorded', 'A periodic control review was recorded.', 'info', 'audit', 'attestation,controls', null],
            ['audit', 'compliance.settings_changed', 'Compliance settings changed', 'Compliance module settings were changed.', 'info', 'audit', 'config', null],
            ['audit', 'compliance.snapshot_taken', 'Compliance snapshot taken', 'A point-in-time compliance snapshot was stored.', 'info', 'audit', 'evidence,history', null],
            ['audit', 'training.ledger_break', 'Training ledger break', 'The tamper-evident training ledger failed verification.', 'critical', 'audit', 'integrity,tamper,hash chain', null],

            ['system', 'redis.cleared', 'Redis cache cleared', 'The Redis cache was flushed.', 'info', 'audit', 'cache', null],
            ['system', 'redis.memory_changed', 'Redis memory limit changed', 'The Redis memory limit or policy was changed.', 'info', 'audit', 'cache,maxmemory', null],
            ['system', 'redis.settings_changed', 'Redis settings changed', 'The Redis connection settings were changed.', 'info', 'audit', 'cache,config', null],
            ['system', 'settings.lifecycle_auto_start_changed', 'Lifecycle auto-start changed', 'Automatic onboarding/offboarding start was switched on or off.', 'info', 'audit', 'employee,config', null],
            ['system', 'backup.completed', 'Backup completed', 'A scheduled or manual backup finished successfully.', 'info', 'audit', 'snapshot,restore', $p],
            ['system', 'backup.failed', 'Backup failed', 'A backup failed to complete.', 'critical', 'audit', 'snapshot,error,restore', $p],

            ['automation', 'automation.rule_toggled', 'Automation rule toggled', 'An automation rule was switched on or off.', 'info', 'audit', 'rule,enable,disable', null],
            ['automation', 'automation.rule_deleted', 'Automation rule deleted', 'An automation rule was deleted.', 'warning', 'audit', 'rule,remove', null],
            ['automation', 'cron.job_started', 'Scheduled job started', 'A scheduled (cron) job was started by an administrator.', 'info', 'audit', 'schedule,run now,task', null],
            ['automation', 'cron.job_refused', 'Scheduled job refused', 'A request to run a scheduled job was refused.', 'warning', 'audit', 'schedule,denied,task', null],
            ['automation', 'cron.schedule_changed', 'Schedule changed', 'The schedule of a cron job was changed.', 'info', 'audit', 'cron,timing,task', null],
            ['automation', 'job.retried', 'Background job retried', 'A failed background job was retried.', 'info', 'audit', 'queue,worker,failed', null],

            ['integrations', 'integration.microsoft.test', 'Microsoft 365 connection tested', 'The Microsoft 365 / Entra integration connection was tested.', 'info', 'audit', 'entra,azure,office,m365', null],
            ['integrations', 'integration.google.test', 'Google connection tested', 'The Google Workspace integration connection was tested.', 'info', 'audit', 'gsuite,workspace', null],
            ['integrations', 'integration.odoo.test', 'Odoo connection tested', 'The Odoo integration connection was tested.', 'info', 'audit', 'erp,accounting', null],

            ['training', 'training.automation_saved', 'Training automation saved', 'A training automation rule was saved.', 'info', 'audit', 'lms,rule', null],
            ['training', 'training.assignment_progress_reset', 'Training progress reset', 'Progress on a training assignment was reset.', 'info', 'audit', 'lms,assignment', null],
            ['training', 'training.assignment_retake', 'Training retake allowed', 'A learner was allowed to retake a training assignment.', 'info', 'audit', 'lms,assignment,exam', null],
            ['training', 'training.assignment_waived', 'Training assignment waived', 'A training assignment was waived for a learner.', 'info', 'audit', 'lms,exempt', null],
            ['training', 'training.assignment_unwaived', 'Training waiver removed', 'A training waiver was removed.', 'info', 'audit', 'lms,exempt', null],
            ['training', 'training.award_manual', 'Training award granted manually', 'A completion or award was granted by hand.', 'info', 'audit', 'lms,certificate', null],
            ['training', 'training.completion_recorded', 'Training completed', 'A learner completed a course.', 'info', 'audit', 'lms,certificate,course', null],
            ['training', 'training.completion_voided', 'Training completion voided', 'A recorded training completion was voided.', 'warning', 'audit', 'lms,certificate,revoke', null],
            ['training', 'training.course_archived', 'Course archived', 'A training course was archived.', 'info', 'audit', 'lms', null],
            ['training', 'training.kiosk_cooldown_cleared', 'Kiosk cooldown cleared', 'A training kiosk cooldown was cleared.', 'info', 'audit', 'lms,pin', null],
            ['training', 'training.kiosk_enroll_codes_issued', 'Kiosk enrolment codes issued', 'Enrolment codes were generated for training kiosks.', 'info', 'audit', 'lms', null],
            ['training', 'training.kiosk_enrolled', 'Kiosk enrolled', 'A training kiosk was enrolled.', 'info', 'audit', 'lms,device', null],
            ['training', 'training.kiosk_expiry_changed', 'Kiosk expiry changed', 'The expiry of a training kiosk was changed.', 'info', 'audit', 'lms,device', null],
            ['training', 'training.kiosk_hidden', 'Kiosk hidden', 'A training kiosk was hidden.', 'info', 'audit', 'lms,device', null],
            ['training', 'training.kiosk_mode_changed', 'Kiosk mode changed', 'The mode of a training kiosk was changed.', 'info', 'audit', 'lms,device', null],
            ['training', 'training.kiosk_revoked', 'Kiosk revoked', 'A training kiosk was revoked.', 'warning', 'audit', 'lms,device', null],
            ['training', 'training.kiosk_settings_changed', 'Kiosk settings changed', 'Training kiosk settings were changed.', 'info', 'audit', 'lms,config', null],
            ['training', 'training.kiosk_token_reissued', 'Kiosk token reissued', 'A training kiosk token was reissued.', 'info', 'audit', 'lms,device', null],
            ['training', 'training.media_purged', 'Training media purged', 'Stored training media was purged.', 'warning', 'audit', 'lms,delete,retention', null],
            ['training', 'training.module_toggled', 'Training module toggled', 'The training module was switched on or off.', 'info', 'audit', 'lms,feature', null],
            ['training', 'training.odoo_discovered', 'Training Odoo discovery run', 'Odoo eLearning courses were discovered.', 'info', 'audit', 'lms,erp', null],
            ['training', 'training.odoo_link_changed', 'Training Odoo link changed', 'A course-to-Odoo link was changed.', 'info', 'audit', 'lms,erp', null],
            ['training', 'training.odoo_links_checked', 'Training Odoo links checked', 'Odoo links were verified.', 'info', 'audit', 'lms,erp', null],
            ['training', 'training.odoo_map_saved', 'Training Odoo mapping saved', 'The Odoo field mapping was saved.', 'info', 'audit', 'lms,erp', null],
            ['training', 'training.odoo_outbox_changed', 'Training Odoo outbox changed', 'An entry in the Odoo write-back outbox was changed.', 'info', 'audit', 'lms,erp,queue', null],
            ['training', 'training.odoo_skill_created', 'Training Odoo skill created', 'A skill was created in Odoo.', 'info', 'audit', 'lms,erp', null],
            ['training', 'training.odoo_target_accepted', 'Training Odoo target accepted', 'An Odoo target was accepted for write-back.', 'info', 'audit', 'lms,erp', null],
            ['training', 'training.odoo_writeback_saved', 'Training Odoo write-back saved', 'Odoo write-back settings were saved.', 'info', 'audit', 'lms,erp', null],
            ['training', 'training.pin_odoo_unblocked', 'Training PIN unblocked via Odoo', 'A training PIN was unblocked through Odoo.', 'info', 'audit', 'lms,pin', null],
            ['training', 'training.pin_slips_issued', 'Training PIN slips issued', 'PIN slips were issued.', 'info', 'audit', 'lms,pin', null],
            ['training', 'training.pin_sources_refreshed', 'Training PIN sources refreshed', 'PIN sources were refreshed.', 'info', 'audit', 'lms,pin', null],
            ['training', 'training.pin_unlocked', 'Training PIN unlocked', 'A locked training PIN was unlocked.', 'info', 'audit', 'lms,pin', null],
            ['training', 'training.requirement_saved', 'Training requirement saved', 'A training requirement was saved.', 'info', 'audit', 'lms,policy', null],
            ['training', 'training.revision_published', 'Course revision published', 'A new course revision was published.', 'info', 'audit', 'lms,version', null],
            ['training', 'training.run_unlocked', 'Training run unlocked', 'A locked training run was unlocked.', 'info', 'audit', 'lms,exam', null],
            ['training', 'training.settings_changed', 'Training settings changed', 'Training module settings were changed.', 'info', 'audit', 'lms,config', null],
            ['training', 'training.trainer_pin_set', 'Trainer PIN set', 'A trainer PIN was set.', 'info', 'audit', 'lms,pin', null],
        ];
    }
}
