<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Followup;

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

final class DeckServiceFollowupTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\NativeDeckAdapterFixtures;
    public function testFollowupScansOnlyTheBoardsTheCallerMayManage(): void {
        $this->recordSetUserId();
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(1), $this->boardDouble(2)]);
        $checked = [];
        $this->services[PermissionService::class]->expects(self::exactly(2))->method('getPermissions')->willReturnCallback(function (int $boardId, string $userId) use (&$checked): array {
            $checked[] = $boardId . ':' . $userId;
            return $boardId === 1 ? [Acl::PERMISSION_MANAGE => true] : [Acl::PERMISSION_READ => true, Acl::PERMISSION_MANAGE => false];
        });
        $this->services[StackMapper::class]->expects(self::once())->method('findAll')->with(1)->willReturn([new Stack(['id' => 10, 'boardId' => 1])]);
        $this->services[CardMapper::class]->method('findAllForStacks')->willReturn([10 => [$this->card(['id' => 11, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')])]]);
        $page = $this->capabilities->followup->followupCards('alice', 'overdue', null, null, null, 10);
        self::assertSame(['1:alice', '2:alice'], $checked);
        self::assertSame([11], array_map(static fn(array $item): int => $item['card']->getId(), $page['items']));
        self::assertSame([1], array_map(static fn(array $item): int => $item['boardId'], $page['items']));
        self::assertFalse($page['truncated']);
        self::assertSame(['setUserId:alice'], $this->record('setUserId'));
    }

    /**
     * Deck 1.15 (Nextcloud 31) and 1.16 (Nextcloud 32) have no CardMapper::findAllForStacks(): the board is read stack
     * by stack with findAll(), the query that grouped one repeats, with the same filters and the same order.
     */
    public function testFollowupReadsStackByStackOnADeckWithoutFindAllForStacks(): void {
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4)]);
        $this->services[PermissionService::class]->method('getPermissions')->willReturn([Acl::PERMISSION_MANAGE => true]);
        $this->services[StackMapper::class]->method('findAll')->with(4)->willReturn([
            new Stack(['id' => 10, 'boardId' => 4]),
            new Stack(['id' => 20, 'boardId' => 4]),
            new Stack(['id' => 30, 'boardId' => 4]),
        ]);
        $late = fn(int $id, int $stackId): Card => $this->card(['id' => $id, 'stackId' => $stackId, 'duedate' => new \DateTime('2026-03-01 00:00:00')]);
        $legacy = new class([10 => [$late(2, 10), $late(1, 10)], 30 => [$late(3, 30)]])
        {
            /** @var list<int> stack id of every findAll() call */
            public array $asked = [];
            /** @param array<int, list<Card>> $cards cards per stack, in Deck's order */
            public function __construct(private array $cards)
            {
            }
            /** Deck 1.15/1.16 signature: `findAll($stackId, $limit = null, $offset = null, $since = -1)`. */
            public function findAll(
                $stackId,
                $limit = null,
                $offset = null,
                $since = -1,
            ): array
            {
                $this->asked[] = $stackId;
                return $this->cards[$stackId] ?? [];
            }
        };
        $this->services[CardMapper::class] = $legacy;
        $page = $this->capabilities->followup->followupCards('alice', 'overdue', null, null, null, 10);
        self::assertSame([10, 20, 30], $legacy->asked);
        self::assertSame([2, 1, 3], array_map(static fn(array $item): int => $item['card']->getId(), $page['items']));
        self::assertFalse($page['truncated']);
    }

    public function testFollowupClassifiesCardsWithTheInjectedClock(): void {
        $late = $this->card(['id' => 1, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]);
        $today = $this->card(['id' => 2, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-05 00:00:00')]);
        $future = $this->card(['id' => 3, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-06 00:00:00')]);
        $undated = $this->card(['id' => 4, 'stackId' => 10]);
        $done = $this->card([
            'id' => 5,
            'stackId' => 10,
            'duedate' => new \DateTime('2026-03-01 00:00:00'),
            'done' => new \DateTime('2026-03-02 09:00:00'),
        ]);
        $archived = $this->card(['id' => 6, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00'), 'archived' => true]);
        $this->managedBoardWith([$late, $today, $future, $undated, $done, $archived]);
        $ids = fn(string $status): array => array_map(
            static fn(array $item): int => $item['card']->getId(),
            $this->capabilities->followup->followupCards('alice', $status, null, null, null, 100)['items'],
        );
        // The clock of the test is 2026-03-05 noon, so "today" is not late yet.
        self::assertSame([1], $ids('overdue'));
        self::assertSame([1, 2, 3, 4], $ids('open'));
        self::assertSame([5], $ids('done'));
        self::assertSame([1, 2, 3, 4, 5, 6], $ids('all'));
    }

    public function testFollowupKeepsOnlyTheCardsOfTheAskedAssignee(): void {
        // Deck stores the assignments apart from the card, so the capability reads them per board.
        $this->services[AssignmentMapper::class]->method('findIn')->willReturn([
            new Assignment(['cardId' => 1, 'participant' => 'pedro', 'type' => Acl::PERMISSION_TYPE_USER]),
            new Assignment(['cardId' => 2, 'participant' => 'vendas', 'type' => Acl::PERMISSION_TYPE_GROUP]),
        ]);
        $this->managedBoardWith([
            $this->card(['id' => 1, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
            $this->card(['id' => 2, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
        ]);
        $ids = fn(?string $assignee): array => array_map(
            static fn(array $item): int => $item['card']->getId(),
            $this->capabilities->followup->followupCards('alice', 'overdue', null, $assignee, null, 100)['items'],
        );
        self::assertSame([1], $ids('pedro'));
        // A group names no person, so a UID filter never answers a card assigned only to one.
        self::assertSame([], $ids('alice'));
        self::assertSame([1, 2], $ids(null));
    }

    public function testFollowupAppliesTheDueDateCeilingOnlyToCardsThatHaveADate(): void {
        $this->managedBoardWith([
            $this->card(['id' => 1, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
            $this->card(['id' => 2, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-10 00:00:00')]),
            $this->card(['id' => 3, 'stackId' => 10]),
        ]);
        $page = $this->capabilities->followup->followupCards('alice', 'all', null, null, '2026-03-05', 100);
        // Cards without a due date cannot be "until a date", so they stay out of the ceiling.
        self::assertSame([1], array_map(static fn(array $item): int => $item['card']->getId(), $page['items']));
    }

    public function testFollowupStopsAtTheLimitAndReportsTheTruncation(): void {
        $cards = array_map(
            fn(int $id): Card => $this->card(['id' => $id, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
            range(1, 12),
        );
        $this->managedBoardWith($cards);
        $page = $this->capabilities->followup->followupCards('alice', 'overdue', null, null, null, 5);
        self::assertCount(5, $page['items']);
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn(array $item): int => $item['card']->getId(), $page['items']));
        self::assertTrue($page['truncated']);
    }

    public function testFollowupStopsReadingBoardsOnceTheLimitIsCovered(): void {
        $boards = array_map(fn(int $id): \OCA\Deck\Db\Board => $this->boardDouble($id), [1, 2, 3]);
        $this->services[BoardService::class]->method('findAll')->willReturn($boards);
        $this->services[PermissionService::class]->method('getPermissions')->willReturn([Acl::PERMISSION_MANAGE => true]);
        $this->services[StackMapper::class]->expects(self::exactly(2))->method('findAll')->willReturnCallback(static fn(int $boardId): array => [new Stack(['id' => $boardId * 10, 'boardId' => $boardId])]);
        $this->services[CardMapper::class]->method('findAllForStacks')->willReturnCallback(function (array $stackIds): array {
            $cards = [];
            foreach ($stackIds as $stackId) {
                $cards[$stackId] = [$this->card(['id' => $stackId + 1, 'stackId' => $stackId, 'duedate' => new \DateTime('2026-03-01 00:00:00')])];
            }
            return $cards;
        });
        $page = $this->capabilities->followup->followupCards('alice', 'overdue', null, null, null, 1);
        self::assertCount(1, $page['items']);
        self::assertTrue($page['truncated']);
    }

    public function testFollowupNeverExaminesMoreThanOneHundredBoards(): void {
        $this->services[BoardService::class]->method('findAll')->willReturn(array_map(fn(int $id): \OCA\Deck\Db\Board => $this->boardDouble($id), range(1, 101)));
        $this->services[PermissionService::class]->expects(self::exactly(100))->method('getPermissions')->willReturn([Acl::PERMISSION_MANAGE => false]);
        $this->services[StackMapper::class]->expects(self::never())->method('findAll');
        $page = $this->capabilities->followup->followupCards('alice', 'overdue', null, null, null, 100);
        self::assertSame([], $page['items']);
        self::assertTrue($page['truncated']);
    }

    public function testFollowupScansOnlyTheBoardTheCallerAskedFor(): void {
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4), $this->boardDouble(9)]);
        $this->services[PermissionService::class]->expects(self::once())->method('getPermissions')->with(9, 'alice')->willReturn([Acl::PERMISSION_MANAGE => true]);
        $this->services[StackMapper::class]->expects(self::once())->method('findAll')->with(9)->willReturn([new Stack(['id' => 90, 'boardId' => 9])]);
        $this->services[CardMapper::class]->method('findAllForStacks')->willReturn([90 => [$this->card(['id' => 91, 'stackId' => 90, 'duedate' => new \DateTime('2026-03-01 00:00:00')])]]);
        $page = $this->capabilities->followup->followupCards('alice', 'overdue', 9, null, null, 10);
        self::assertSame([9], array_map(static fn(array $item): int => $item['boardId'], $page['items']));
        self::assertSame([91], array_map(static fn(array $item): int => $item['card']->getId(), $page['items']));
    }

    public function testFollowupReadsTheAssignmentsOfAWholeBoardInOneQuery(): void {
        $this->managedBoardWith([
            $this->card(['id' => 1, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
            $this->card(['id' => 2, 'stackId' => 10, 'duedate' => new \DateTime('2026-03-01 00:00:00')]),
        ]);
        $this->services[AssignmentMapper::class]->expects(self::once())->method('findIn')->with([1, 2])->willReturn([new Assignment(['cardId' => 1, 'participant' => 'alice', 'type' => Acl::PERMISSION_TYPE_USER])]);
        $page = $this->capabilities->followup->followupCards('alice', 'overdue', null, null, null, 10);
        self::assertSame(['alice'], \OCA\Mcp\Modules\Deck\Assignments\AssignedUsers::assignedUids($page['items'][0]['card']));
        self::assertSame([], \OCA\Mcp\Modules\Deck\Assignments\AssignedUsers::assignedUids($page['items'][1]['card']));
    }
}
