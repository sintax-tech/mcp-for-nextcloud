<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Talk\Messaging;
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Modules\Talk\Messaging\BatchSender;
use OCA\Mcp\Modules\Talk\Messaging\MessageSender;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
final class BatchSenderTest extends TestCase
{
    public function testHandledFailureDoesNotRetryOrHideOtherMessages(): void
    {
        self::assertTrue(class_exists(BatchSender::class), 'Batch delivery must have its own capability');
        $sender = $this->createMock(MessageSender::class);
        $attempts = [];
        $sender->method('reply')->willReturnCallback(function ($room, $user, $text, $parent) use (&$attempts): array {
            $attempts[] = $text;
            if ($text === 'bad') {
                throw new ConversationAccessException('refused');
            }
            return ['messageId' => count($attempts)];
        });
        $room = new class {
            public function getToken(): string
            {
                return 'abcd';
            }
        };
        $result = (new BatchSender($sender))->replyMany(new Conversation($room, new \stdClass()), 'alice', [
            ['message' => 'first'],
            ['message' => 'bad'],
            ['message' => 'last'],
        ]);
        self::assertSame(['first', 'bad', 'last'], $attempts);
        self::assertSame([['index' => 0, 'messageId' => 1], ['index' => 2, 'messageId' => 3]], $result['sent']);
        self::assertSame([['index' => 1, 'error' => 'refused']], $result['failed']);
    }
}
