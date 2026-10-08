<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\AttachmentMessage;
use OCA\Mcp\Tools\Talk\Messages;
use PHPUnit\Framework\TestCase;

class AttachmentMessageTest extends TestCase {
    private AttachmentMessage $attachmentMessage;

    protected function setUp(): void {
        parent::setUp();
        $this->attachmentMessage = new AttachmentMessage();
    }

    public function testEnvelopeIsTheOneTalkRendersAsACard(): void {
        $envelope = $this->attachmentMessage->build(77);

        $this->assertSame('{"message":"file_shared","parameters":{"share":"77"}}', $envelope);
    }

    public function testShareIdTravelsAsTextBecauseThatIsHowTalkStoresIt(): void {
        $envelope = json_decode($this->attachmentMessage->build(77), true);

        $this->assertSame('77', $envelope['parameters']['share']);
    }

    public function testCaptionIsTrimmedAndPlacedInMetaData(): void {
        $envelope = json_decode($this->attachmentMessage->build(77, "  a figura  "), true);

        $this->assertSame('a figura', $envelope['parameters']['metaData']['caption']);
    }

    public function testTextIsNotEscapedSoAccentsAndSlashesSurvive(): void {
        $envelope = $this->attachmentMessage->build(77, 'relatório da equipe/chefe');

        $this->assertStringContainsString('relatório da equipe/chefe', $envelope);
        $this->assertStringNotContainsString('\\/', $envelope);
        $this->assertStringNotContainsString('\\u', $envelope);
    }

    public function testBlankCaptionIsAnArgumentError(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::emptyMessage());
        $this->attachmentMessage->build(77, "  \n ");
    }

    public function testCaptionAboveTheLimitIsAnArgumentError(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::messageTooLong());
        $this->attachmentMessage->build(77, str_repeat('a', AttachmentMessage::MAX_CAPTION + 1));
    }

    public function testCaptionOfExactlyTheLimitIsAccepted(): void {
        $caption = str_repeat('a', AttachmentMessage::MAX_CAPTION);

        $envelope = json_decode($this->attachmentMessage->build(77, $caption), true);

        $this->assertSame($caption, $envelope['parameters']['metaData']['caption']);
    }

    public function testNoCaptionIsReportedAsNoneRatherThanAsAnEmptyText(): void {
        // The draft and the card have to agree on what "no caption" means, or the preview would show a text
        // the confirmed call never publishes.
        $this->assertNull(AttachmentMessage::normalizeCaption(null));
        $this->assertSame('a figura', AttachmentMessage::normalizeCaption('  a figura  '));

        $envelope = json_decode($this->attachmentMessage->build(77, AttachmentMessage::normalizeCaption(null)), true);

        $this->assertArrayNotHasKey('metaData', $envelope['parameters']);
    }
}
