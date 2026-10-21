<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Deck\NoPermissionException;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use OCA\Mcp\Modules\Deck\Reading\CardReader;
use OCA\Mcp\Modules\Deck\Tools\ListCards;
use OCA\Mcp\Tests\Support\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_list_cards`, including the guarantee that a deleted card never reaches the client.
 */

final class ListCardsTest extends TestCase {
    use DeckTestHelpers;
    /**
     * @param array<string, mixed> $items Cards the capability returns.
     * @param int $boardId Board reported by the capability.
     * @return ListCards Handler under test.
     */
    private function handler(array $items, int $boardId = 4): ListCards {
        $capabilities = $this->createMock(CardReader::class);
        $capabilities->method('listCards')->willReturn(['items' => $items, 'boardId' => $boardId]);
        return new ListCards(
            $capabilities->listCards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
    }

    public function testHappyPathForwardsStackAndPagination(): void {
        $capabilities = $this->createMock(CardReader::class);
        $capabilities->expects(self::once())->method('listCards')->with('alice', 10, 25, 50)->willReturn(['items' => [$this->card(['id' => 1])], 'boardId' => 4]);
        $handler = new ListCards(
            $capabilities->listCards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $payload = $this->payload($handler->execute(['stackId' => 10, 'limit' => 25, 'offset' => 50], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(1, $payload['cards'][0]['id']);
        self::assertSame(4, $payload['cards'][0]['boardId']);
        self::assertSame(25, $payload['limit']);
        self::assertSame(50, $payload['offset']);
    }

    public function testDefaultsAreAppliedWhenTheClientOmitsPagination(): void {
        $capabilities = $this->createMock(CardReader::class);
        $capabilities->expects(self::once())->method('listCards')->with('alice', 10, ListCards::DEFAULT_LIMIT, 0)->willReturn(['items' => [], 'boardId' => 4]);
        $handler = new ListCards(
            $capabilities->listCards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        self::assertSame(
            ListCards::DEFAULT_LIMIT,
            $this->payload($handler->execute(['stackId' => 10], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')))['limit'],
        );
    }

    public function testDeletedCardNeverReachesTheClient(): void {
        $payload = $this->payload($this->handler([$this->card(['id' => 1]), $this->card(['id' => 2, 'deletedAt' => 1700000900])])->execute(['stackId' => 10], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame([1], array_column($payload['cards'], 'id'));
    }

    public function testArchivedCardNeverReachesTheClient(): void {
        $payload = $this->payload($this->handler([$this->card(['id' => 1, 'archived' => true])])->execute(['stackId' => 10], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame([], $payload['cards']);
    }

    public function testTotalIsOnlyClaimedWhenThePageCameBackShort(): void {
        $short = $this->payload($this->handler([$this->card()])->execute(['stackId' => 10, 'limit' => 50], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        $full = $this->payload($this->handler([$this->card(['id' => 1]), $this->card(['id' => 2])])->execute(['stackId' => 10, 'limit' => 2], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(1, $short['total']);
        self::assertNull($full['total']);
    }

    /** The total counts the cards before this page: a short page at offset 50 is not the whole stack. */
    public function testTotalOfAShortPageCountsTheOffset(): void {
        $payload = $this->payload($this->handler([$this->card(['id' => 1]), $this->card(['id' => 2]), $this->card(['id' => 3])])->execute(['stackId' => 10, 'limit' => 25, 'offset' => 50], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(53, $payload['total']);
    }

    /** An empty page past the end says nothing about the size of the stack: the total is unknown, never the offset asked. */
    public function testAnEmptyPageBeyondTheEndHasNoTotal(): void {
        $payload = $this->payload($this->handler([])->execute(['stackId' => 10, 'limit' => 25, 'offset' => 50], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame([], $payload['cards']);
        self::assertNull($payload['total']);
    }

    /** An empty first page is an empty stack. */
    public function testAnEmptyFirstPageIsAnEmptyStack(): void {
        $payload = $this->payload($this->handler([])->execute(['stackId' => 10, 'limit' => 25], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(0, $payload['total']);
    }

    /** Whether the page is short is decided on what Deck returned, not on what is left after the filter. */
    public function testAFullPageIsNeverClaimedAsTheEndEvenWhenTheFilterDropsCards(): void {
        $payload = $this->payload($this->handler([$this->card(['id' => 1]), $this->card(['id' => 2, 'archived' => true])])->execute(['stackId' => 10, 'limit' => 2], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame([1], array_column($payload['cards'], 'id'));
        self::assertNull($payload['total']);
    }

    public function testDeniedAccessBecomesTheGenericMessage(): void {
        $capabilities = $this->createMock(CardReader::class);
        $capabilities->method('listCards')->willThrowException(new NoPermissionException('Permission denied'));
        $handler = new ListCards(
            $capabilities->listCards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $result = $handler->execute(['stackId' => 10], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }

    public function testBackendFailureBecomesTheGenericMessage(): void {
        $capabilities = $this->createMock(CardReader::class);
        $capabilities->method('listCards')->willThrowException(new RuntimeException('deadlock'));
        $handler = new ListCards(
            $capabilities->listCards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $result = $handler->execute(['stackId' => 10], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
    }
}
