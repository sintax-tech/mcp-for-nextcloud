<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Mcp\Modules\Deck\Reading\CardReader;
use OCA\Mcp\Modules\Deck\Reading\DeckCardReferenceReader;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../../Support/Deck/Stubs/deck_stubs.php';

final class ReadingReferenceTest extends TestCase {
    public function testReferenceContainsOnlyCheckedPublicFields(): void {
        $cards = $this->createMock(CardReader::class);
        $cards->expects(self::once())->method('findCard')->with('alice', 7)->willReturn(new \OCA\Deck\Db\Card(['id' => 7, 'title' => 'Task', 'description' => 'Private body']));
        $cards->expects(self::once())->method('cardBoardId')->with('alice', 7)->willReturn(3);
        self::assertSame(
            ['cardId' => 7, 'boardId' => 3, 'title' => 'Task'],
            (new DeckCardReferenceReader($cards))->read('alice', 7),
        );
    }

    public function testDeniedCardDoesNotTriggerBoardLookup(): void {
        $cards = $this->createMock(CardReader::class);
        $cards->method('findCard')->willThrowException(new \OCA\Deck\NoPermissionException('denied'));
        $cards->expects(self::never())->method('cardBoardId');
        $this->expectException(\OCA\Deck\NoPermissionException::class);
        (new DeckCardReferenceReader($cards))->read('alice', 7);
    }
}
