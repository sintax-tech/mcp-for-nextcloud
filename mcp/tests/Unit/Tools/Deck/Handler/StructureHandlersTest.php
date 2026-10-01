<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\Handler\CreateStackHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteBoardHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteStackHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_create_stack`, `deck_delete_stack` and `deck_delete_board`.
 */
final class StructureHandlersTest extends TestCase {
	use DeckTestHelpers;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * @param string $owner Owner the board of the lookups reports.
	 * @return DeckGatewayInterface&MockObject Gateway whose board and list lookups report that owner.
	 */
	private function gateway(string $owner = 'alice'): DeckGatewayInterface&MockObject {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('boardOwnership')->willReturn($this->ownershipOf($owner, 'Comercial'));
		$gateway->method('stackOwnership')->willReturn($this->ownershipOf($owner, 'Comercial'));

		return $gateway;
	}

	public function testCreateStackWritesAtTheEndByDefault(): void {
		$gateway = $this->gateway();
		$gateway->expects(self::once())->method('createStack')->with('alice', 4, 'Revisão', null)
			->willReturn(new Stack(['id' => 12, 'boardId' => 4, 'title' => 'Revisão', 'order' => 3]));

		$payload = $this->payload((new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => '  Revisão ', 'confirm' => true], 'alice'));

		self::assertSame(['id' => 12, 'boardId' => 4, 'title' => 'Revisão', 'order' => 3], $payload);
	}

	public function testCreateStackAtAGivenPosition(): void {
		$gateway = $this->gateway();
		$gateway->expects(self::once())->method('createStack')->with('alice', 4, 'Topo', 0)
			->willReturn(new Stack(['id' => 13, 'boardId' => 4, 'title' => 'Topo', 'order' => 0]));

		$this->payload((new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'Topo', 'order' => 0, 'confirm' => true], 'alice'));
	}

	public function testCreateStackRefusesABlankTitleBeforeAnyLookup(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::never())->method('boardOwnership');
		$gateway->expects(self::never())->method('createStack');

		$this->expectException(ArgumentValidationException::class);
		(new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => '   ', 'confirm' => true], 'alice');
	}

	public function testCreateStackOnABoardOfSomebodyElseNeedsConfirmShared(): void {
		$gateway = $this->gateway('pedro');
		$gateway->expects(self::never())->method('createStack');

		$result = (new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'Nova', 'confirm' => true], 'alice');

		self::assertTrue($this->payload($result)['requiresConfirmation']);
	}

	public function testCreateStackOnASharedBoardWithConfirmationWrites(): void {
		$gateway = $this->gateway('pedro');
		$gateway->expects(self::once())->method('createStack')->willReturn(new Stack(['id' => 14, 'boardId' => 4, 'title' => 'Nova', 'order' => 1]));

		$result = (new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'Nova', 'confirm' => true, 'confirm_shared' => true], 'alice');

		self::assertSame(14, $this->payload($result)['id']);
	}

	public function testCreateStackOnAnUnreadableBoardIsRefusedBeforeWriting(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('boardOwnership')->willThrowException(new NoPermissionException('Permission denied'));
		$gateway->expects(self::never())->method('createStack');

		$result = (new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'Nova', 'confirm' => true], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}

	public function testDeleteStackDeletesAnEmptyList(): void {
		$gateway = $this->gateway();
		$gateway->expects(self::once())->method('deleteEmptyStack')->with('alice', 10)
			->willReturn(new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Feito']));

		$payload = $this->payload((new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice'));

		self::assertSame(['id' => 10, 'boardId' => 4, 'title' => 'Feito', 'deleted' => true], $payload);
	}

	/** The refusal is a result with its own words and a warning in the log, never an alarm: nothing broke. */
	public function testDeleteStackWithCardsIsRefusedWithTheCountAndLoggedAsAWarning(): void {
		$gateway = $this->gateway();
		$gateway->method('deleteEmptyStack')->willThrowException(DeckRefusalException::stackNotEmpty(3));
		$this->logger->expects(self::never())->method('error');
		$this->logger->expects(self::once())->method('warning');

		$result = (new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame('The list has 3 cards; move or delete them first.', $this->text($result));
	}

	public function testDeleteStackOnASharedBoardNeedsConfirmShared(): void {
		$gateway = $this->gateway('pedro');
		$gateway->expects(self::never())->method('deleteEmptyStack');

		$result = (new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice');

		self::assertTrue($this->payload($result)['requiresConfirmation']);
	}

	public function testDeleteBoardDeletesAnEmptyBoardOfTheOwner(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())->method('deleteEmptyBoard')->with('alice', 4)
			->willReturn(new Board(['id' => 4, 'title' => 'Vazio']));

		$payload = $this->payload((new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice'));

		self::assertSame(['id' => 4, 'title' => 'Vazio', 'deleted' => true], $payload);
	}

	public function testDeleteBoardWithCardsIsRefused(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardNotEmpty(1));

		$result = (new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame('The board has 1 card; move or delete it first.', $this->text($result));
	}

	public function testDeleteBoardOfSomebodyElseIsRefused(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardNotOwned());

		$result = (new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice');

		self::assertSame('Only the owner of a board can delete it.', $this->text($result));
	}

	public function testDeckFailuresStayGenericOnEveryStructureTool(): void {
		$gateway = $this->gateway();
		$boom = new \RuntimeException('SQLSTATE secret');
		$gateway->method('createStack')->willThrowException($boom);
		$gateway->method('deleteEmptyStack')->willThrowException($boom);
		$gateway->method('deleteEmptyBoard')->willThrowException($boom);

		foreach ([
			(new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'X', 'confirm' => true], 'alice'),
			(new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice'),
			(new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice'),
		] as $result) {
			self::assertTrue($result['isError']);
			self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
		}
	}
}
