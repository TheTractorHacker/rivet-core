<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * Renders one event into the request body a destination platform expects. Pure (no I/O, no clock): everything comes in
 * through the arguments, so the output is deterministic and a retry sends identical bytes.
 *
 * $event is {event: string, timestamp: string, data: array} (the generic envelope). Formats:
 *   json               the generic envelope, byte-identical to what WebhookDispatcher has always sent
 *   form               application/x-www-form-urlencoded with flattened keys (event, timestamp, data.a.b, ...)
 *   slack              Block Kit message for a Slack incoming webhook
 *   slack_attachments  legacy attachments, for Mattermost and Rocket.Chat (they do not render Block Kit)
 *   teams              Adaptive Card message for a Teams "Workflows" webhook
 *   discord            content/embeds for a Discord webhook (2000/4096/6000 character limits respected)
 *   ntfy               plain text body + Title/Priority/Tags/Click headers
 *   gotify             {title, message, priority, extras}
 *   telegram           {chat_id, text, parse_mode: HTML}  (option chat_id is required)
 *   matrix             m.room.message content for the Matrix client API
 *   matrix_hookshot    {text, html, username} for a matrix-hookshot generic webhook
 *   apprise            {title, body, type, format} for Apprise API
 *   template           an admin-written PayloadTemplate (options template, template_encoding)
 *
 * User text (subjects, client names, audit summaries) is untrusted: it is escaped for each platform so it can neither
 * mention people nor inject markup or links, truncated to the platform's limits, and secret-looking keys are never
 * copied. Options: app_name, test (bool), link_url, link_label, chat_id, username, content, ntfy_priority (1-5),
 * ntfy_tags, gotify_priority (0-10), apprise_tag, template, template_encoding.
 *
 * @api
 */
final class PayloadFormatter
{
    public const FORMATS = ['json', 'form', 'slack', 'slack_attachments', 'teams', 'discord', 'ntfy', 'gotify', 'telegram', 'matrix', 'matrix_hookshot', 'apprise', 'template'];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const JSON_SAFE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    private const JSON_CT = 'application/json; charset=utf-8';
    private const SECRET_KEY = '/pass(word|wd)?|secret|token|api[_-]?key|authorization|cookie|credential_value|private|signature|bearer|otp|totp|session/i';
    private const MAX_FORM_KEYS = 200;
    private const MAX_FORM_VALUE = 2000;

    /** @return list<string> */
    public static function formats(): array
    {
        return self::FORMATS;
    }

    public static function isFormat(string $format): bool
    {
        return in_array($format, self::FORMATS, true);
    }

    /**
     * @param array<string,mixed> $event {event, timestamp, data}
     * @param array<string,mixed> $options
     * @throws \InvalidArgumentException unknown format, missing required option (telegram chat_id, template) or invalid template
     */
    public static function format(string $format, array $event, array $options = []): FormattedPayload
    {
        $type = is_string($event['event'] ?? null) ? $event['event'] : '';
        $timestamp = is_string($event['timestamp'] ?? null) ? $event['timestamp'] : '';
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if ($format === 'json') {
            $body = json_encode(['event' => $type, 'timestamp' => $timestamp, 'data' => $data], self::JSON_FLAGS);
            if ($body === false) {
                $body = json_encode(['event' => $type, 'timestamp' => $timestamp, 'data' => $data], self::JSON_SAFE_FLAGS);
            }

            return new FormattedPayload($body === false ? '{}' : $body, 'application/json');
        }
        if ($format === 'form') {
            return self::form($type, $timestamp, $data);
        }

        $s = EventSummary::fromEvent($type, $data);
        $app = EventSummary::clip(EventSummary::clean((string) ($options['app_name'] ?? 'RivetCore')), 40);
        if (!empty($options['test'])) {
            $s['title'] = 'TEST MESSAGE from ' . $app;
            $s['summary'] = $s['summary'] !== '' ? $s['summary'] : 'This is a test message. Nothing is wrong.';
        }
        $link = $options['link_url'] ?? null;
        if (is_string($link) && $link !== '') {
            $s['url'] = EventSummary::isSafeUrl($link) ? $link : null;
        }
        $label = EventSummary::clip(EventSummary::clean((string) ($options['link_label'] ?? 'Open')), 40) ?: 'Open';

        return match ($format) {
            'slack' => self::slack($s, $label),
            'slack_attachments' => self::slackAttachments($s, $label),
            'teams' => self::teams($s, $label),
            'discord' => self::discord($s, $timestamp, $app, $options),
            'ntfy' => self::ntfy($s, $options),
            'gotify' => self::gotify($s, $options),
            'telegram' => self::telegram($s, $label, $options),
            'matrix' => self::matrix($s, $label, false, $options),
            'matrix_hookshot' => self::matrix($s, $label, true, $options),
            'apprise' => self::apprise($s, $options),
            'template' => self::template($type, $timestamp, $data, $s, $options),
            default => throw new \InvalidArgumentException('Unknown webhook format "' . $format . '".'),
        };
    }

    // ----- escaping ---------------------------------------------------------------------------------------------

    /** Slack: entity-escape (&, <, >) and defang the broadcast keywords, so <!channel>, <@U1> and <http://x|y> are inert. */
    public static function slackEscape(string $text): string
    {
        $text = EventSummary::clean($text);
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        return (string) preg_replace('/@(channel|here|everyone)\b/i', "@\u{200B}$1", $text);
    }

    /**
     * Markdown (Discord, Mattermost, Rocket.Chat, Teams): backslash-escape control characters, neutralise angle brackets
     * (mentions, HTML, autolinks) and break @-mentions of broadcast keywords.
     */
    public static function markdownEscape(string $text, bool $teams = false): string
    {
        $text = EventSummary::clean($text);
        $text = (string) preg_replace('/([\\\\`*_{}\[\]()#+!|~>-])/', '\\\\$1', $text);
        $text = str_replace(['<', '&'], ["\u{FF1C}", $teams ? "\u{FF06}" : '&'], $text);

        return (string) preg_replace('/@(channel|here|everyone|all)\b/i', "@\u{200B}$1", $text);
    }

    // ----- chat formats -----------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $s
     * @return FormattedPayload
     */
    private static function slack(array $s, string $label): FormattedPayload
    {
        $fields = [];
        foreach (array_slice($s['fields'], 0, 10) as $f) {
            $fields[] = ['type' => 'plain_text', 'text' => EventSummary::clip($f['name'] . ': ' . $f['value'], 150), 'emoji' => false];
        }
        $section = ['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => EventSummary::clip($s['summary'] !== '' ? $s['summary'] : $s['title'], 3000), 'emoji' => false]];
        if ($fields !== []) {
            $section['fields'] = $fields;
        }
        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => EventSummary::clip($s['title'], 150), 'emoji' => false]],
            $section,
        ];
        if ($s['url'] !== null) {
            $blocks[] = ['type' => 'actions', 'elements' => [['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $label, 'emoji' => false], 'url' => $s['url']]]];
        }
        $fallback = $s['title'] . ': ' . $s['summary'];
        foreach ($s['fields'] as $f) {
            if ($f['name'] === 'Priority' || $f['name'] === 'Client') {
                $fallback .= ' | ' . $f['name'] . ': ' . $f['value'];
            }
        }

        return self::json(['text' => EventSummary::clip(self::slackEscape($fallback), 3000), 'blocks' => $blocks, 'mrkdwn' => false]);
    }

    /** @param array<string,mixed> $s */
    private static function slackAttachments(array $s, string $label): FormattedPayload
    {
        $fields = [];
        foreach (array_slice($s['fields'], 0, 10) as $f) {
            $fields[] = ['title' => self::markdownEscape($f['name']), 'value' => self::markdownEscape($f['value']), 'short' => true];
        }
        $att = [
            'fallback' => EventSummary::clip(self::markdownEscape($s['title'] . ': ' . $s['summary']), 500),
            'color' => self::color($s['severity']),
            'title' => self::markdownEscape($s['title']),
            'text' => self::markdownEscape($s['summary']),
            'fields' => $fields,
        ];
        if ($s['url'] !== null) {
            $att['title_link'] = $s['url'];
        }

        return self::json(['text' => EventSummary::clip(self::markdownEscape($s['title']), 1000), 'attachments' => [$att]]);
    }

    /** @param array<string,mixed> $s */
    private static function teams(array $s, string $label): FormattedPayload
    {
        $body = [
            ['type' => 'TextBlock', 'text' => self::markdownEscape($s['title'], true), 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true,
                'color' => $s['severity'] === 'info' ? 'Default' : ($s['severity'] === 'warning' ? 'Warning' : 'Attention')],
            ['type' => 'TextBlock', 'text' => self::markdownEscape($s['summary'], true), 'wrap' => true, 'spacing' => 'Small'],
        ];
        if ($s['fields'] !== []) {
            $facts = [];
            foreach (array_slice($s['fields'], 0, 10) as $f) {
                $facts[] = ['title' => self::markdownEscape($f['name'], true), 'value' => self::markdownEscape($f['value'], true)];
            }
            $body[] = ['type' => 'FactSet', 'facts' => $facts];
        }
        $card = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => $body, 'msteams' => ['width' => 'Full']];
        if ($s['url'] !== null) {
            $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => $label, 'url' => $s['url']]];
        }

        return self::json(['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $card]]]);
    }

    /**
     * Discord limits: content 2000, embed title 256, description 4096, 25 fields (name 256, value 1024), footer 2048,
     * and 6000 characters across one embed.
     *
     * @param array<string,mixed> $s
     * @param array<string,mixed> $options
     */
    private static function discord(array $s, string $timestamp, string $app, array $options): FormattedPayload
    {
        $embed = [
            'title' => EventSummary::clip(self::markdownEscape($s['title']), 256),
            'description' => EventSummary::clip(self::markdownEscape($s['summary']), 4096),
            'color' => self::colorInt($s['severity']),
        ];
        if ($s['url'] !== null) {
            $embed['url'] = $s['url'];
        }
        $fields = [];
        foreach (array_slice($s['fields'], 0, 25) as $f) {
            $fields[] = ['name' => EventSummary::clip(self::markdownEscape($f['name']), 256) ?: "\u{200B}", 'value' => EventSummary::clip(self::markdownEscape($f['value']), 1024) ?: "\u{200B}", 'inline' => true];
        }
        if ($fields !== []) {
            $embed['fields'] = $fields;
        }
        $embed['footer'] = ['text' => EventSummary::clip(self::markdownEscape($app), 2048)];
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $timestamp) === 1) {
            $embed['timestamp'] = $timestamp;
        }
        // 6000 characters across title, description, footer and every field
        $total = static function (array $e): int {
            $n = mb_strlen($e['title']) + mb_strlen($e['description']) + mb_strlen($e['footer']['text']);
            foreach ($e['fields'] ?? [] as $f) {
                $n += mb_strlen($f['name']) + mb_strlen($f['value']);
            }

            return $n;
        };
        while ($total($embed) > 6000 && !empty($embed['fields'])) {
            array_pop($embed['fields']);
        }
        if ($total($embed) > 6000) {
            $embed['description'] = EventSummary::clip($embed['description'], max(0, 6000 - mb_strlen($embed['title']) - mb_strlen($embed['footer']['text'])));
        }
        if (empty($embed['fields'])) {
            unset($embed['fields']);
        }
        $payload = ['allowed_mentions' => ['parse' => []]];
        $content = EventSummary::clean((string) ($options['content'] ?? ''));
        if ($content !== '') {
            $payload['content'] = EventSummary::clip(self::markdownEscape($content), 2000);
        }
        $user = EventSummary::clean((string) ($options['username'] ?? ''));
        if ($user !== '') {
            $payload['username'] = EventSummary::clip($user, 80);
        }
        $payload['embeds'] = [$embed];

        return self::json($payload);
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $options
     */
    private static function ntfy(array $s, array $options): FormattedPayload
    {
        $prio = match ($s['severity']) { 'critical' => 5, 'warning' => 4, default => 3 };
        if (isset($options['ntfy_priority']) && is_numeric($options['ntfy_priority'])) {
            $prio = max(1, min(5, (int) $options['ntfy_priority']));
        }
        $tags = [match ($s['severity']) { 'critical' => 'rotating_light', 'warning' => 'warning', default => 'bell' }];
        foreach (explode(',', (string) ($options['ntfy_tags'] ?? '')) as $t) {
            $t = preg_replace('/[^A-Za-z0-9_+-]/', '', trim($t)) ?? '';
            if ($t !== '' && count($tags) < 10) {
                $tags[] = substr($t, 0, 40);
            }
        }
        $headers = [
            'Title' => self::headerValue(EventSummary::clip($s['title'], 200)),
            'Priority' => (string) $prio,
            'Tags' => implode(',', array_unique($tags)),
        ];
        if ($s['url'] !== null) {
            $headers['Click'] = $s['url'];
        }
        $text = self::plain($s);
        while (strlen($text) > 4096) {
            $text = mb_substr($text, 0, mb_strlen($text) - 64);
        }

        return new FormattedPayload($text, 'text/plain; charset=utf-8', $headers);
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $options
     */
    private static function gotify(array $s, array $options): FormattedPayload
    {
        $prio = match ($s['severity']) { 'critical' => 9, 'warning' => 6, default => 3 };
        if (isset($options['gotify_priority']) && is_numeric($options['gotify_priority'])) {
            $prio = max(0, min(10, (int) $options['gotify_priority']));
        }
        $payload = ['title' => $s['title'], 'message' => self::plain($s), 'priority' => $prio];
        if ($s['url'] !== null) {
            $payload['extras'] = ['client::notification' => ['click' => ['url' => $s['url']]]];
        }

        return self::json($payload);
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $options
     */
    private static function telegram(array $s, string $label, array $options): FormattedPayload
    {
        $chat = trim((string) ($options['chat_id'] ?? ''));
        if ($chat === '' || preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{3,64})$/', $chat) !== 1) {
            throw new \InvalidArgumentException('Telegram needs a chat_id (a number such as -1001234567890, or @channelname).');
        }
        $esc = static fn (string $t): string => htmlspecialchars($t, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $build = static function (bool $withFields) use ($s, $esc, $label): string {
            $t = '<b>' . $esc($s['title']) . '</b>';
            if ($s['summary'] !== '') {
                $t .= "\n" . $esc($s['summary']);
            }
            if ($withFields) {
                foreach ($s['fields'] as $f) {
                    $t .= "\n<b>" . $esc($f['name']) . ':</b> ' . $esc($f['value']);
                }
            }
            if ($s['url'] !== null) {
                $t .= "\n<a href=\"" . htmlspecialchars($s['url'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $esc($label) . '</a>';
            }

            return $t;
        };
        $text = $build(true);
        if (mb_strlen($text) > 4096) {
            $text = $build(false);
        }
        if (mb_strlen($text) > 4096) {
            $text = '<b>' . $esc(EventSummary::clip($s['title'], 200)) . '</b>';
        }

        return self::json([
            'chat_id' => preg_match('/^-?\d+$/', $chat) === 1 ? (int) $chat : $chat,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $options
     */
    private static function matrix(array $s, string $label, bool $hookshot, array $options): FormattedPayload
    {
        $esc = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<strong>' . $esc($s['title']) . '</strong>';
        if ($s['summary'] !== '') {
            $html .= '<br>' . $esc($s['summary']);
        }
        foreach ($s['fields'] as $f) {
            $html .= '<br><em>' . $esc($f['name']) . ':</em> ' . $esc($f['value']);
        }
        if ($s['url'] !== null) {
            $html .= '<br><a href="' . $esc($s['url']) . '">' . $esc($label) . '</a>';
        }
        $text = self::plain($s) . ($s['url'] !== null ? "\n" . $s['url'] : '');
        if ($hookshot) {
            $p = ['text' => $text, 'html' => $html];
            $user = EventSummary::clean((string) ($options['username'] ?? ''));
            if ($user !== '') {
                $p['username'] = EventSummary::clip($user, 80);
            }

            return self::json($p);
        }

        return self::json(['msgtype' => 'm.text', 'body' => $text, 'format' => 'org.matrix.custom.html', 'formatted_body' => $html]);
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $options
     */
    private static function apprise(array $s, array $options): FormattedPayload
    {
        $p = [
            'title' => EventSummary::clip($s['title'], 250),
            'body' => EventSummary::clip(self::plain($s) . ($s['url'] !== null ? "\n" . $s['url'] : ''), 4000),
            'type' => match ($s['severity']) { 'critical' => 'failure', 'warning' => 'warning', default => 'info' },
            'format' => 'text',
        ];
        $tag = preg_replace('/[^A-Za-z0-9_,&-]/', '', (string) ($options['apprise_tag'] ?? '')) ?? '';
        if ($tag !== '') {
            $p['tag'] = substr($tag, 0, 100);
        }

        return self::json($p);
    }

    // ----- generic formats --------------------------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private static function form(string $type, string $timestamp, array $data): FormattedPayload
    {
        $flat = ['event' => $type, 'timestamp' => $timestamp];
        self::flatten(self::redact($data), 'data', $flat, 0);

        return new FormattedPayload(http_build_query($flat, '', '&', PHP_QUERY_RFC3986), 'application/x-www-form-urlencoded');
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $s
     * @param array<string,mixed> $options
     */
    private static function template(string $type, string $timestamp, array $data, array $s, array $options): FormattedPayload
    {
        $template = (string) ($options['template'] ?? '');
        $enc = (string) ($options['template_encoding'] ?? 'json');
        if (trim($template) === '') {
            throw new \InvalidArgumentException('The custom template format needs a template.');
        }
        $body = PayloadTemplate::render($template, ['event' => $type, 'timestamp' => $timestamp, 'data' => self::redact($data), 'summary' => $s], $enc);

        return new FormattedPayload($body, match ($enc) {
            'json' => self::JSON_CT, 'form' => 'application/x-www-form-urlencoded', default => 'text/plain; charset=utf-8',
        });
    }

    // ----- helpers ----------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $payload */
    private static function json(array $payload): FormattedPayload
    {
        $body = json_encode($payload, self::JSON_SAFE_FLAGS);

        return new FormattedPayload($body === false ? '{}' : $body, self::JSON_CT);
    }

    /**
     * Plain-text body: summary, then "Name: value" lines.
     *
     * @param array{title:string,summary:string,fields:list<array{name:string,value:string}>} $s
     */
    private static function plain(array $s): string
    {
        $t = $s['summary'] !== '' ? $s['summary'] : $s['title'];
        foreach ($s['fields'] as $f) {
            $t .= "\n" . $f['name'] . ': ' . $f['value'];
        }

        return $t;
    }

    /** Header values: single line; non-ASCII as an RFC 2047 encoded word (ntfy decodes it). */
    private static function headerValue(string $v): string
    {
        $v = EventSummary::clean($v);
        if (preg_match('/^[\x20-\x7E]*$/', $v) === 1) {
            return $v;
        }

        return '=?UTF-8?B?' . base64_encode($v) . '?=';
    }

    private static function color(string $sev): string
    {
        return match ($sev) { 'critical' => '#d00000', 'warning' => '#daa038', default => '#36a64f' };
    }

    private static function colorInt(string $sev): int
    {
        return (int) hexdec(ltrim(self::color($sev), '#'));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function redact(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (preg_match(self::SECRET_KEY, (string) $k) === 1) {
                $out[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $out[$k] = $depth >= 8 ? [] : self::redact($v, $depth + 1);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** @param array<string,string> $out */
    private static function flatten(mixed $v, string $prefix, array &$out, int $depth): void
    {
        if (count($out) >= self::MAX_FORM_KEYS) {
            return;
        }
        if (is_array($v)) {
            if ($depth >= 6) {
                return;
            }
            foreach ($v as $k => $item) {
                self::flatten($item, $prefix . '.' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $k), $out, $depth + 1);
            }

            return;
        }
        $s = is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : '');
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_scrub($s, 'UTF-8'));
        $out[$prefix] = EventSummary::clip($s, self::MAX_FORM_VALUE);
    }
}
