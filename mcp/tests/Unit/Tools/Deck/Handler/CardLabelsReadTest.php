<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
use OCA\Mcp\Modules\Deck\Reading\ListCardsHandler;
use OCA\Mcp\Modules\Deck\Reading\ReadCardHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/** `deck_read_card` and `deck_list_cards` show the labels Deck attached to a card, in one shape. */
final class CardLabelsReadTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param int $id Label id.
	 * @param string $title Label title.
	 * @param string $color Label color.
	 * @param int $cardId Card of the association.
	 * @return Label Label as the Deck mapper returns it.
	 */
	private function label(int $id, string $title, string $color, int $cardId = 1): Label {
		return new Label(['id' => $id, 'title' => $title, 'color' => $color, 'boardId' => 4, 'cardId' => $cardId]);
	}

	/** @return iterable<string, array{list<Label>, list<array{id: int, title: string, color: string}>}> zero, one and several labels */
	public static function labelSets(): iterable {
		yield 'no label' => [[], []];
		yield 'one label' => [[new Label(['id' => 3, 'title' => 'Urgent', 'color' => 'ff0000', 'cardId' => 1])],
			[['id' => 3, 'title' => 'Urgent', 'color' => 'ff0000']]];
		yield 'several labels' => [[
			new Label(['id' => 3, 'title' => 'Urgent', 'color' => 'ff0000', 'cardId' => 1]),
			new Label(['id' => 7, 'title' => 'Client', 'color' => '00aa00', 'cardId' => 1]),
			new Label(['id' => 9, 'title' => 'Later', 'color' => '0000ff', 'cardId' => 1]),
		], [
			['id' => 3, 'title' => 'Urgent', 'color' => 'ff0000'],
			['id' => 7, 'title' => 'Client', 'color' => '00aa00'],
			['id' => 9, 'title' => 'Later', 'color' => '0000ff'],
		]];
	}

	/**
	 * @param list<Label> $labels Labels Deck attached to the card.
	 * @param list<array{id: int, title: string, color: string}> $expected Labels the tools answer.
	 * @return void read and list give the same card, the labels in Deck's order, every other field unchanged
	 */
	#[DataProvider('labelSets')]
	public function testReadAndListShowTheSameLabels(array $labels, array $expected): void {
		$card = $this->card(['id' => 1, 'labels' => $labels, 'relatedBoard' => new Board(['id' => 4])]);
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->with('alice', 1)->willReturn($card);
		$gateway->method('listCards')->willReturn(['items' => [$card], 'boardId' => 4]);
		$logger = $this->createMock(LoggerInterface::class);

		$read = $this->payload((new ReadCardHandler($gateway, $logger, $this->cardFormatter()))->handle(['cardId' => 1], 'alice'));
		$listed = $this->payload((new ListCardsHandler($gateway, $logger, $this->cardFormatter()))->handle(['stackId' => 10], 'alice'))['cards'][0];

		self::assertSame($expected, $read['labels']);
		self::assertSame($read, $listed);
		$withoutLabels = $read;
		unset($withoutLabels['labels']);
		self::assertSame(json_decode(json_encode($this->cardFormatter()->card($card, 4)), true), $withoutLabels);
	}

	/** @return void a listing page reads the labels of all its cards in one query, and a card without any gets an empty list */
	public function testListingAttachesLabelsOfTheWholePageInOneQuery(): void {
		$services = $this->services([$this->card(['id' => 1]), $this->card(['id' => 2]), $this->card(['id' => 3])]);
		$services[LabelMapper::class]->expects(self::once())->method('findAssignedLabelsForCards')->with([1, 2, 3])
			->willReturn([$this->label(3, 'Urgent', 'ff0000', 1), $this->label(7, 'Client', '00aa00', 1), $this->label(3, 'Urgent', 'ff0000', 3)]);

		$page = $this->gateway($services)->listCards('alice', 10, 25, 0);

		self::assertSame([[3, 7], [], [3]], array_map(
			static fn (Card $card): array => array_map(static fn (Label $label): int => $label->getId(), $card->getLabels()),
			$page['items'],
		));
	}

	/** @return void an empty page asks Deck for no labels at all */
	public function testEmptyPageDoesNotQueryLabels(): void {
		$services = $this->services([]);
		$services[LabelMapper::class]->expects(self::never())->method('findAssignedLabelsForCards');

		self::assertSame([], $this->gateway($services)->listCards('alice', 10, 25, 0)['items']);
	}

	/**
	 * @param list<Card> $cards Cards of the readable stack.
	 * @return array<string, object> Deck services a listing resolves
	 */
	private function services(array $cards): array {
		$services = [];
		foreach ([BoardService::class, PermissionService::class, CardMapper::class, StackMapper::class, AssignmentMapper::class, LabelMapper::class] as $class) {
			$services[$class] = $this->createMock($class);
		}
		$services[PermissionService::class]->method('checkPermission')->willReturn(true);
		$services[CardMapper::class]->method('findAll')->willReturn($cards);
		$services[StackMapper::class]->method('findBoardId')->willReturn(4);
		$services[AssignmentMapper::class]->method('findIn')->willReturn([]);
		return $services;
	}

	/**
	 * @param array<string, object> $services Deck services by class.
	 * @return DeckServiceGateway real gateway over those services
	 */
	private function gateway(array $services): DeckServiceGateway {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id] ?? throw new \LogicException('Unexpected container lookup: ' . $id));
		return new DeckServiceGateway($container, $this->createMock(ITimeFactory::class));
	}
}
