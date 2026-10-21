<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Reading;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Modules\Deck\Followup\CardCriteria;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCA\Deck\Service\AssignmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckServiceCardsTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\NativeDeckAdapterFixtures;
    public function testListCardsChecksReadPermissionBeforeQueryingTheMapper(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(self::isInstanceOf(StackMapper::class), 10, Acl::PERMISSION_READ)->willReturnCallback(function (): bool {
            $this->calls[] = 'checkPermission';
            return true;
        });
        $this->services[CardMapper::class]->expects(self::once())->method('findAll')->with(10, 25, 50)->willReturnCallback(function (): array {
            $this->calls[] = 'findAll';
            return [$this->card(['id' => 1])];
        });
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        $page = $this->capabilities->cards->listCards('alice', 10, 25, 50);
        self::assertSame(4, $page['boardId']);
        self::assertCount(1, $page['items']);
        self::assertSame(['checkPermission', 'findAll'], $this->record('checkPermission', 'findAll'));
    }

    public function testListCardsStopsWhenTheStackIsNotReadable(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
        $this->services[CardMapper::class]->expects(self::never())->method('findAll');
        $this->expectException(NoPermissionException::class);
        $this->capabilities->cards->listCards('alice', 10, 25, 0);
    }

    public function testListCardsNormalisesAnEmptyMapperResult(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->willReturn([]);
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        self::assertSame(['items' => [], 'boardId' => 4], $this->capabilities->cards->listCards('alice', 10, 25, 0));
    }

    public function testFindCardGoesThroughTheDeckService(): void {
        $card = $this->card();
        $this->recordSetUserId();
        $this->services[CardService::class]->expects(self::once())->method('find')->with(7)->willReturn($card);
        self::assertSame($card, $this->capabilities->cards->findCard('alice', 7));
    }

    public function testUnresolvedDeckServiceSurfacesAsAnExceptionForTheCallerToMap(): void {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')->willThrowException(new RuntimeException('Service not found'));
        $capabilities = $this->nativeDeck($this->container, $this->time, session: $this->deckSession());
        $this->expectException(RuntimeException::class);
        $capabilities->cards->findCard('alice', 7);
    }

    public function testDeckServicesAreResolvedOncePerRequest(): void {
        $this->recordSetUserId();
        $this->services[CardService::class]->method('find')->willReturn($this->card());
        $this->capabilities->cards->findCard('alice', 7);
        $this->capabilities->cards->findCard('alice', 8);
        self::assertCount(1, $this->record('get:' . CardService::class));
    }

    public function testTheBoardOfACardIsReadOnlyAfterTheReadCheck(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(self::isInstanceOf(CardMapper::class), 7, Acl::PERMISSION_READ)->willReturnCallback(function (): bool {
            $this->calls[] = 'checkPermission';
            return true;
        });
        $this->services[CardMapper::class]->expects(self::once())->method('findBoardId')->with(7)->willReturnCallback(function (): int {
            $this->calls[] = 'findBoardId';
            return 4;
        });
        self::assertSame(4, $this->capabilities->cards->cardBoardId('alice', 7));
        $calls = array_values(array_filter($this->calls, static fn(string $call): bool => !str_starts_with($call, 'get:')));
        self::assertSame(['setUserId:alice', 'checkPermission', 'findBoardId'], $calls);
    }

    public function testTheBoardOfACardIsNotReadWithoutAccess(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
        $this->services[CardMapper::class]->expects(self::never())->method('findBoardId');
        $this->expectException(NoPermissionException::class);
        $this->capabilities->cards->cardBoardId('alice', 7);
    }

    public function testListCardsEmbedsTheAssignmentsOfTheWholePageInOneQuery(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        $first = $this->card(['id' => 1]);
        $second = $this->card(['id' => 2]);
        $this->services[CardMapper::class]->method('findAll')->willReturn([$first, $second]);
        $this->services[AssignmentMapper::class]->expects(self::once())->method('findIn')->with([1, 2])->willReturn([new Assignment(['cardId' => 2, 'participant' => 'alice', 'type' => Acl::PERMISSION_TYPE_USER])]);
        $page = $this->capabilities->cards->listCards('alice', 10, 25, 0);
        self::assertSame([], $page['items'][0]->getAssignedUsers());
        self::assertSame('alice', $page['items'][1]->getAssignedUsers()[0]->getParticipant());
    }
}
