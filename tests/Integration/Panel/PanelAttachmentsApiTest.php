<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Panel;

use RivetCore\Tests\Support\PanelHarness;
use RivetCore\Tests\Support\PanelTestCase;

final class PanelAttachmentsApiTest extends PanelTestCase
{
    /** @return array{token:string,device_id:int} */
    private function dev(?PanelHarness $h = null): array
    {
        $h ??= $this->p;

        return $h->device_for($h->clientA, $h->gateway->addClientContact($h->clientA, 'J'));
    }

    public function testValidatedAttachmentsReachTheGatewayOnCreateAndChat(): void
    {
        $d = $this->dev();
        $pdf = ['filename' => '../../report.pdf', 'content_type' => 'application/pdf', 'data_base64' => base64_encode('%PDF-1.4 hi')];
        [$c, , $t] = $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b', 'attachments' => [self::pngAttachment(), $pdf]], $d['token']);
        $this->assertSame(201, $c);
        $got = $this->p->gateway->created[0]['attachments'];
        $this->assertSame(['screen.png', 'report.pdf'], array_column($got, 'filename'));
        $this->assertSame(['image/png', 'application/pdf'], array_column($got, 'content_type'));
        $this->assertSame('%PDF-1.4 hi', $got[1]['bytes']);
        [$c, , $m] = $this->p->call('POST', 'panel_ticket_chat', ['body' => 'see attached', 'attachments' => [self::pngAttachment('b.png')]], $d['token'], [], [(string) $t['ticket_id']]);
        $this->assertSame(201, $c);
        $this->assertSame('b.png', $this->p->gateway->posted[0]['attachments'][0]['filename']);
        [, , $chat] = $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], [], [(string) $t['ticket_id']]);
        $this->assertSame([['filename' => 'screen.png', 'content_type' => 'image/png', 'size' => 40], ['filename' => 'report.pdf', 'content_type' => 'application/pdf', 'size' => 11]], $chat['messages'][0]['attachments']);
    }

    public function testARejectedAttachmentCreatesNothing(): void
    {
        $d = $this->dev();
        $exe = ['filename' => 'invoice.pdf.exe', 'content_type' => 'application/pdf', 'data_base64' => base64_encode("MZ\x90")];
        $disguised = ['filename' => 'invoice.pdf', 'content_type' => 'application/pdf', 'data_base64' => base64_encode("MZ\x90\0\3")];
        foreach ([$exe, $disguised] as $bad) {
            [$c, , $j] = $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b', 'attachments' => [self::pngAttachment(), $bad]], $d['token']);
            $this->assertSame([422, 'attachment_rejected'], [$c, $j['code']]);
        }
        $this->assertSame([], $this->p->gateway->created);
        $this->assertSame(0, (int) $this->p->rmm->one('SELECT COUNT(*) FROM panel_ticket_owner'));
        [, , $t] = $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $d['token']);
        [$c, , $j] = $this->p->call('POST', 'panel_ticket_chat', ['body' => 'x', 'attachments' => [$exe]], $d['token'], [], [(string) $t['ticket_id']]);
        $this->assertSame([422, 'attachment_rejected'], [$c, $j['code']]);
        $this->assertSame([], $this->p->gateway->posted);
    }

    public function testTheSizeCapsComeFromTheConfiguration(): void
    {
        $h = new PanelHarness(['limits' => ['attachmentBytesMax' => 100, 'attachmentsTotalMax' => 150, 'attachmentsMax' => 2]]);
        $d = $this->dev($h);
        $png = static fn (int $n, string $name): array => ['filename' => $name, 'content_type' => 'image/png', 'data_base64' => base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", $n - 8))];
        $send = static fn (array $atts) => $h->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b', 'attachments' => $atts], $d['token']);
        $this->assertSame(201, $send([$png(100, 'a.png')])[0]);
        $this->assertSame(413, $send([$png(101, 'a.png')])[0]);
        $this->assertSame(413, $send([$png(100, 'a.png'), $png(60, 'b.png')])[0], 'total cap');
        $this->assertSame(422, $send([$png(10, 'a.png'), $png(10, 'b.png'), $png(10, 'c.png')])[0], 'count cap');
        $this->assertSame(['subject_max', 'ticket_body_max', 'chat_body_max', 'attachments_max', 'attachment_bytes_max', 'attachments_total_max', 'attachment_extensions'], array_keys($h->call('GET', 'panel_me', null, $d['token'])[2]['limits']));
        $this->assertSame(100, $h->call('GET', 'panel_me', null, $d['token'])[2]['limits']['attachment_bytes_max']);
    }

    public function testAnOversizedBodyIsRefusedBeforeItIsParsed(): void
    {
        $d = $this->dev();
        $max = $this->p->module->limits()->postBodyMax();
        $r = $this->p->api->handle($this->p->request('POST', 'panel_tickets', '{"subject":"s","body":"b"}', $d['token'], [], [], '127.0.0.1', true, [], $max + 1));
        $this->assertSame(413, $r->status);
        $this->assertSame([], $this->p->gateway->created);
    }
}
