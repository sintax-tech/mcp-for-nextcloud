<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Access;

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

final class DeckStructureAccessTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckStructureAdapterFixtures;
    /**
     * Regression of 0.9.1 for the new writes: with the caller missing from the session Deck would write and then
     * fail, so nothing of the structure may reach the Deck.
     */
    public function testNoStructureWriteReachesDeckWithoutTheCallerInTheSession(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturn(null);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $capabilities = $this->nativeDeck($container, $this->createMock(ITimeFactory::class), null, $session);
        foreach ([
            'createBoard' => fn() => $capabilities->boardCreator->createBoard('alice', 'X', '0082c9'),
            'createStack' => fn() => $capabilities->stackCreator->createStack('alice', 4, 'X', 0),
            'deleteEmptyStack' => fn() => $capabilities->deletion->deleteEmptyStack('alice', 10),
            'deleteEmptyBoard' => fn() => $capabilities->deletion->deleteEmptyBoard('alice', 4),
        ] as $name => $call) {
            try {
                $call();
                self::fail($name . ' reached the Deck without the caller in the session');
            } catch (DeckSessionException) {
            }
        }
    }
}
