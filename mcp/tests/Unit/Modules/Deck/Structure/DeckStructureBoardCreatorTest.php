<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Structure;

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
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckStructureBoardCreatorTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckStructureAdapterFixtures;
    public function testCreateBoardIsOwnedByTheCallerAndBoundFirst(): void {
        $board = new \OCA\Deck\Db\Board(['id' => 3, 'title' => 'Projeto']);
        $this->services[BoardService::class]->expects(self::once())->method('create')->with('Projeto', 'alice', '0082c9')->willReturnCallback(function () use ($board): \OCA\Deck\Db\Board {
            $this->calls[] = 'create';
            return $board;
        });
        self::assertSame($board, $this->capabilities->boardCreator->createBoard('alice', 'Projeto', '0082c9'));
        self::assertSame(['setUserId:alice', 'create'], $this->calls);
    }
}
