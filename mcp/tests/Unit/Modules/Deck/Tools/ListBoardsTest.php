<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Deck\Db\Board;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use OCA\Mcp\Modules\Deck\Reading\BoardReader;
use OCA\Mcp\Modules\Deck\Tools\ListBoards;
use OCA\Mcp\Tests\Support\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_list_boards`.
 */

final class ListBoardsTest extends TestCase {
    use DeckTestHelpers;
    /**
     * @param array<Board> $boards Boards the capability returns.
     * @param LoggerInterface|null $logger Logger to inspect.
     * @return ListBoards Handler under test.
     */
    private function handler(array $boards, ?LoggerInterface $logger = null): ListBoards {
        $capabilities = $this->createMock(BoardReader::class);
        $capabilities->method('listBoards')->willReturn($boards);
        return new ListBoards(
            $capabilities->listBoards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($logger ?? $this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
    }

    public function testHappyPathReturnsTheBoards(): void {
        $result = $this->handler([
            new Board(['id' => 1, 'title' => 'Casa', 'owner' => 'alice', 'lastModified' => 5]),
            new Board(['id' => 2, 'title' => 'Trabalho', 'owner' => 'alice', 'lastModified' => 6]),
        ])->execute([], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        $payload = $this->payload($result);
        self::assertSame([1, 2], array_column($payload['boards'], 'id'));
        self::assertSame(['Casa', 'Trabalho'], array_column($payload['boards'], 'title'));
        self::assertFalse($payload['truncated']);
    }

    public function testHappyPathForwardsTheAuthenticatedUser(): void {
        $capabilities = $this->createMock(BoardReader::class);
        $capabilities->expects(self::once())->method('listBoards')->with('alice')->willReturn([]);
        $handler = new ListBoards(
            $capabilities->listBoards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        self::assertSame(
            ['boards' => [], 'truncated' => false],
            $this->payload($handler->execute([], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'))),
        );
    }

    public function testTruncatesAtTwoHundredBoards(): void {
        $boards = [];
        for ($i = 0; $i < ListBoards::MAX_BOARDS + 5; $i++) {
            $boards[] = new Board(['id' => $i]);
        }
        $payload = $this->payload($this->handler($boards)->execute([], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertCount(ListBoards::MAX_BOARDS, $payload['boards']);
        self::assertTrue($payload['truncated']);
    }

    public function testDeniedAccessBecomesTheGenericMessageWithoutDetails(): void {
        $capabilities = $this->createMock(BoardReader::class);
        $capabilities->method('listBoards')->willThrowException(new NoPermissionException('Permission denied'));
        $handler = new ListBoards(
            $capabilities->listBoards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $result = $handler->execute([], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
        self::assertStringNotContainsString('Permission denied', $this->text($result));
    }

    public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
        $capabilities = $this->createMock(BoardReader::class);
        $capabilities->method('listBoards')->willThrowException(new StatusException('Operation not allowed. This board is archived.'));
        $handler = new ListBoards(
            $capabilities->listBoards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );
        $result = $handler->execute([], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotAllowed(), $this->text($result));
    }

    public function testBackendFailureIsLoggedByClassOnly(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('{exception}'),
            self::callback(static function (array $context): bool {
                return $context['tool'] === 'deck_list_boards' && $context['exception'] === RuntimeException::class && !str_contains(json_encode($context), 'connection refused');
            }),
        );
        $capabilities = $this->createMock(BoardReader::class);
        $capabilities->method('listBoards')->willThrowException(new RuntimeException('connection refused'));
        $handler = new ListBoards(
            $capabilities->listBoards(...),
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($logger),
            $this->formatterFactory($this->cardFormatter()),
        );
        $result = $handler->execute([], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
    }
}
