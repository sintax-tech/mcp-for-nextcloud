<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Deck;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCA\Mcp\Modules\Deck\Execution\DeckUnconfirmedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

trait DeckStructureAdapterFixtures {
    use \OCA\Mcp\Tests\Support\Deck\DeckNativeFixtures;
    /** @var array<string, object&MockObject> Services the container hands out. */
    private array $services = [];
    /** @var list<string> Ordered log of the calls, to prove check-before-write and count-before-delete. */
    private array $calls = [];
    private DeckDoubles $capabilities;
    protected function setUp(): void {
        $this->services = [
            BoardService::class => $this->createMock(BoardService::class),
            StackService::class => $this->createMock(StackService::class),
            CardService::class => $this->createMock(CardService::class),
            PermissionService::class => $this->createMock(PermissionService::class),
            CardMapper::class => $this->createMock(CardMapper::class),
            StackMapper::class => $this->createMock(StackMapper::class),
            BoardMapper::class => $this->createMock(BoardMapper::class),
            IUserManager::class => $this->createMock(IUserManager::class),
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id): object {
            if (!isset($this->services[$id])) {
                throw new LogicException('Unexpected container lookup: ' . $id);
            }
            return $this->services[$id];
        });
        $this->services[BoardService::class]->method('setUserId')->willReturnCallback(function (string $userId): void {
            $this->calls[] = 'setUserId:' . $userId;
        });
        $this->capabilities = $this->nativeDeck($container, $this->createMock(ITimeFactory::class), session: $this->deckSession());
    }

    /** Arranges a list that received a card during its delete and whose restore throws; the trash decides what is told. */
    private function stackWhoseRestoreThrows(): void {
        $deleted = false;
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->willReturnCallback(function () use (&$deleted): array {
            return $deleted ? [$this->card(['id' => 7])] : [];
        });
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[StackService::class]->method('delete')->willReturnCallback(function () use (&$deleted): Stack {
            $deleted = true;
            return new Stack(['id' => 10, 'boardId' => 4, 'title' => 'A fazer', 'order' => 2]);
        });
        $this->services[StackService::class]->method('update')->willThrowException(new \RuntimeException('listener failed after the update'));
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
    }

    /** Arranges a board that received a card during its delete and whose undo throws. */
    private function boardWhoseUndoThrows(): void {
        $deleted = false;
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[BoardService::class]->method('find')->willReturn(new Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
        $this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
        $this->services[CardMapper::class]->method('findAll')->willReturnCallback(function () use (&$deleted): array {
            return $deleted ? [$this->card(['id' => 7])] : [];
        });
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[BoardService::class]->method('delete')->willReturnCallback(function () use (&$deleted): Board {
            $deleted = true;
            return new Board(['id' => 4]);
        });
        $this->services[BoardService::class]->method('deleteUndo')->willThrowException(new \RuntimeException('listener failed after the undo'));
    }
}
