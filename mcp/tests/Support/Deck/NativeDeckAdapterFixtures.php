<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Deck;

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

trait NativeDeckAdapterFixtures {
    use \OCA\Mcp\Tests\Support\Deck\DeckNativeFixtures;
    /** @var array<string, object> Services the container hands out. */
    private array $services = [];
    /** @var list<string> Ordered log of the calls the gateway made, to prove check-before-query. */
    private array $calls = [];
    /** @var ContainerInterface&MockObject */
    private ContainerInterface $container;
    /** @var ITimeFactory&MockObject Clock the follow-up reads `overdue` from. */
    private ITimeFactory $time;
    private DeckDoubles $capabilities;
    protected function setUp(): void {
        $this->services = [
            BoardService::class => $this->createMock(BoardService::class),
            StackService::class => $this->createMock(StackService::class),
            CardService::class => $this->createMock(CardService::class),
            PermissionService::class => $this->createMock(PermissionService::class),
            CardMapper::class => $this->createMock(CardMapper::class),
            StackMapper::class => $this->createMock(StackMapper::class),
            AssignmentMapper::class => $this->createMock(AssignmentMapper::class),
            IUserManager::class => $this->createMock(IUserManager::class),
        ];
        $this->time = $this->createMock(ITimeFactory::class);
        $this->time->method('getTime')->willReturn((new \DateTime('2026-03-05 12:00:00'))->getTimestamp());
        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')->willReturnCallback(function (string $id): object {
            $this->calls[] = 'get:' . $id;
            if (!isset($this->services[$id])) {
                throw new LogicException('Unexpected container lookup: ' . $id);
            }
            return $this->services[$id];
        });
        $this->capabilities = $this->nativeDeck($this->container, $this->time, session: $this->deckSession());
    }

    /**
     * A card as Deck 1.18 and later load it, with the start date and colour fields 1.17 does not have.
     *
     * @param \DateTime|null $startdate Start date the card holds.
     * @param string|null $color Colour the card holds, as Deck stores it (hex without "#").
     */
    private function deck118Card(?\DateTime $startdate, ?string $color): Card {
        return new class(['id' => 7, 'stackId' => 10, 'owner' => 'alice', 'type' => 'note'], $startdate, $color) extends Card
        {
            public function __construct(
                array $fields,
                private ?\DateTime $startdate,
                private ?string $color,
            )
            {
                parent::__construct($fields);
            }
            public function getStartdate(): ?\DateTime
            {
                return $this->startdate;
            }
            public function getColor(): ?string
            {
                return $this->color;
            }
        };
    }

    /**
     * Makes every `setUserId()` call record itself in the call log.
     *
     * @return void
     */
    private function recordSetUserId(): void {
        $this->services[BoardService::class]->method('setUserId')->willReturnCallback(function (string $userId): void {
            $this->calls[] = 'setUserId:' . $userId;
        });
    }

    /**
     * Answers a follow-up over one board the caller manages, holding one stack with the given cards.
     *
     * @param list<Card> $cards Cards of the single stack of board 4.
     */
    private function managedBoardWith(array $cards): void {
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4)]);
        $this->services[PermissionService::class]->method('getPermissions')->with(4, 'alice')->willReturn([Acl::PERMISSION_MANAGE => true]);
        $this->services[StackMapper::class]->method('findAll')->with(4)->willReturn([new Stack(['id' => 10, 'boardId' => 4])]);
        $this->services[CardMapper::class]->method('findAllForStacks')->with([10])->willReturn([10 => $cards]);
    }

    /**
     * Makes the user manager resolve one account for the display-name lookup.
     *
     * @param string $uid UID to resolve.
     * @param string $displayName Name that account reports.
     * @return void
     */
    private function recordUser(string $uid, string $displayName): void {
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn($displayName);
        $this->services[IUserManager::class]->method('get')->with($uid)->willReturn($user);
    }

    /**
     * Gateway reading the session the way the DI container does for the Deck services.
     *
     * The CardService double behaves like Deck v1.17.5 `CardService::create()`: the row is written first, then
     * `enrichCards()` calls `IUserManager::get($this->userId)`, a TypeError when the injected `userId` is null.
     *
     * @param string|null $sessionUid `user_id` of the session, which Deck receives as its injected `userId`.
     * @param list<string> $written Titles the Deck persisted, filled by reference.
     * @return DeckDoubles Gateway wired to that session.
     */
    private function sessionCapabilities(?string $sessionUid, array &$written): DeckDoubles {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(static fn(string $key): mixed => $key === 'user_id' ? $sessionUid : null);
        $this->recordSetUserId();
        $this->services[CardService::class]->method('create')->willReturnCallback(function (string $title) use (&$written, $sessionUid): Card {
            $written[] = $title;
            if ($sessionUid === null) {
                throw new \TypeError('OC\User\Manager::get(): Argument #1 ($uid) must be of type string, null given');
            }
            return $this->card();
        });
        $this->services[CardService::class]->method('delete')->willReturnCallback(function () use (&$written): Card {
            $written[] = 'delete';
            return $this->card();
        });
        $this->services[AssignmentService::class] = $this->createMock(AssignmentService::class);
        $this->services[AssignmentService::class]->method('assignUser')->willReturnCallback(function () use (&$written): Assignment {
            $written[] = 'assign';
            return new Assignment();
        });
        return $this->nativeDeck($this->container, $this->time, null, $session);
    }

    /**
     * Keeps only the entries of the call log that start with one of the given prefixes.
     *
     * @param string ...$prefixes Prefixes to keep, for example a class name or a method name.
     * @return list<string> Matching entries, in call order.
     */
    private function record(string ...$prefixes): array {
        return array_values(array_filter(
            $this->calls,
            static function (string $call) use ($prefixes): bool {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($call, $prefix)) {
                        return true;
                    }
                }
                return false;
            },
        ));
    }

    /**
     * @param int $id Board id.
     * @param string|null $title Board title, or null for the default one.
     * @param string $owner UID of the board owner.
     * @return \OCA\Deck\Db\Board Board double.
     */
    private function boardDouble(
        int $id,
        ?string $title = null,
        string $owner = '',
    ): \OCA\Deck\Db\Board {
        return new \OCA\Deck\Db\Board(['id' => $id, 'title' => $title ?? 'Board ' . $id, 'owner' => $owner]);
    }
}
