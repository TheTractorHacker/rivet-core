<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Panel;

use PHPUnit\Framework\TestCase;
use RivetCore\Panel\PanelLimits;

final class PanelLimitsTest extends TestCase
{
    public function testDefaultsAreTheDocumentedSafeValues(): void
    {
        $l = new PanelLimits();
        $this->assertSame([200, 20000, 5000, 3, 5 * 1024 * 1024, 10 * 1024 * 1024, 50], [$l->subjectMax, $l->ticketBodyMax, $l->chatBodyMax, $l->attachmentsMax, $l->attachmentBytesMax, $l->attachmentsTotalMax, $l->ticketsPerDayMax]);
        $this->assertSame(['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv'], $l->allowedExtensions);
        $this->assertGreaterThan((int) (10 * 1024 * 1024 * 4 / 3), $l->postBodyMax());
    }

    public function testOverridesAreClampedToHardCeilingsAndJunkIsIgnored(): void
    {
        $l = PanelLimits::fromArray(['subjectMax' => 99999, 'attachmentBytesMax' => PHP_INT_MAX, 'attachmentsMax' => 500, 'ticketsPerDayMax' => 0, 'pageMax' => '5', 'chatBodyMax' => 1000, 'unknown' => 1]);
        $this->assertSame(500, $l->subjectMax);
        $this->assertSame(PanelLimits::MAX_ATTACHMENT_BYTES_CEILING, $l->attachmentBytesMax);
        $this->assertSame(PanelLimits::MAX_ATTACHMENTS_CEILING, $l->attachmentsMax);
        $this->assertSame(1, $l->ticketsPerDayMax);
        $this->assertSame(50, $l->pageMax, 'a non-integer value falls back to the default');
        $this->assertSame(1000, $l->chatBodyMax);
        $d = $l->describe();
        $this->assertSame(['subject_max', 'ticket_body_max', 'chat_body_max', 'attachments_max', 'attachment_bytes_max', 'attachments_total_max', 'attachment_extensions'], array_keys($d));
    }
}
