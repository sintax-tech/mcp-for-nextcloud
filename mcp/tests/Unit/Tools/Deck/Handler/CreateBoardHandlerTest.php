<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\CreateBoardHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_create_board`: a board, its lists and its first cards from one confirmed call.
 *
 * The rule behind most of these tests is the bug of 0.9.1: once something was written, the answer is never an
 * error, because the client retries an error and builds the same board twice.
 */
final class CreateBoardHandlerTest extends TestCase {
	use DeckTestHelpers;

	/** @var DeckGatewayInterface&MockObject */
	private DeckGatewayInterface $gateway;

	private CreateBoardHandler $handler;

	protected function setUp(): void {
		$this->gateway = $this->createMock(DeckGatewayInterface::class);
		$this->handler = new CreateBoardHandler($this->gateway, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @return array<string, mixed> Arguments of a board with three lists and three cards.
	 */
	private function arguments(): array {
		return [
			'title' => 'Projeto Alfa',
			'stacks' => [
				['title' => 'A fazer', 'cards' => [
					['title' => 'Briefing', 'description' => 'Reunir requisitos', 'duedate' => '2026-10-05', 'assignees' => ['alice']],
					['title' => 'Escopo'],
				]],
				['title' => 'Em andamento', 'cards' => [['title' => 'Layout']]],
				['title' => 'Feito'],
			],
		];
	}

	public function testBuildsTheBoardThenTheListsThenTheCardsInTheOrderGiven(): void {
		$calls = [];
		$this->gateway->expects(self::once())->method('createBoard')->with('alice', 'Projeto Alfa', '0082c9')
			->willReturnCallback(function () use (&$calls): Board {
				$calls[] = 'board';

				return new Board(['id' => 3, 'title' => 'Projeto Alfa', 'color' => '0082c9']);
			});
		$this->gateway->expects(self::exactly(3))->method('createStack')
			->willReturnCallback(function (string $user, int $board, string $title, ?int $order) use (&$calls): Stack {
				$calls[] = 'stack:' . $title . '@' . $order;

				return new Stack(['id' => 10 + (int)$order, 'boardId' => $board, 'title' => $title, 'order' => $order]);
			});
		$this->gateway->expects(self::exactly(3))->method('createCard')
			->willReturnCallback(function (string $user, int $stack, string $title, string $description, ?string $duedate, ?int $order) use (&$calls): \OCA\Deck\Db\Card {
				$calls[] = 'card:' . $title . '@' . $stack . '/' . $order;

				return $this->card(['id' => 100 + count($calls), 'stackId' => $stack, 'title' => $title]);
			});
		$this->gateway->expects(self::once())->method('assignCardUser')->with('alice', self::anything(), 'alice')
			->willReturn(new Assignment(['cardId' => 1, 'participant' => 'alice', 'type' => 0]));

		$result = $this->handler->handle($this->arguments() + ['confirm' => true], 'alice');

		self::assertArrayNotHasKey('isError', $result);
		self::assertSame([
			'board',
			'stack:A fazer@0', 'card:Briefing@10/0', 'card:Escopo@10/1',
			'stack:Em andamento@1', 'card:Layout@11/0',
			'stack:Feito@2',
		], $calls);
		$payload = $this->payload($result);
		self::assertTrue($payload['complete']);
		self::assertSame(['id' => 3, 'title' => 'Projeto Alfa'], $payload['created']['board']);
		self::assertSame(['A fazer', 'Em andamento', 'Feito'], array_column($payload['created']['stacks'], 'title'));
		self::assertSame([10, 11, 12], array_column($payload['created']['stacks'], 'id'));
		self::assertSame(['Briefing', 'Escopo'], array_column($payload['created']['stacks'][0]['cards'], 'title'));
		self::assertSame([], $payload['failed']);
		self::assertSame([], $payload['warnings']);
		self::assertStringContainsString('Projeto Alfa', $payload['summary']);
		self::assertStringContainsString('3 lists', $payload['summary']);
		self::assertStringContainsString('3 cards', $payload['summary']);
	}

	public function testAnythingTheStructureRefusesIsRefusedBeforeTheBoardExists(): void {
		$this->gateway->expects(self::never())->method('createBoard');
		$this->gateway->expects(self::never())->method('createStack');
		$this->gateway->expects(self::never())->method('createCard');

		$this->expectException(ArgumentValidationException::class);
		$this->handler->handle([
			'title' => 'Projeto',
			'stacks' => [['title' => 'L', 'cards' => [['title' => 'C', 'assignees' => ['pedro']]]]],
			'confirm' => true,
		], 'alice');
	}

	/** Nothing exists yet when the board itself cannot be created, so a plain error is the truth. */
	public function testFailureToCreateTheBoardIsAnErrorBecauseNothingWasWritten(): void {
		$this->gateway->method('createBoard')->willThrowException(new NoPermissionException('Creating boards has been disabled for your account.'));
		$this->gateway->expects(self::never())->method('createStack');

		$result = $this->handler->handle($this->arguments() + ['confirm' => true], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}

	public function testAListThatFailsLeavesAPartialResultAndItsCardsAreNotTried(): void {
		$this->gateway->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto Alfa']));
		$this->gateway->method('createStack')->willReturnCallback(
			static fn (string $user, int $board, string $title, ?int $order): Stack => $title === 'Em andamento'
				? throw new \RuntimeException('SQLSTATE[HY000] secret detail')
				: new Stack(['id' => 20 + (int)$order, 'title' => $title]),
		);
		$created = [];
		$this->gateway->method('createCard')->willReturnCallback(function (string $user, int $stack, string $title) use (&$created): \OCA\Deck\Db\Card {
			$created[] = $title;

			return $this->card(['id' => count($created), 'stackId' => $stack, 'title' => $title]);
		});
		$this->gateway->method('assignCardUser')->willReturn(new Assignment());

		$result = $this->handler->handle($this->arguments() + ['confirm' => true], 'alice');

		self::assertArrayNotHasKey('isError', $result, 'something exists, so the answer is not an error');
		$payload = $this->payload($result);
		self::assertFalse($payload['complete']);
		self::assertSame(['Briefing', 'Escopo'], $created, 'the cards of the failed list are not written');
		self::assertSame(['A fazer', 'Feito'], array_column($payload['created']['stacks'], 'title'));
		self::assertSame([['kind' => 'list', 'title' => 'Em andamento', 'reason' => DeckMessages::errorGeneric(), 'cardsNotCreated' => 1]], $payload['failed']);
		self::assertStringContainsString('Nothing was undone', $payload['summary']);
		self::assertStringContainsString('Projeto Alfa', $payload['summary']);
		self::assertStringNotContainsString('secret detail', (string)$result['content'][0]['text']);
	}

	public function testACardThatFailsDoesNotStopTheOthers(): void {
		$this->gateway->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto Alfa']));
		$this->gateway->method('createStack')->willReturnCallback(
			static fn (string $user, int $board, string $title, ?int $order): Stack => new Stack(['id' => 20 + (int)$order, 'title' => $title]),
		);
		$this->gateway->method('createCard')->willReturnCallback(function (string $user, int $stack, string $title): \OCA\Deck\Db\Card {
			if ($title === 'Briefing') {
				// The shape of the 0.9.1 bug: Deck wrote, then raised.
				throw new \TypeError('OC\User\Manager::get(): Argument #1 ($uid) must be of type string, null given');
			}

			return $this->card(['id' => 50, 'stackId' => $stack, 'title' => $title]);
		});

		$result = $this->handler->handle($this->arguments() + ['confirm' => true], 'alice');

		self::assertArrayNotHasKey('isError', $result);
		$payload = $this->payload($result);
		self::assertFalse($payload['complete']);
		self::assertSame([['kind' => 'card', 'list' => 'A fazer', 'title' => 'Briefing', 'reason' => DeckMessages::errorGeneric()]], $payload['failed']);
		self::assertSame(['Escopo'], array_column($payload['created']['stacks'][0]['cards'], 'title'));
		self::assertSame(['Layout'], array_column($payload['created']['stacks'][1]['cards'], 'title'));
	}

	/** The card exists; being unable to make the owner its assignee is a warning and never a reason to retry. */
	public function testAnAssignmentThatFailsIsAWarningAndKeepsTheCard(): void {
		$this->gateway->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto Alfa']));
		$this->gateway->method('createStack')->willReturn(new Stack(['id' => 20, 'title' => 'A fazer']));
		$this->gateway->method('createCard')->willReturn($this->card(['id' => 50, 'title' => 'Briefing']));
		$this->gateway->method('assignCardUser')->willThrowException(new \RuntimeException('deadlock'));

		$result = $this->handler->handle([
			'title' => 'Projeto Alfa',
			'stacks' => [['title' => 'A fazer', 'cards' => [['title' => 'Briefing', 'assignees' => ['alice']]]]],
			'confirm' => true,
		], 'alice');

		self::assertArrayNotHasKey('isError', $result);
		$payload = $this->payload($result);
		self::assertTrue($payload['complete']);
		self::assertSame([50], array_column($payload['created']['stacks'][0]['cards'], 'id'));
		self::assertCount(1, $payload['warnings']);
		self::assertStringContainsString('Briefing', $payload['warnings'][0]);
	}

	public function testABoardWithoutListsIsJustTheBoard(): void {
		$this->gateway->expects(self::once())->method('createBoard')->with('alice', 'Vazio', 'ff0000')
			->willReturn(new Board(['id' => 4, 'title' => 'Vazio']));
		$this->gateway->expects(self::never())->method('createStack');

		$payload = $this->payload($this->handler->handle(['title' => 'Vazio', 'color' => '#FF0000', 'confirm' => true], 'alice'));

		self::assertSame(4, $payload['created']['board']['id']);
		self::assertSame([], $payload['created']['stacks']);
		self::assertTrue($payload['complete']);
	}
}
