<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Deck\Db\Card;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use OCA\Mcp\Modules\Deck\Reading\StackReader;
use OCA\Mcp\Modules\Deck\Tools\ListStacks;
use OCA\Mcp\Tests\Support\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_list_stacks`.
 */

final class ListStacksTest extends TestCase {
    use DeckTestHelpers;
    /**
     * @param array<Stack> $stacks Stacks the capability returns.
     * @return ListStacks Handler under test.
     */
    private function handler(array $stacks): ListStacks {
        $capabilities = $this->createMock(StackReader::class);
        $capabilities->method('listStacks')->willReturn($stacks);
        return new ListStacks(
            $capabilities->listStacks(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
    }

    public function testHappyPathForwardsTheBoardAndTheCaller(): void {
        $capabilities = $this->createMock(StackReader::class);
        $capabilities->expects(self::once())->method('listStacks')->with('alice', 4)->willReturn([new Stack(['id' => 10, 'boardId' => 4, 'title' => 'A fazer', 'order' => 1])]);
        $handler = new ListStacks(
            $capabilities->listStacks(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $payload = $this->payload($handler->execute(['boardId' => 4], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(
            [['id' => 10, 'boardId' => 4, 'title' => 'A fazer', 'order' => 1, 'cardsCount' => 0]],
            $payload['stacks'],
        );
        self::assertFalse($payload['truncated']);
    }

    public function testCardsAreCountedAndNotRepeatedInThePayload(): void {
        $stack = new Stack([
            'id' => 10,
            'boardId' => 4,
            'title' => 'A fazer',
            'order' => 1,
            'cards' => [new Card(['id' => 1]), new Card(['id' => 2])],
        ]);
        $payload = $this->payload($this->handler([$stack])->execute(['boardId' => 4], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(2, $payload['stacks'][0]['cardsCount']);
        self::assertStringNotContainsString(
            '"description"',
            $this->text($this->handler([$stack])->execute(['boardId' => 4], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'))),
        );
    }

    public function testTruncatesAtTwoHundredStacks(): void {
        $stacks = [];
        for ($i = 0; $i < ListStacks::MAX_STACKS + 1; $i++) {
            $stacks[] = new Stack(['id' => $i, 'boardId' => 4]);
        }
        $payload = $this->payload($this->handler($stacks)->execute(['boardId' => 4], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertCount(ListStacks::MAX_STACKS, $payload['stacks']);
        self::assertTrue($payload['truncated']);
    }

    public function testDeniedAccessBecomesTheGenericMessage(): void {
        $capabilities = $this->createMock(StackReader::class);
        $capabilities->method('listStacks')->willThrowException(new NoPermissionException('Permission denied'));
        $handler = new ListStacks(
            $capabilities->listStacks(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $result = $handler->execute(['boardId' => 4], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }

    public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
        $capabilities = $this->createMock(StackReader::class);
        $capabilities->method('listStacks')->willThrowException(new StatusException('Operation not allowed.'));
        $handler = new ListStacks(
            $capabilities->listStacks(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        self::assertSame(
            \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotAllowed(),
            $this->text($handler->execute(['boardId' => 4], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'))),
        );
    }

    public function testBackendFailureBecomesTheGenericMessage(): void {
        $capabilities = $this->createMock(StackReader::class);
        $capabilities->method('listStacks')->willThrowException(new RuntimeException('SQLSTATE'));
        $handler = new ListStacks(
            $capabilities->listStacks(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $result = $handler->execute(['boardId' => 4], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
    }
}
