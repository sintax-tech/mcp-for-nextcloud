<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Access;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckVerificationAccessTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckVerificationAdapterFixtures;
    public function testNoVerificationReadReachesTheDeckWithoutTheCallerInTheSession(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturn(null);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $capabilities = $this->nativeDeck($container, $this->createMock(ITimeFactory::class), null, $session);
        foreach ([
            fn() => $capabilities->state->cardState('alice', 7),
            fn() => $capabilities->state->findCreatedCard('alice', 10, 'X'),
            fn() => $capabilities->assignments->cardAssignments('alice', 7),
            fn() => $capabilities->structureState->stacksOf('alice', 4),
            fn() => $capabilities->structureState->deletedStack('alice', 10),
            fn() => $capabilities->structureState->ownedBoards('alice'),
        ] as $read) {
            try {
                $read();
                self::fail('a verification read reached the Deck without the caller in the session');
            } catch (DeckSessionException) {
            }
        }
    }
}
