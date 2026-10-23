<?php

declare(strict_types=1);

namespace RivetCore\Panel;

/**
 * Size, count and rate limits of the panel API, with safe defaults. An edition passes overrides as an array (unknown keys are
 * ignored, out-of-range values are clamped to a hard ceiling so a typo cannot lift a limit into abuse territory).
 *
 * @api
 */
final class PanelLimits
{
    /** Hard ceilings no configuration can exceed. */
    public const MAX_ATTACHMENT_BYTES_CEILING = 25 * 1024 * 1024;
    public const MAX_ATTACHMENTS_CEILING = 10;

    /**
     * @param list<string> $allowedExtensions extensions an attachment may have (the built-in table decides the matching content types and magic bytes; an extension without an entry there is never accepted)
     */
    public function __construct(
        public readonly int $subjectMax = 200,
        public readonly int $ticketBodyMax = 20000,
        public readonly int $chatBodyMax = 5000,
        public readonly int $displayNameMax = 100,
        public readonly int $attachmentsMax = 3,
        public readonly int $attachmentBytesMax = 5 * 1024 * 1024,
        public readonly int $attachmentsTotalMax = 10 * 1024 * 1024,
        public readonly array $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv'],
        public readonly int $ticketsPerDayMax = 50,
        public readonly int $pageMax = 50,
        public readonly int $chatPageMax = 100,
        public readonly int $ownedWindow = 500,
        public readonly int $enrollMaxTtlH = PanelProtocol::ENROLL_MAX_TTL_H,
    ) {
    }

    /** @param array<string,mixed> $o */
    public static function fromArray(array $o): self
    {
        $d = new self();
        $int = static fn (string $k, int $default, int $min, int $max): int => isset($o[$k]) && is_int($o[$k]) ? max($min, min($max, $o[$k])) : $default;
        $ext = $d->allowedExtensions;
        if (isset($o['allowedExtensions']) && is_array($o['allowedExtensions'])) {
            $ext = array_values(array_unique(array_filter(array_map(static fn ($e): string => is_string($e) ? strtolower(ltrim($e, '.')) : '', $o['allowedExtensions']), static fn (string $e): bool => preg_match('/^[a-z0-9]{1,6}$/', $e) === 1)));
        }

        return new self(
            $int('subjectMax', $d->subjectMax, 1, 500),
            $int('ticketBodyMax', $d->ticketBodyMax, 1, 100000),
            $int('chatBodyMax', $d->chatBodyMax, 1, 50000),
            $int('displayNameMax', $d->displayNameMax, 1, 200),
            $int('attachmentsMax', $d->attachmentsMax, 0, self::MAX_ATTACHMENTS_CEILING),
            $int('attachmentBytesMax', $d->attachmentBytesMax, 1, self::MAX_ATTACHMENT_BYTES_CEILING),
            $int('attachmentsTotalMax', $d->attachmentsTotalMax, 1, self::MAX_ATTACHMENT_BYTES_CEILING * 2),
            $ext,
            $int('ticketsPerDayMax', $d->ticketsPerDayMax, 1, 1000),
            $int('pageMax', $d->pageMax, 1, 200),
            $int('chatPageMax', $d->chatPageMax, 1, 500),
            $int('ownedWindow', $d->ownedWindow, 1, 5000),
            $int('enrollMaxTtlH', $d->enrollMaxTtlH, 1, 24 * 14),
        );
    }

    /** Largest request body a ticket or chat POST may carry: every attachment base64-encoded plus the text fields and JSON overhead. */
    public function postBodyMax(): int
    {
        return (int) (ceil($this->attachmentsTotalMax / 3) * 4) + $this->ticketBodyMax * 4 + 65536;
    }

    /** @return array<string,mixed> what the panel is told so it can validate before it sends */
    public function describe(): array
    {
        return [
            'subject_max' => $this->subjectMax,
            'ticket_body_max' => $this->ticketBodyMax,
            'chat_body_max' => $this->chatBodyMax,
            'attachments_max' => $this->attachmentsMax,
            'attachment_bytes_max' => $this->attachmentBytesMax,
            'attachments_total_max' => $this->attachmentsTotalMax,
            'attachment_extensions' => $this->allowedExtensions,
        ];
    }
}
