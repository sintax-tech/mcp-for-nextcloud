<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Cards;

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

final class DeckVerificationStateTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckVerificationAdapterFixtures;
    public function testCardStateReadsTheRowAgainDeletedIncludedAfterTheReadCheck(): void {
        $row = $this->card(['id' => 7, 'deletedAt' => 1700000500]);
        $this->services[CardMapper::class]->expects(self::once())->method('find')->with(7, false)->willReturnCallback(function () use ($row) {
            $this->calls[] = 'read';
            return $row;
        });
        self::assertSame($row, $this->capabilities->state->cardState('alice', 7));
        self::assertSame(['check:' . CardMapper::class . ':7:' . Acl::PERMISSION_READ . ':deleted', 'read'], $this->checks());
    }

    public function testCardStateIsRefusedWithoutAccess(): void {
        $this->services[PermissionService::class] = $this->createMock(PermissionService::class);
        $this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
        $this->services[CardMapper::class]->expects(self::never())->method('find');
        $this->expectException(NoPermissionException::class);
        $this->capabilities->state->cardState('alice', 7);
    }

    /** The rule of the ticket: a card of the caller, same title, created in the last two minutes, newest first. */
    public function testFindCreatedCardIsTheNewestRecentCardOfTheCallerWithTheTitle(): void {
        $this->services[CardMapper::class]->expects(self::once())->method('findAll')->with(10)->willReturn([
            $this->card(['id' => 1, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 600]),
            $this->card(['id' => 2, 'title' => 'Fechar', 'owner' => 'pedro', 'createdAt' => self::NOW - 5]),
            $this->card(['id' => 3, 'title' => 'Outro', 'owner' => 'alice', 'createdAt' => self::NOW - 5]),
            $this->card(['id' => 4, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 100]),
            $this->card(['id' => 5, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 3]),
            $this->card(['id' => 6, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 2]),
        ]);
        self::assertSame(
            5,
            $this->capabilities->state->findCreatedCard('alice', 10, 'Fechar', [6])?->getId(),
            'o 6 já foi contado por esta chamada',
        );
        self::assertSame(['check:' . StackMapper::class . ':10:' . Acl::PERMISSION_READ], $this->checks());
    }

    public function testFindCreatedCardWithoutAMatchIsNull(): void {
        $this->services[CardMapper::class]->method('findAll')->willReturn([$this->card(['id' => 1, 'title' => 'Fechar', 'owner' => 'alice', 'createdAt' => self::NOW - 121])]);
        self::assertNull($this->capabilities->state->findCreatedCard('alice', 10, 'Fechar'));
    }
}
