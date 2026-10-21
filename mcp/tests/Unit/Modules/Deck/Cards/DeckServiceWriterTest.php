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

final class DeckServiceWriterTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\NativeDeckAdapterFixtures;
    public function testCreateCardIsANoteOwnedByTheCallerAtTheEndOfTheStack(): void {
        $card = $this->card();
        $this->recordSetUserId();
        $this->services[CardService::class]->expects(self::once())->method('create')->with(
            'Fechar',
            10,
            'note',
            99999,
            'alice',
            'Com o cliente',
            \OCA\Mcp\Modules\Deck\Cards\CardDate::localMidnight('2026-03-01', new \DateTimeZone(date_default_timezone_get())),
        )->willReturn($card);
        self::assertSame($card, $this->capabilities->writer->createCard('alice', 10, 'Fechar', 'Com o cliente', '2026-03-01'));
    }

    public function testDayWrittenByTheToolsIsMidnightInTheCallerTimezone(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('America/Sao_Paulo');
        $capabilities = $this->nativeDeck($this->container, $this->time, new UserTimezone($config), session: $this->deckSession());
        $this->recordSetUserId();
        $this->services[CardService::class]->expects(self::once())->method('create')->with('Fechar', 10, 'note', 99999, 'alice', '', '2026-10-01T00:00:00-03:00')->willReturn($this->card());
        $capabilities->writer->createCard('alice', 10, 'Fechar', '', '2026-10-01');
    }

    public function testUpdateKeepsAFullTimestampUntouched(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('America/Sao_Paulo');
        $capabilities = $this->nativeDeck($this->container, $this->time, new UserTimezone($config), session: $this->deckSession());
        $card = $this->card(['id' => 7, 'duedate' => new \DateTime('2026-10-01 18:30:00', new \DateTimeZone('UTC'))]);
        $this->recordSetUserId();
        $this->services[CardService::class]->expects(self::once())->method('update')->with(7, 'Card', 10, 'note', 'alice', '', 0, '2026-10-01T18:30:00+00:00')->willReturn($card);
        $capabilities->writer->updateCard('alice', $card, 'Card', '', '2026-10-01T18:30:00+00:00');
    }

    public function testUpdateCardPreservesEveryFieldTheCallerDidNotChange(): void {
        $card = $this->card([
            'id' => 7,
            'stackId' => 10,
            'type' => 'note',
            'owner' => 'alice',
            'order' => 3,
            'duedate' => new \DateTime('2026-01-05 00:00:00'),
            'done' => new \DateTime('2026-01-02 10:00:00'),
            'archived' => true,
        ]);
        $this->recordSetUserId();
        $this->services[CardService::class]->expects(self::once())->method('update')->with(
            7,
            'Fechar',
            10,
            'note',
            'alice',
            'Novo texto',
            3,
            null,
            null,
            null,
            self::callback(static function (mixed $done): bool {
                // Deck v1.17.5 nulls the column when this argument is left out, so it is sent back wrapped.
                return $done instanceof OptionalNullableValue && $done->getValue()?->format('Y-m-d H:i:s') === '2026-01-02 10:00:00';
            }),
        )->willReturn($card);
        self::assertSame($card, $this->capabilities->writer->updateCard('alice', $card, 'Fechar', 'Novo texto', null));
    }

    /**
     * Deck 1.18 (Nextcloud 34) added `?string $startdate` and `?string $color` after `done` and writes both on every
     * update, so an edit that leaves them out clears the start date and the colour of the card.
     */
    public function testUpdateCardKeepsTheStartDateAndTheColourOnDeck118(): void {
        $deck = new class
        {
            /** @var list<mixed> */
            public array $arguments = [];
            public function update(
                int $id,
                string $title,
                int $stackId,
                string $type,
                string $owner,
                string $description = '',
                int $order = 0,
                ?string $duedate = null,
                ?int $deletedAt = null,
                ?bool $archived = null,
                ?OptionalNullableValue $done = null,
                ?string $startdate = null,
                ?string $color = null,
            ): Card
            {
                $this->arguments = func_get_args();
                return new Card(['id' => $id]);
            }
        };
        $this->services[CardService::class] = $deck;
        $this->recordSetUserId();
        $this->capabilities->writer->updateCard(
            'alice',
            $this->deck118Card(new \DateTime('2026-02-01 09:00:00', new \DateTimeZone('UTC')), 'e9322d'),
            'Fechar',
            'Novo texto',
            null,
        );
        self::assertCount(13, $deck->arguments);
        self::assertSame('2026-02-01T09:00:00+00:00', $deck->arguments[11]);
        self::assertEquals(
            new \DateTime('2026-02-01 09:00:00', new \DateTimeZone('UTC')),
            new \DateTime($deck->arguments[11]),
            'Deck reads it back with new DateTime()',
        );
        self::assertSame('e9322d', $deck->arguments[12]);
    }

    /** Deck 1.19 (Nextcloud 35) leaves the colour alone on a null argument, but still clears a start date left out. */
    public function testUpdateCardKeepsTheStartDateAndTheColourOnDeck119(): void {
        $deck = new class
        {
            /** @var list<mixed> */
            public array $arguments = [];
            public function update(
                int $id,
                string $title,
                int $stackId,
                string $type,
                string $owner,
                string $description = '',
                int $order = 0,
                ?string $duedate = null,
                ?int $deletedAt = null,
                ?bool $archived = null,
                ?OptionalNullableValue $done = null,
                ?string $startdate = null,
                ?OptionalNullableValue $color = null,
            ): Card
            {
                $this->arguments = func_get_args();
                return new Card(['id' => $id]);
            }
        };
        $this->services[CardService::class] = $deck;
        $this->recordSetUserId();
        $this->capabilities->writer->updateCard(
            'alice',
            $this->deck118Card(new \DateTime('2026-02-01 09:00:00', new \DateTimeZone('UTC')), 'e9322d'),
            'Fechar',
            'Novo texto',
            null,
        );
        self::assertCount(13, $deck->arguments);
        self::assertSame('2026-02-01T09:00:00+00:00', $deck->arguments[11]);
        self::assertInstanceOf(OptionalNullableValue::class, $deck->arguments[12]);
        self::assertSame('e9322d', $deck->arguments[12]->getValue());
    }

    /** A card without a start date or a colour keeps having none. */
    public function testUpdateCardSendsNoStartDateOrColourWhenTheCardHasNone(): void {
        $deck = new class
        {
            /** @var list<mixed> */
            public array $arguments = [];
            public function update(
                int $id,
                string $title,
                int $stackId,
                string $type,
                string $owner,
                string $description = '',
                int $order = 0,
                ?string $duedate = null,
                ?int $deletedAt = null,
                ?bool $archived = null,
                ?OptionalNullableValue $done = null,
                ?string $startdate = null,
                ?string $color = null,
            ): Card
            {
                $this->arguments = func_get_args();
                return new Card(['id' => $id]);
            }
        };
        $this->services[CardService::class] = $deck;
        $this->recordSetUserId();
        $this->capabilities->writer->updateCard('alice', $this->deck118Card(null, null), 'Fechar', '', null);
        self::assertSame([null, null], array_slice($deck->arguments, 11));
    }

    /**
     * Up to Deck 1.17 the update ends at `done` and the card has no start date or colour: reading either would fail
     * on the real entity, and the stub card of these tests has neither getter.
     */
    public function testUpdateCardSendsNothingAfterDoneOnDeck117(): void {
        $card = $this->card(['id' => 7]);
        $this->recordSetUserId();
        $this->services[CardService::class]->expects(self::once())->method('update')->willReturnCallback(static function (mixed ...$arguments) use ($card): Card {
            self::assertCount(11, $arguments);
            return $card;
        });
        $this->capabilities->writer->updateCard('alice', $card, 'Fechar', '', null);
    }

    public function testMoveCardWithoutOrderChecksTheDestinationStackBeforeReadingIt(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(self::isInstanceOf(StackMapper::class), 11, Acl::PERMISSION_READ)->willReturnCallback(function (): bool {
            $this->calls[] = 'checkPermission';
            return true;
        });
        $this->services[CardMapper::class]->expects(self::once())->method('findAll')->with(11)->willReturnCallback(function (): array {
            $this->calls[] = 'findAll';
            return [
                $this->card(['id' => 1, 'order' => 0]),
                $this->card(['id' => 2, 'order' => 5]),
                $this->card(['id' => 3, 'order' => 2]),
            ];
        });
        $this->services[CardService::class]->expects(self::once())->method('reorder')->with(7, 11, 6)->willReturn([$this->card(['id' => 7, 'stackId' => 11, 'order' => 6])]);
        $moved = $this->capabilities->writer->moveCard('alice', 7, 11, null);
        self::assertSame(11, $moved->getStackId());
        self::assertSame(['checkPermission', 'findAll'], $this->record('checkPermission', 'findAll'));
    }

    public function testMoveCardWithExplicitOrderNeverReadsTheDestinationStack(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->expects(self::never())->method('checkPermission');
        $this->services[CardMapper::class]->expects(self::never())->method('findAll');
        $this->services[CardService::class]->expects(self::once())->method('reorder')->with(7, 11, 2)->willReturn([$this->card(['id' => 7, 'stackId' => 11])]);
        self::assertSame(11, $this->capabilities->writer->moveCard('alice', 7, 11, 2)->getStackId());
    }

    public function testMoveCardFallsBackToReadingTheCardWhenReorderDoesNotReturnIt(): void {
        $this->recordSetUserId();
        $this->services[CardService::class]->method('reorder')->willReturn([$this->card(['id' => 99, 'stackId' => 11])]);
        $this->services[CardService::class]->expects(self::once())->method('find')->with(7)->willReturn($this->card(['id' => 7, 'stackId' => 11]));
        self::assertSame(7, $this->capabilities->writer->moveCard('alice', 7, 11, 2)->getId());
    }

    public function testDeleteCardUsesTheDeckSoftDelete(): void {
        $card = $this->card(['deletedAt' => 1700000900]);
        $this->recordSetUserId();
        $this->services[CardService::class]->expects(self::once())->method('delete')->with(7)->willReturn($card);
        self::assertSame($card, $this->capabilities->writer->deleteCard('alice', 7));
    }

    /** A session that belongs to somebody else is refused the same way, never written as that account. */
    public function testSessionOfAnotherAccountIsRefused(): void {
        $written = [];
        $capabilities = $this->sessionCapabilities('bob', $written);
        $this->expectException(DeckSessionException::class);
        try {
            $capabilities->writer->createCard('alice', 10, 'Fechar', '', null);
        } finally {
            self::assertSame([], $written);
        }
    }

    public function testCallerInTheSessionWritesOnce(): void {
        $written = [];
        $capabilities = $this->sessionCapabilities('alice', $written);
        $capabilities->writer->createCard('alice', 10, 'Fechar', '', null);
        $capabilities->writer->deleteCard('alice', 7);
        self::assertSame(['Fechar', 'delete'], $written);
    }
}
