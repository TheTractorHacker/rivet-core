<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * Normalises any event ("ticket.created" + its data array) into the small, safe summary every chat/notification
 * formatter renders: {type, title, summary, url, severity, actor, client, fields[]}. Known families (tickets, SLA,
 * approvals, workflows, assets, security/audit, backups, billing) get specific wording; unknown events still produce a
 * sane title from the dotted type and top-level scalar keys. Pure; never reads nested metadata blobs and never copies
 * values of secret-looking keys.
 *
 * @api
 */
final class EventSummary
{
    public const MAX_FIELDS = 10;
    public const MAX_TITLE = 200;
    public const MAX_SUMMARY = 500;
    public const MAX_FIELD_NAME = 40;
    public const MAX_FIELD_VALUE = 200;

    /** Keys whose values are never copied into a summary. */
    private const SECRET_KEY = '/pass(word|wd)?|secret|token|api[_-]?key|authorization|cookie|credential_value|private|signature|bearer|otp|totp|session/i';

    private const TICKET_LABELS = [
        'ticket.created' => 'New ticket',
        'ticket.replied' => 'New reply on ticket',
        'ticket.assigned' => 'Ticket assigned',
        'ticket.status_changed' => 'Ticket status changed',
        'ticket.resolved' => 'Ticket resolved',
    ];

    /** Data keys that are shown as labelled fields for ticket-shaped events, in order. */
    private const TICKET_FIELDS = [
        'ticket_priority' => 'Priority', 'client_name' => 'Client', 'ticket_status' => 'Status', 'assigned_to_user_name' => 'Assigned to', 'contact_name' => 'Contact',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array{type:string,title:string,summary:string,url:?string,severity:string,actor:string,client:string,fields:list<array{name:string,value:string}>}
     */
    public static function fromEvent(string $type, array $data): array
    {
        $type = self::clean(mb_substr($type, 0, 150));
        $def = EventCatalog::get($type);
        $family = explode('.', $type)[0];
        $fields = [];
        $client = self::scalar($data['client_name'] ?? ($data['client'] ?? ''));
        $actor = '';
        foreach (['actor', 'actor_name', 'user_name', 'username', 'user'] as $k) {
            if (($actor = self::scalar($data[$k] ?? '')) !== '') {
                break;
            }
        }
        $severity = $def->severity ?? 'info';

        if (isset($data['ticket_subject']) || isset($data['ticket_number'])) {
            $title = self::TICKET_LABELS[$type] ?? ($def->label ?? self::humanize($type));
            $number = self::scalar($data['ticket_number'] ?? '');
            $summary = trim(($number !== '' ? $number . ' ' : '') . self::scalar($data['ticket_subject'] ?? ''));
            foreach (self::TICKET_FIELDS as $key => $label) {
                $v = self::scalar($data[$key] ?? '');
                if ($v !== '') {
                    $fields[] = ['name' => $label, 'value' => $v];
                }
            }
            $prio = self::scalar($data['ticket_priority'] ?? '');
            if ($prio === 'Critical') {
                $severity = 'critical';
            } elseif ($prio === 'High' && $severity === 'info') {
                $severity = 'warning';
            }
            $actor = $actor !== '' ? $actor : self::scalar($data['assigned_to_user_name'] ?? '');
        } else {
            $title = $def->label ?? self::humanize($type);
            $summary = self::scalar($data['summary'] ?? '');
            if ($summary === '') {
                foreach (['message', 'title', 'subject', 'name', 'description'] as $k) {
                    if (($summary = self::scalar($data[$k] ?? '')) !== '') {
                        break;
                    }
                }
            }
            if ($summary === '') {
                $summary = $def->description ?? self::humanize($type);
            }
            foreach (['action' => 'Action', 'entity_type' => 'Entity', 'entity_id' => 'Entity ID'] as $k => $label) {
                $v = self::scalar($data[$k] ?? '');
                if ($v !== '') {
                    $fields[] = ['name' => $label, 'value' => $v];
                }
            }
            // Other top-level scalar keys, so unknown events still carry something useful.
            $shown = ['summary', 'message', 'title', 'subject', 'name', 'description', 'action', 'entity_type', 'entity_id', 'actor', 'actor_name', 'user_name', 'username', 'user', 'url', 'link', 'severity'];
            foreach ($data as $k => $v) {
                if (count($fields) >= self::MAX_FIELDS) {
                    break;
                }
                $k = (string) $k;
                if (in_array($k, $shown, true) || preg_match(self::SECRET_KEY, $k) === 1 || !is_scalar($v)) {
                    continue;
                }
                $sv = self::scalar($v);
                if ($sv !== '') {
                    $fields[] = ['name' => self::humanize($k, false), 'value' => $sv];
                }
            }
        }
        if (isset($data['severity']) && is_string($data['severity']) && in_array(strtolower($data['severity']), ['info', 'warning', 'critical'], true)) {
            $severity = strtolower($data['severity']);
        }

        $url = null;
        foreach (['url', 'link', 'ticket_url'] as $k) {
            $u = $data[$k] ?? null;
            if (is_string($u) && self::isSafeUrl($u)) {
                $url = $u;
                break;
            }
        }

        $fields = array_slice($fields, 0, self::MAX_FIELDS);

        return [
            'type' => $type,
            'title' => self::clip($title, self::MAX_TITLE),
            'summary' => self::clip($summary, self::MAX_SUMMARY),
            'url' => $url,
            'severity' => $severity,
            'actor' => self::clip($actor, 100),
            'client' => self::clip($client, 100),
            'fields' => array_map(static fn (array $f): array => ['name' => self::clip($f['name'], self::MAX_FIELD_NAME), 'value' => self::clip($f['value'], self::MAX_FIELD_VALUE)], $fields),
        ];
    }

    /** http(s) URL with no whitespace, quotes, angle brackets, pipes or credentials, at most 1000 characters. */
    public static function isSafeUrl(string $u): bool
    {
        return strlen($u) <= 1000 && preg_match('#^https?://[^\s<>"\'|\\\\@]+$#i', $u) === 1 && parse_url($u, PHP_URL_HOST) !== null;
    }

    /** Remove control characters (keeping nothing but printable text), scrub invalid UTF-8 and trim. */
    public static function clean(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');
        $s = preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $s) ?? '';

        return trim($s);
    }

    public static function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, max(0, $max - 1)) . "\u{2026}" : $s;
    }

    private static function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }

        return is_scalar($v) ? self::clean((string) $v) : '';
    }

    private static function humanize(string $s, bool $dotted = true): string
    {
        $s = trim(str_replace($dotted ? ['.', '_'] : ['_', '.'], ' ', self::clean($s)));
        if ($s === '') {
            return 'Event';
        }

        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
