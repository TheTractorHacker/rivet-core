<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Parser for the Uptime Kuma "Webhook" notification with the default JSON body:
 *
 *   {"heartbeat":{"monitorID":1,"status":0,"time":"2026-11-13 10:00:00.000","msg":"...","ping":null,"important":true,"duration":0},
 *    "monitor":{"id":1,"name":"...","url":"...","hostname":null,"port":null,"type":"http","tags":[{"name":"client","value":"acme"}]},
 *    "msg":"..."}
 *
 * Status 0 DOWN, 1 UP, 2 PENDING, 3 MAINTENANCE. The "Test" button sends a body with a null heartbeat (and monitor): that is accepted
 * and returned as a test event. Strict: the top level must be a JSON object (nesting at most 6), heartbeat and monitor must each be an
 * object or null, the status an integer 0..3, the monitor id a positive integer (a monitor object with no id falls back to heartbeat.monitorID;
 * if both are present they must agree), time a string UTC "Y-m-d H:i:s[.v]" or ISO 8601 (absent or null is allowed: the engine then uses the arrival time).
 * Unknown members are ignored, never echoed. Strings lose control characters and bidi overrides and are cut to a fixed length (mb, UTF-8
 * safe). The body is capped at {@see self::MAX_BODY} bytes.
 *
 * @api
 */
final class UptimeKumaPayload implements PayloadParserInterface
{
    public const SOURCE = 'uptime-kuma';
    public const MAX_BODY = 65536;
    public const MAX_NAME = 200;
    public const MAX_MESSAGE = 1000;
    public const MAX_URL = 500;
    public const MAX_HOSTNAME = 255;
    public const MAX_TYPE = 32;
    public const MAX_TAGS = 20;
    public const MAX_TAG_PART = 100;
    public const MAX_DEPTH = 6;

    public function source(): string
    {
        return self::SOURCE;
    }

    public function parse(string $rawBody): InboundEvent|PayloadRejection
    {
        if (strlen($rawBody) > self::MAX_BODY) {
            return new PayloadRejection(PayloadRejection::TOO_LARGE, 'The request body is too large.');
        }
        try {
            $data = json_decode($rawBody, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new PayloadRejection(PayloadRejection::INVALID_JSON, 'The request body is not valid JSON.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return new PayloadRejection(PayloadRejection::NOT_AN_OBJECT, 'The request body must be a JSON object.');
        }

        $hb = $data['heartbeat'] ?? null;
        $mon = $data['monitor'] ?? null;
        if (($hb !== null && !self::isObject($hb)) || ($mon !== null && !self::isObject($mon))) {
            return new PayloadRejection(PayloadRejection::BAD_FIELD, 'The heartbeat and monitor members must be objects or null.');
        }
        $topMsg = $data['msg'] ?? '';
        if (!is_string($topMsg)) {
            return new PayloadRejection(PayloadRejection::BAD_FIELD, 'A field has the wrong type.');
        }

        if ($hb === null) {
            // The "Test" notification (or anything with no heartbeat): nothing to act on.
            $name = is_array($mon) && is_string($mon['name'] ?? null) ? self::clean($mon['name'], self::MAX_NAME) : '';

            return new InboundEvent(self::SOURCE, true, null, '', $name, null, self::clean($topMsg, self::MAX_MESSAGE));
        }

        $status = $hb['status'] ?? null;
        if (!is_int($status) || MonitorStatus::tryFrom($status) === null) {
            return new PayloadRejection(PayloadRejection::BAD_STATUS, 'The heartbeat status must be 0, 1, 2 or 3.');
        }

        $hbId = self::monitorId($hb['monitorID'] ?? null);
        $monId = is_array($mon) ? self::monitorId($mon['id'] ?? null) : null;
        if ((($hb['monitorID'] ?? null) !== null && $hbId === null) || (is_array($mon) && ($mon['id'] ?? null) !== null && $monId === null)) {
            return new PayloadRejection(PayloadRejection::NO_MONITOR, 'The monitor id must be a positive integer.');
        }
        if ($hbId !== null && $monId !== null && $hbId !== $monId) {
            return new PayloadRejection(PayloadRejection::NO_MONITOR, 'The heartbeat and monitor ids disagree.');
        }
        $id = $monId ?? $hbId;
        if ($id === null) {
            return new PayloadRejection(PayloadRejection::NO_MONITOR, 'The delivery names no monitor.');
        }

        $time = null;
        $rawTime = $hb['time'] ?? null;
        if ($rawTime !== null) {
            $time = is_string($rawTime) ? self::parseTime($rawTime) : null;
            if ($time === null) {
                return new PayloadRejection(PayloadRejection::BAD_TIME, 'The heartbeat time is not a valid UTC time.');
            }
        }

        $m = is_array($mon) ? $mon : [];
        foreach (['name', 'url', 'hostname', 'type'] as $k) {
            if (isset($m[$k]) && !is_string($m[$k])) {
                return new PayloadRejection(PayloadRejection::BAD_FIELD, 'A field has the wrong type.');
            }
        }
        if (isset($hb['msg']) && !is_string($hb['msg'])) {
            return new PayloadRejection(PayloadRejection::BAD_FIELD, 'A field has the wrong type.');
        }
        $tags = self::tags($m['tags'] ?? null);
        if ($tags === null) {
            return new PayloadRejection(PayloadRejection::BAD_FIELD, 'The tags must be a list of objects.');
        }

        $name = self::clean((string) ($m['name'] ?? ''), self::MAX_NAME);
        $msg = self::clean((string) ($hb['msg'] ?? ''), self::MAX_MESSAGE);
        if ($msg === '') {
            $msg = self::clean($topMsg, self::MAX_MESSAGE);
        }

        return new InboundEvent(
            self::SOURCE,
            false,
            MonitorStatus::from($status),
            (string) $id,
            $name !== '' ? $name : 'Monitor ' . $id,
            $time,
            $msg,
            self::clean((string) ($m['url'] ?? ''), self::MAX_URL),
            self::clean((string) ($m['hostname'] ?? ''), self::MAX_HOSTNAME),
            self::clean((string) ($m['type'] ?? ''), self::MAX_TYPE),
            $tags,
        );
    }

    /** Control characters (C0, DEL, C1, line and paragraph separators), bidi overrides/isolates and the BOM become nothing; then trim and cut to $max characters. */
    public static function clean(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{FEFF}]/u', '', $s) ?? '';

        return trim(mb_substr($s, 0, $max, 'UTF-8'));
    }

    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    private static function monitorId(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && preg_match('/^[1-9][0-9]{0,17}$/', $v) === 1) {
            return (int) $v;
        }

        return null;
    }

    /** @return list<string>|null null = malformed */
    private static function tags(mixed $raw): ?array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $out = [];
        foreach (array_slice($raw, 0, self::MAX_TAGS) as $t) {
            if (!is_array($t) || !is_string($t['name'] ?? null) || (isset($t['value']) && !is_string($t['value']) && !is_int($t['value']))) {
                return null;
            }
            $n = self::clean($t['name'], self::MAX_TAG_PART);
            $v = self::clean((string) ($t['value'] ?? ''), self::MAX_TAG_PART);
            if ($n !== '') {
                $out[] = $v !== '' ? $n . ':' . $v : $n;
            }
        }

        return $out;
    }

    private static function parseTime(string $s): ?int
    {
        $s = trim($s);
        if ($s === '' || strlen($s) > 40) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})?$/', $s) !== 1) {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        $ts = $d->getTimestamp();

        return $ts > 946684800 ? $ts : null;   // before 2000 is a bug in the sender, not a time
    }
}
