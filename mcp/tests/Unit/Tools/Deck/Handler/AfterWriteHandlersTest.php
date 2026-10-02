<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckConflictException;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\DeckSessionException;
use OCA\Mcp\Tools\Deck\DeckUnconfirmedException;
use OCA\Mcp\Tools\Deck\Handler\CreateBoardHandler;
use OCA\Mcp\Tools\Deck\Handler\CreateCardHandler;
use OCA\Mcp\Tools\Deck\Handler\CreateStackHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteBoardHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteCardHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteStackHandler;
use OCA\Mcp\Tools\Deck\Handler\EditCardHandler;
use OCA\Mcp\Tools\Deck\Handler\MoveCardHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Re-review of c06b2e0: Deck 1.17.5 writes first and only then runs activity, events and notifications, any of which
 * may throw. A write tool never answers with an error once the write may exist: after an exception of the write call
 * itself the handler reads the real state again. Written: success with a warning. Not written: the usual error.
 * Unknown: a result that is not an error and says to read before trying again.
 *
 * Every stub here throws after "writing", the way a failing listener would, and its message is a secret that must
 * never reach the answer or the log.
 */
final class AfterWriteHandlersTest extends TestCase {
	use DeckTestHelpers;

	private const SECRET = 'listener failed for /secret/path';

	/** @var list<array{string, array<string, mixed>}> Log lines of the handlers, level-independent. */
	private array $logged = [];

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$this->logger->method($level)->willReturnCallback(function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			});
		}
	}

	protected function tearDown(): void {
		foreach ($this->logged as [$message, $context]) {
			self::assertStringNotContainsString(self::SECRET, $message . json_encode($context), 'o log leva só a classe');
		}
	}

	private function afterWrite(): \RuntimeException {
		return new \RuntimeException(self::SECRET);
	}

	/** @param array<string, mixed> $result */
	private function assertWrittenWithWarning(array $result): array {
		self::assertArrayNotHasKey('isError', $result);
		self::assertStringNotContainsString(self::SECRET, $this->text($result));
		$payload = $this->payload($result);
		self::assertContains(DeckMessages::writtenThenFailed(), $payload['warnings']);
		self::assertContains(\RuntimeException::class, array_column(array_column($this->logged, 1), 'exception'));

		return $payload;
	}

	/** @param array<string, mixed> $result */
	private function assertUnconfirmed(array $result, string $readWith): void {
		self::assertArrayNotHasKey('isError', $result, 'nunca um erro que convide a repetir');
		self::assertSame(['confirmed' => false, 'message' => DeckMessages::writeUnconfirmed($readWith)], $this->payload($result));
	}

	/** @param array<string, mixed> $result */
	private function assertPlainError(array $result): void {
		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
	}

	private function cards(DeckGatewayInterface $gateway): array {
		$formatter = $this->cardFormatter();

		return [
			'create' => new CreateCardHandler($gateway, $this->logger, $formatter),
			'edit' => new EditCardHandler($gateway, $this->logger, $formatter),
			'move' => new MoveCardHandler($gateway, $this->logger, $formatter),
			'delete' => new DeleteCardHandler($gateway, $this->logger, $formatter),
		];
	}

	// --- deck_create_card ---------------------------------------------------------------------------------------

	public function testACardFoundAfterAListenerFailedIsProbable(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('createCard')->willThrowException($this->afterWrite());
		$gateway->expects(self::once())->method('findCreatedCard')->with('alice', 10, 'Fechar', [])
			->willReturn($this->card(['id' => 77, 'title' => 'Fechar']));

		// The card is found only by owner, title and time, so the answer is "probable", never "saved".
		$payload = $this->assertProbable($this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice'));
		self::assertSame(77, $payload['id']);
	}

	public function testACardThatIsNotThereIsTheUsualError(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('createCard')->willThrowException($this->afterWrite());
		$gateway->method('findCreatedCard')->willReturn(null);

		$this->assertPlainError($this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice'));
	}

	public function testACardThatCannotBeCheckedIsUnconfirmedAndNotAnError(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('createCard')->willThrowException($this->afterWrite());
		$gateway->method('findCreatedCard')->willThrowException(new \RuntimeException('db gone'));

		$this->assertUnconfirmed($this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice'), 'deck_list_cards');
	}

	/** The refusals of this app, raised before Deck is called, stay errors and are not even checked. */
	public function testARefusalOfTheAppBeforeTheWriteIsNeverChecked(): void {
		foreach ([DeckRefusalException::boardNotOwned(), new DeckSessionException('session'), new DeckConflictException('conflict')] as $refusal) {
			$gateway = $this->gatewayOwnedBy('alice');
			$gateway->method('createCard')->willThrowException($refusal);
			$gateway->expects(self::never())->method('findCreatedCard');

			$result = $this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');
			self::assertTrue($result['isError']);
		}
	}

	/**
	 * Third round: a Deck listener may throw the very classes Deck uses for its own checks, after the card is saved.
	 * The class alone proves nothing, so the state is read again.
	 */
	public function testADeckExceptionRaisedByAListenerAfterTheWriteIsStillReadBack(): void {
		foreach ([new NoPermissionException('Permission denied'), new BadRequestException('listener says no')] as $thrown) {
			$gateway = $this->gatewayOwnedBy('alice');
			$gateway->method('findCard')->willReturn($this->card(['id' => 7, 'title' => 'Antigo', 'lastModified' => 1_700_000_000]));
			$gateway->method('updateCard')->willThrowException($thrown);
			$gateway->expects(self::once())->method('cardState')->with('alice', 7)
				->willReturn($this->card(['id' => 7, 'title' => 'Novo', 'lastModified' => 1_700_000_100]));

			$result = $this->cards($gateway)['edit']->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');
			self::assertArrayNotHasKey('isError', $result);
			self::assertContains(DeckMessages::writtenThenFailed(), $this->payload($result)['warnings']);
		}
	}

	/**
	 * A listener of another app may throw a plain `\InvalidArgumentException` after Deck saved the card; only the
	 * exclusive `ArgumentValidationException` of this app is a refusal that skips the read-back.
	 */
	public function testAPlainInvalidArgumentExceptionFromAListenerAfterTheWriteIsReadBack(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('createCard')->willThrowException(new \InvalidArgumentException('listener says no'));
		$gateway->expects(self::once())->method('findCreatedCard')->willReturn($this->card(['id' => 77, 'title' => 'Fechar']));

		$payload = $this->assertProbable($this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice'));
		self::assertSame(77, $payload['id']);

		$edit = $this->gatewayOwnedBy('alice');
		$edit->method('findCard')->willReturn($this->card(['id' => 7, 'title' => 'Antigo', 'lastModified' => 1_700_000_000]));
		$edit->method('updateCard')->willThrowException(new \InvalidArgumentException('listener says no'));
		$edit->expects(self::once())->method('cardState')
			->willReturn($this->card(['id' => 7, 'title' => 'Novo', 'lastModified' => 1_700_000_100]));
		$result = $this->cards($edit)['edit']->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');
		self::assertArrayNotHasKey('isError', $result);
		self::assertContains(DeckMessages::writtenThenFailed(), $this->payload($result)['warnings']);
	}

	/** The validation exception of this app is a refusal before the write: never read back. */
	public function testAnArgumentValidationExceptionOfTheAppIsNeverReadBack(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('createCard')->willThrowException(new ArgumentValidationException('bad', 'title', 'required'));
		$gateway->expects(self::never())->method('findCreatedCard');

		$this->expectException(ArgumentValidationException::class);
		$this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');
	}

	/** A real refusal of Deck, read back, finds nothing: the usual error. */
	public function testARealDeckRefusalReadBackAsNotWrittenIsTheUsualError(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('createCard')->willThrowException(new NoPermissionException('Permission denied'));
		$gateway->expects(self::once())->method('findCreatedCard')->willReturn(null);

		self::assertTrue($this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice')['isError']);
	}

	public function testAnAssignmentSavedBeforeTheListenerFailedIsKept(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->method('createCard')->willReturn($this->card(['id' => 9]));
		$gateway->method('assignCardUser')->willThrowException($this->afterWrite());
		$gateway->expects(self::once())->method('cardAssignments')->with('alice', 9)
			->willReturn([new Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => 0])]);

		$payload = $this->assertWrittenWithWarning($this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'X', 'assignees' => ['pedro']], 'alice'));
		self::assertSame(['pedro'], array_column($payload['assignedUsers'], 'uid'));
		self::assertCount(1, $payload['warnings'], 'sem o aviso de atribuição que falhou');
	}

	// --- deck_edit_card -----------------------------------------------------------------------------------------

	private function editGateway(): DeckGatewayInterface&MockObject {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->card(['id' => 7, 'title' => 'Antigo', 'lastModified' => 1_700_000_000]));
		$gateway->method('updateCard')->willThrowException($this->afterWrite());

		return $gateway;
	}

	public function testAnEditReadBackWithTheNewFieldsIsASuccessWithAWarning(): void {
		$gateway = $this->editGateway();
		$gateway->expects(self::once())->method('cardState')->with('alice', 7)
			->willReturn($this->card(['id' => 7, 'title' => 'Novo', 'lastModified' => 1_700_000_100]));

		$payload = $this->assertWrittenWithWarning($this->cards($gateway)['edit']->handle(['cardId' => 7, 'title' => 'Novo'], 'alice'));
		self::assertSame('Novo', $payload['title']);
	}

	/** A database without 4-byte UTF-8 stores an emoji as U+FFFD: the card it read back is still the one just written. */
	public function testAnEditWithAnEmojiReadBackWithItsReplacementCharacterIsASuccessWithAWarning(): void {
		$gateway = $this->editGateway();
		$gateway->expects(self::once())->method('cardState')->with('alice', 7)
			->willReturn($this->card(['id' => 7, 'title' => 'Novo', 'description' => "Feito \u{FFFD}", 'lastModified' => 1_700_000_100]));

		$payload = $this->assertWrittenWithWarning($this->cards($gateway)['edit']->handle(['cardId' => 7, 'title' => 'Novo', 'description' => "Feito \u{1F680}"], 'alice'));
		self::assertSame('Novo', $payload['title']);
	}

	public function testAnEditReadBackUnchangedIsTheUsualError(): void {
		$gateway = $this->editGateway();
		$gateway->method('cardState')->willReturn($this->card(['id' => 7, 'title' => 'Antigo', 'lastModified' => 1_700_000_000]));

		$this->assertPlainError($this->cards($gateway)['edit']->handle(['cardId' => 7, 'title' => 'Novo'], 'alice'));
	}

	/** Asking for the value the card already has proves nothing: without a new modification time it was not written. */
	public function testAnEditToTheSameValueIsNotTakenForAWrite(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->card(['id' => 7, 'title' => 'Igual', 'lastModified' => 1_700_000_000]));
		$gateway->method('updateCard')->willThrowException($this->afterWrite());
		$gateway->method('cardState')->willReturn($this->card(['id' => 7, 'title' => 'Igual', 'lastModified' => 1_700_000_000]));

		$this->assertPlainError($this->cards($gateway)['edit']->handle(['cardId' => 7, 'title' => 'Igual'], 'alice'));
	}

	public function testAnEditThatCannotBeCheckedIsUnconfirmed(): void {
		$gateway = $this->editGateway();
		$gateway->method('cardState')->willThrowException(new \RuntimeException('db gone'));

		$this->assertUnconfirmed($this->cards($gateway)['edit']->handle(['cardId' => 7, 'title' => 'Novo'], 'alice'), 'deck_read_card');
	}

	public function testAssignAndUnassignSavedBeforeTheListenerFailedAreReportedAsDone(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->card(['id' => 7, 'assignedUsers' => [new Assignment(['cardId' => 7, 'participant' => 'joao', 'type' => 0])]]));
		$gateway->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->method('assignCardUser')->willThrowException($this->afterWrite());
		$gateway->method('unassignCardUser')->willThrowException($this->afterWrite());
		$gateway->method('cardAssignments')->willReturn([new Assignment(['cardId' => 7, 'participant' => 'pedro', 'type' => 0])]);

		$payload = $this->assertWrittenWithWarning($this->cards($gateway)['edit']->handle(['cardId' => 7, 'assign' => ['pedro'], 'unassign' => ['joao']], 'alice'));
		self::assertSame(['pedro'], $payload['assigned']);
		self::assertSame(['joao'], $payload['unassigned']);
		self::assertSame([], $payload['failed']);
	}

	public function testAnAssignmentThatCannotBeCheckedFailsWithTheReadHint(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->card(['id' => 7]));
		$gateway->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->method('assignCardUser')->willThrowException($this->afterWrite());
		$gateway->method('cardAssignments')->willThrowException(new \RuntimeException('db gone'));

		$payload = $this->payload($this->cards($gateway)['edit']->handle(['cardId' => 7, 'assign' => ['pedro']], 'alice'));
		self::assertSame([['uid' => 'pedro', 'reason' => DeckMessages::writeUnconfirmed('deck_read_card')]], $payload['failed']);
	}

	// --- deck_move_card -----------------------------------------------------------------------------------------

	public function testAMoveReadBackInTheDestinationIsASuccessWithAWarning(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('moveCard')->willThrowException($this->afterWrite());
		$gateway->expects(self::exactly(2))->method('cardState')->with('alice', 7)->willReturnOnConsecutiveCalls(
			$this->card(['id' => 7, 'stackId' => 10]),
			$this->card(['id' => 7, 'stackId' => 11]),
		);

		$payload = $this->assertWrittenWithWarning($this->cards($gateway)['move']->handle(['cardId' => 7, 'stackId' => 11], 'alice'));
		self::assertSame(11, $payload['stackId']);
	}

	public function testAMoveReadBackInTheOriginIsTheUsualError(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('moveCard')->willThrowException($this->afterWrite());
		$gateway->method('cardState')->willReturn($this->card(['id' => 7, 'stackId' => 10]));

		$this->assertPlainError($this->cards($gateway)['move']->handle(['cardId' => 7, 'stackId' => 11], 'alice'));
	}

	public function testAMoveThatCannotBeCheckedIsUnconfirmed(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('moveCard')->willThrowException($this->afterWrite());
		$gateway->method('cardState')->willReturnOnConsecutiveCalls(
			$this->card(['id' => 7, 'stackId' => 10]),
			self::throwException(new \RuntimeException('db gone')),
		);

		$this->assertUnconfirmed($this->cards($gateway)['move']->handle(['cardId' => 7, 'stackId' => 11], 'alice'), 'deck_read_card');
	}

	// --- deck_delete_card ---------------------------------------------------------------------------------------

	public function testADeletionReadBackAsDeletedIsASuccessWithAWarning(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('deleteCard')->willThrowException($this->afterWrite());
		$gateway->expects(self::once())->method('cardState')->with('alice', 7)->willReturn($this->card(['id' => 7, 'deletedAt' => 1_700_000_500]));

		$payload = $this->assertWrittenWithWarning($this->cards($gateway)['delete']->handle(['cardId' => 7, 'confirm' => true], 'alice'));
		self::assertSame(7, $payload['id']);
	}

	public function testADeletionReadBackAliveIsTheUsualError(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('deleteCard')->willThrowException($this->afterWrite());
		$gateway->method('cardState')->willReturn($this->card(['id' => 7, 'deletedAt' => 0]));

		$this->assertPlainError($this->cards($gateway)['delete']->handle(['cardId' => 7, 'confirm' => true], 'alice'));
	}

	public function testADeletionThatCannotBeCheckedIsUnconfirmed(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('deleteCard')->willThrowException($this->afterWrite());
		$gateway->method('cardState')->willThrowException(new \RuntimeException('db gone'));

		$this->assertUnconfirmed($this->cards($gateway)['delete']->handle(['cardId' => 7, 'confirm' => true], 'alice'), 'deck_read_card');
	}

	// --- deck_create_stack, deck_delete_stack, deck_delete_board ------------------------------------------------

	private function structureGateway(): DeckGatewayInterface&MockObject {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('boardOwnership')->willReturn($this->ownershipOf('alice', 'Comercial'));
		$gateway->method('stackOwnership')->willReturn($this->ownershipOf('alice', 'Comercial'));

		return $gateway;
	}

	public function testAListFoundAfterAnEventFailedIsProbable(): void {
		$gateway = $this->structureGateway();
		$gateway->method('createStack')->willThrowException($this->afterWrite());
		$gateway->expects(self::exactly(2))->method('stacksOf')->with('alice', 4)->willReturnOnConsecutiveCalls(
			[new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Revisão'])],
			[new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Revisão']), new Stack(['id' => 12, 'boardId' => 4, 'title' => 'Revisão', 'order' => 1])],
		);

		$payload = $this->assertProbable((new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'Revisão', 'confirm' => true], 'alice'));
		self::assertSame(12, $payload['id'], 'a lista que já existia com o mesmo título não conta');
	}

	public function testAListThatIsNotThereIsTheUsualError(): void {
		$gateway = $this->structureGateway();
		$gateway->method('createStack')->willThrowException($this->afterWrite());
		$gateway->method('stacksOf')->willReturn([new Stack(['id' => 10, 'title' => 'Revisão'])]);

		$this->assertPlainError((new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'Revisão', 'confirm' => true], 'alice'));
	}

	public function testAListThatCannotBeCheckedIsUnconfirmed(): void {
		$gateway = $this->structureGateway();
		$gateway->method('createStack')->willThrowException($this->afterWrite());
		$gateway->method('stacksOf')->willReturnOnConsecutiveCalls([], self::throwException(new \RuntimeException('db gone')));

		$this->assertUnconfirmed((new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'Revisão', 'confirm' => true], 'alice'), 'deck_list_stacks');
	}

	public function testAListDeletionReadBackAsDeletedIsASuccessWithAWarning(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyStack')->willThrowException($this->afterWrite());
		$gateway->expects(self::once())->method('deletedStack')->with('alice', 10)->willReturn(new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Velha']));

		$payload = $this->assertWrittenWithWarning((new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice'));
		self::assertSame(['id' => 10, 'boardId' => 4, 'title' => 'Velha', 'deleted' => true], array_diff_key($payload, ['warnings' => 1]));
	}

	public function testAListDeletionThatCannotBeCheckedIsUnconfirmed(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyStack')->willThrowException($this->afterWrite());
		$gateway->method('deletedStack')->willThrowException(new \RuntimeException('db gone'));

		$this->assertUnconfirmed((new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice'), 'deck_list_stacks');
	}

	/** The refusals of the gateway (cards in the list, not the owner) are decided before any write. */
	public function testTheRefusalsOfTheDeletionsAreNeverChecked(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyStack')->willThrowException(DeckRefusalException::stackNotEmpty(2));
		$gateway->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardNotOwned());
		$gateway->expects(self::never())->method('deletedStack');
		$gateway->expects(self::never())->method('ownedBoards');

		self::assertTrue((new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice')['isError']);
		self::assertTrue((new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice')['isError']);
	}

	/** A card arrived during the deletion and the delete was taken back: a plain refusal, nothing was deleted. */
	public function testADeletionTakenBackIsARefusal(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyStack')->willThrowException(DeckRefusalException::stackReceivedCards(true));
		$gateway->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardReceivedCards(true));

		$stack = (new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice');
		$board = (new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice');

		self::assertTrue($stack['isError']);
		self::assertSame(DeckMessages::errorStackReceivedCards(true), $this->text($stack));
		self::assertTrue($board['isError']);
		self::assertSame(DeckMessages::errorBoardReceivedCards(true), $this->text($board));
	}

	/** The undo failed and the list or board sits in the Deck trash with the new card: partial, never an error. */
	public function testADeletionThatCouldNotBeTakenBackIsPartialAndNotAnError(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyStack')->willThrowException(DeckRefusalException::stackReceivedCards(false));
		$gateway->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardReceivedCards(false));
		$gateway->expects(self::never())->method('deletedStack');
		$gateway->expects(self::never())->method('ownedBoards');

		$stack = (new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice');
		$board = (new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice');

		self::assertArrayNotHasKey('isError', $stack);
		self::assertSame(['partial' => true, 'message' => DeckMessages::errorStackReceivedCards(false)], $this->payload($stack));
		self::assertArrayNotHasKey('isError', $board);
		self::assertSame(['partial' => true, 'message' => DeckMessages::errorBoardReceivedCards(false)], $this->payload($board));
	}

	/** The gateway already read the list again and could not tell: the handler does not read it a second time. */
	public function testAnUnconfirmedRestoreOfTheGatewayIsNotReadBackAgain(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyStack')->willThrowException(new DeckUnconfirmedException('deck_list_stacks', $this->afterWrite()));
		$gateway->method('deleteEmptyBoard')->willThrowException(new DeckUnconfirmedException('deck_list_boards', $this->afterWrite()));
		$gateway->expects(self::never())->method('deletedStack');
		$gateway->expects(self::never())->method('ownedBoards');

		$this->assertUnconfirmed((new DeleteStackHandler($gateway, $this->logger))->handle(['stackId' => 10, 'confirm' => true], 'alice'), 'deck_list_stacks');
		$this->assertUnconfirmed((new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice'), 'deck_list_boards');
	}

	public function testABoardDeletionReadBackAsDeletedIsASuccessWithAWarning(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyBoard')->willThrowException($this->afterWrite());
		$gateway->expects(self::once())->method('ownedBoards')->with('alice')->willReturn([
			new Board(['id' => 3, 'title' => 'Outro']),
			new Board(['id' => 4, 'title' => 'Velho', 'deletedAt' => 1_700_000_500]),
		]);

		$payload = $this->assertWrittenWithWarning((new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice'));
		self::assertSame(['id' => 4, 'title' => 'Velho', 'deleted' => true], array_diff_key($payload, ['warnings' => 1]));
	}

	public function testABoardDeletionReadBackAliveIsTheUsualError(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyBoard')->willThrowException($this->afterWrite());
		$gateway->method('ownedBoards')->willReturn([new Board(['id' => 4, 'title' => 'Velho'])]);

		$this->assertPlainError((new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice'));
	}

	public function testABoardDeletionThatCannotBeCheckedIsUnconfirmed(): void {
		$gateway = $this->structureGateway();
		$gateway->method('deleteEmptyBoard')->willThrowException($this->afterWrite());
		$gateway->method('ownedBoards')->willThrowException(new \RuntimeException('db gone'));

		$this->assertUnconfirmed((new DeleteBoardHandler($gateway, $this->logger))->handle(['boardId' => 4, 'confirm' => true], 'alice'), 'deck_list_boards');
	}

	// --- an approximate read-back never authorizes the next write (third round) ---------------------------------

	/** @param array<string, mixed> $result */
	private function assertProbable(array $result): array {
		self::assertArrayNotHasKey('isError', $result);
		$payload = $this->payload($result);
		self::assertSame('probable', $payload['confirmed']);
		self::assertContains(DeckMessages::probablyCreated(), $payload['warnings']);
		self::assertNotContains(DeckMessages::writtenThenFailed(), $payload['warnings'], 'o que é só provável não é dado como gravado');

		return $payload;
	}

	/**
	 * Two identical recent cards: the creation fails before the insert and the read-back finds the earlier one by
	 * owner, title and time. It is reported as probable and gets no assignment.
	 */
	public function testACardFoundByHeuristicIsProbableAndNeverAssigned(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->method('createCard')->willThrowException(new NoPermissionException('Permission denied'));
		$gateway->method('findCreatedCard')->willReturn($this->card(['id' => 41, 'title' => 'Fechar']));
		$gateway->expects(self::never())->method('assignCardUser');

		$payload = $this->assertProbable($this->cards($gateway)['create']->handle(['stackId' => 10, 'title' => 'Fechar', 'assignees' => ['pedro']], 'alice'));
		self::assertSame(41, $payload['id']);
		self::assertContains(DeckMessages::probableCardNotAssigned(), $payload['warnings']);
	}

	public function testAListFoundByHeuristicIsProbable(): void {
		$gateway = $this->structureGateway();
		$gateway->method('createStack')->willThrowException($this->afterWrite());
		$gateway->method('stacksOf')->willReturnOnConsecutiveCalls([], [new Stack(['id' => 12, 'boardId' => 4, 'title' => 'Revisão', 'order' => 1])]);

		$payload = $this->assertProbable((new CreateStackHandler($gateway, $this->logger))->handle(['boardId' => 4, 'title' => 'Revisão', 'confirm' => true], 'alice'));
		self::assertSame(12, $payload['id']);
	}

	/** A board found by heuristic gets no list and no card: every dependent step is reported as not created, with the reason. */
	public function testABoardFoundByHeuristicBuildsNothingInsideIt(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('ownedBoards')->willReturnOnConsecutiveCalls([], [new Board(['id' => 3, 'title' => 'Projeto'])]);
		$gateway->method('createBoard')->willThrowException($this->afterWrite());
		$gateway->expects(self::never())->method('createStack');
		$gateway->expects(self::never())->method('createCard');

		$payload = $this->payload((new CreateBoardHandler($gateway, $this->logger))->handle(['title' => 'Projeto', 'stacks' => [
			['title' => 'A fazer', 'cards' => [['title' => 'Um'], ['title' => 'Dois']]],
			['title' => 'Feito'],
		], 'confirm' => true], 'alice'));

		self::assertSame(['id' => 3, 'title' => 'Projeto', 'confirmed' => 'probable'], $payload['created']['board']);
		self::assertSame([], $payload['created']['stacks']);
		self::assertSame([
			['kind' => 'list', 'title' => 'A fazer', 'reason' => DeckMessages::probableParentNotBuilt(), 'cardsNotCreated' => 2],
			['kind' => 'list', 'title' => 'Feito', 'reason' => DeckMessages::probableParentNotBuilt(), 'cardsNotCreated' => 0],
		], $payload['failed']);
		self::assertFalse($payload['complete']);
		self::assertContains(DeckMessages::probablyCreated(), $payload['warnings']);
	}

	/** A list found by heuristic gets no card; a card found by heuristic gets no assignment. */
	public function testAListAndACardFoundByHeuristicAreNotBuiltOn(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
		$gateway->method('createStack')->willReturnCallback(function (string $user, int $board, string $title): Stack {
			if ($title === 'Exata') {
				return new Stack(['id' => 21, 'title' => 'Exata']);
			}
			throw $this->afterWrite();
		});
		$gateway->method('stacksOf')->willReturn([new Stack(['id' => 20, 'title' => 'Provável'])]);
		$gateway->method('createCard')->willThrowException($this->afterWrite());
		$gateway->method('findCreatedCard')->willReturn($this->card(['id' => 50, 'title' => 'Um']));
		$gateway->expects(self::never())->method('assignCardUser');

		$payload = $this->payload((new CreateBoardHandler($gateway, $this->logger))->handle(['title' => 'Projeto', 'stacks' => [
			['title' => 'Provável', 'cards' => [['title' => 'Um']]],
			['title' => 'Exata', 'cards' => [['title' => 'Um', 'assignees' => ['alice']]]],
		], 'confirm' => true], 'alice'));

		self::assertSame([['id' => 20, 'title' => 'Provável', 'confirmed' => 'probable', 'cards' => []],
			['id' => 21, 'title' => 'Exata', 'cards' => [['id' => 50, 'title' => 'Um', 'confirmed' => 'probable']]]], $payload['created']['stacks']);
		self::assertSame([['kind' => 'card', 'list' => 'Provável', 'title' => 'Um', 'reason' => DeckMessages::probableParentNotBuilt()]], $payload['failed']);
		self::assertFalse($payload['complete']);
		self::assertContains(DeckMessages::probableCardNotAssigned(), $payload['warnings']);
	}

	// --- deck_create_board --------------------------------------------------------------------------------------

	/** The scenario of the review: the board is inserted, a default label fails; the board is found by title, so it is probable and nothing is built in it. */
	public function testABoardSavedBeforeItsLabelsFailedIsProbableAndNotBuiltOn(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::exactly(2))->method('ownedBoards')->with('alice')->willReturnOnConsecutiveCalls(
			[new Board(['id' => 1, 'title' => 'Projeto'])],
			[new Board(['id' => 1, 'title' => 'Projeto']), new Board(['id' => 3, 'title' => 'Projeto'])],
		);
		$gateway->method('createBoard')->willThrowException($this->afterWrite());
		$gateway->expects(self::never())->method('createStack');

		$payload = $this->payload((new CreateBoardHandler($gateway, $this->logger))->handle(
			['title' => 'Projeto', 'stacks' => [['title' => 'A fazer']], 'confirm' => true], 'alice'));
		self::assertSame(['id' => 3, 'title' => 'Projeto', 'confirmed' => 'probable'], $payload['created']['board']);
		self::assertSame([], $payload['created']['stacks']);
		self::assertSame('list', $payload['failed'][0]['kind']);
	}

	public function testABoardThatCannotBeCheckedIsUnconfirmed(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('ownedBoards')->willReturnOnConsecutiveCalls([], self::throwException(new \RuntimeException('db gone')));
		$gateway->method('createBoard')->willThrowException($this->afterWrite());
		$gateway->expects(self::never())->method('createStack');

		$this->assertUnconfirmed((new CreateBoardHandler($gateway, $this->logger))->handle(['title' => 'Projeto', 'confirm' => true], 'alice'), 'deck_list_boards');
	}

	public function testABoardThatIsNotThereIsTheUsualError(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('ownedBoards')->willReturn([new Board(['id' => 1, 'title' => 'Projeto'])]);
		$gateway->method('createBoard')->willThrowException($this->afterWrite());

		$this->assertPlainError((new CreateBoardHandler($gateway, $this->logger))->handle(['title' => 'Projeto', 'confirm' => true], 'alice'));
	}

	/** Inside the build, a card found after Deck failed is listed as probable; one that cannot be checked is a warning. */
	public function testCardsOfTheBuildAreCheckedToo(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
		$gateway->method('createStack')->willReturnCallback(function (string $user, int $board, string $title): Stack {
			return new Stack(['id' => $title === 'Feito' ? 21 : 20, 'title' => $title]);
		});
		$gateway->method('createCard')->willThrowException($this->afterWrite());
		$gateway->method('findCreatedCard')->willReturnCallback(function (string $user, int $stack, string $title, array $exclude): ?\OCA\Deck\Db\Card {
			return match ($title) {
				'Um' => $this->card(['id' => 50, 'title' => 'Um']),
				'Dois' => $exclude === [50] ? throw new \RuntimeException('db gone') : null,
				default => null,
			};
		});

		$payload = $this->payload((new CreateBoardHandler($gateway, $this->logger))->handle(['title' => 'Projeto', 'stacks' => [
			['title' => 'A fazer', 'cards' => [['title' => 'Um'], ['title' => 'Dois']]],
			['title' => 'Feito'],
		], 'confirm' => true], 'alice'));

		self::assertSame([20, 21], array_column($payload['created']['stacks'], 'id'));
		self::assertSame([50], array_column($payload['created']['stacks'][0]['cards'], 'id'));
		self::assertSame([['id' => 50, 'title' => 'Um', 'confirmed' => 'probable']], $payload['created']['stacks'][0]['cards']);
		self::assertSame([], $payload['failed'], 'nada que foi ou pode ter sido gravado vai para failed');
		self::assertFalse($payload['complete']);
		self::assertContains(DeckMessages::probablyCreated(), $payload['warnings']);
		self::assertContains(DeckMessages::boardItemUnconfirmed('card', 'Dois'), $payload['warnings']);
	}
}
