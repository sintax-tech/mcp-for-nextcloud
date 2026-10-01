<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Assignment;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\EditCardHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_edit_card`, including the optimistic lock and the preservation of untouched fields.
 */
final class EditCardHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param array<string, mixed> $current Current card values.
	 * @param \Throwable|null $failure Exception `updateCard` raises, or null for a happy path.
	 * @return EditCardHandler Handler under test.
	 */
	private function handler(array $current = [], ?\Throwable $failure = null): EditCardHandler {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->card($current + [
			'title' => 'Título atual',
			'description' => 'Descrição atual',
		]));
		$expectation = $gateway->method('updateCard');
		if ($failure !== null) {
			$expectation->willThrowException($failure);
		} else {
			$expectation->willReturn($this->card($current + ['id' => 7]));
		}

		return new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
	}

	public function testHappyPathSendsTheMergedForm(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$current = $this->card([
			'id' => 7,
			'title' => 'Título atual',
			'description' => 'Descrição atual',
			'duedate' => new \DateTime('2026-01-05 00:00:00', new \DateTimeZone('UTC')),
			'type' => 'note',
			'owner' => 'alice',
			'order' => 3,
		]);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Novo título', 'Descrição atual', '2026-01-05T00:00:00+00:00')
			->willReturn($current);
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7, 'title' => 'Novo título'], 'alice'));

		self::assertSame(7, $payload['id']);
	}

	public function testUntouchedFieldsAreNotResentAsEdits(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$current = $this->card(['id' => 7, 'description' => 'Só isso']);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Card', 'Só isso', null)
			->willReturn($current);
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$handler->handle(['cardId' => 7, 'description' => 'Só isso'], 'alice');
	}

	public function testExplicitNullClearsTheDateAndOmissionKeepsIt(): void {
		$current = $this->card(['id' => 7, 'duedate' => new \DateTime('2026-01-05 00:00:00', new \DateTimeZone('UTC'))]);

		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Card', '', null)
			->willReturn($current);
		(new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter()))
			->handle(['cardId' => 7, 'duedate' => null], 'alice');

		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Card', '', '2026-01-05T00:00:00+00:00')
			->willReturn($current);
		(new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter()))
			->handle(['cardId' => 7, 'title' => 'Card', 'description' => ''], 'alice');
	}

	public function testStaleLastModifiedIsAConflictAndNothingIsWritten(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->card(['id' => 7, 'lastModified' => 1_700_000_000]));
		$gateway->expects(self::never())->method('updateCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle([
			'cardId' => 7,
			'title' => 'Novo título',
			'lastModified' => 1_699_999_999,
		], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorConflict(), $this->text($result));
	}

	public function testMatchingLastModifiedProceeds(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$current = $this->card(['id' => 7, 'lastModified' => 1_700_000_000]);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())->method('updateCard')->willReturn($current);
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle([
			'cardId' => 7,
			'title' => 'Novo título',
			'lastModified' => 1_700_000_000,
		], 'alice');

		self::assertArrayNotHasKey('isError', $result);
	}

	public function testCallWithoutAnyEditableFieldIsAParameterError(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('findCard');
		$gateway->expects(self::never())->method('updateCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorNoFieldToEdit());

		$handler->handle(['cardId' => 7], 'alice');
	}

	public function testLastModifiedAloneIsNotAnEdit(): void {
		$handler = $this->handler();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorNoFieldToEdit());

		$handler->handle(['cardId' => 7, 'lastModified' => 1_700_000_000], 'alice');
	}

	public function testInvalidDateIsAParameterError(): void {
		$handler = $this->handler();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorInvalidDuedate());

		$handler->handle(['cardId' => 7, 'duedate' => '31/12/2026'], 'alice');
	}

	public function testDeniedAccessBecomesTheGenericMessage(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willThrowException(new NoPermissionException('Permission denied'));
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}

	public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
		$result = $this->handler([], new StatusException('Operation not allowed. This board is archived.'))
			->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');

		self::assertSame(DeckMessages::errorNotAllowed(), $this->text($result));
	}

	public function testDeckRejectionBecomesTheInvalidMessage(): void {
		$result = $this->handler([], new BadRequestException('title'))
			->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');

		self::assertSame(DeckMessages::errorInvalid(), $this->text($result));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$result = $this->handler([], new RuntimeException('lock wait timeout'))
			->handle(['cardId' => 7, 'title' => 'Novo'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
	}

	public function testSharedBoardWithoutConfirmationUpdatesNothing(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('cardOwnership')
			->with('alice', 7)
			->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::never())->method('findCard');
		$gateway->expects(self::never())->method('updateCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->assertSharedConfirmation($handler->handle([
			'cardId' => 7,
			'title' => 'Novo título',
		], 'alice'));
	}

	public function testSharedBoardWithConfirmationUpdatesTheCard(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$current = $this->card(['id' => 7, 'title' => 'Título atual', 'description' => 'Descrição atual']);
		$gateway->method('findCard')->willReturn($current);
		$gateway->expects(self::once())
			->method('updateCard')
			->with('alice', $current, 'Novo título', 'Descrição atual', null)
			->willReturn($current);
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle([
			'cardId' => 7,
			'title' => 'Novo título',
			'confirm_shared' => true,
		], 'alice'));

		self::assertSame(7, $payload['id']);
	}

	public function testOwnershipDeniedIsRefusedBeforeTheCardIsRead(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('cardOwnership')->willThrowException(new NoPermissionException('Permission denied'));
		$gateway->expects(self::never())->method('findCard');
		$gateway->expects(self::never())->method('updateCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle(['cardId' => 7, 'title' => 'Novo título'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}

	/**
	 * @param list<string> $uids Accounts assigned to the card today.
	 * @return \OCA\Deck\Db\Card Card as `findCard` reads it, with its assignments embedded.
	 */
	private function cardAssignedTo(array $uids): \OCA\Deck\Db\Card {
		return $this->card([
			'id' => 7,
			'stackId' => 10,
			'assignedUsers' => array_map(
				static fn (string $uid): Assignment => new Assignment(['cardId' => 7, 'participant' => $uid, 'type' => 0]),
				$uids,
			),
		]);
	}

	private function assignment(string $uid): Assignment {
		return new Assignment(['cardId' => 7, 'participant' => $uid, 'type' => 0]);
	}

	/** @return list<string> uids of the assigned users the payload reports */
	private function assignedUids(array $payload): array {
		return array_column($payload['assignedUsers'], 'uid');
	}

	public function testAssignOnlyEditsTheAssigneesAndTouchesNoOtherField(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->cardAssignedTo(['ana']));
		$gateway->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])
			->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->expects(self::never())->method('updateCard');
		$gateway->expects(self::once())->method('assignCardUser')->with('alice', 7, 'pedro')
			->willReturn($this->assignment('pedro'));
		$gateway->expects(self::never())->method('unassignCardUser');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7, 'assign' => ['pedro']], 'alice'));

		self::assertSame(['ana', 'pedro'], $this->assignedUids($payload));
		self::assertSame(['pedro'], $payload['assigned']);
		self::assertSame([], $payload['unassigned']);
		self::assertSame([], $payload['failed']);
	}

	public function testUnassignRemovesAnAssignedAccount(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->cardAssignedTo(['ana', 'pedro']));
		$gateway->expects(self::never())->method('validateAssignees');
		$gateway->expects(self::never())->method('assignCardUser');
		$gateway->expects(self::never())->method('updateCard');
		$gateway->expects(self::once())->method('unassignCardUser')->with('alice', 7, 'pedro')
			->willReturn($this->assignment('pedro'));
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7, 'unassign' => ['pedro']], 'alice'));

		self::assertSame(['ana'], $this->assignedUids($payload));
		self::assertSame(['pedro'], $payload['unassigned']);
	}

	public function testAccountWithoutBoardAccessIsRefusedBeforeAnythingIsWritten(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->cardAssignedTo(['pedro']));
		$gateway->method('validateAssignees')->willThrowException(
			new ArgumentValidationException('Invalid argument: assignees', 'assignees', 'each account must exist and have access to the board'),
		);
		$gateway->expects(self::never())->method('updateCard');
		$gateway->expects(self::never())->method('assignCardUser');
		$gateway->expects(self::never())->method('unassignCardUser');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->expectException(ArgumentValidationException::class);

		// The title would be written and `pedro` removed if the refusal came after the writes.
		$handler->handle(['cardId' => 7, 'title' => 'Novo', 'assign' => ['intruso'], 'unassign' => ['pedro']], 'alice');
	}

	public function testAnAccountInBothListsIsRefusedBeforeDeckIsTouched(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('findCard');
		$gateway->expects(self::never())->method('updateCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		try {
			$handler->handle(['cardId' => 7, 'assign' => ['pedro'], 'unassign' => ['pedro']], 'alice');
			self::fail('accepted an account in both lists');
		} catch (ArgumentValidationException $e) {
			self::assertSame('assign', $e->details()['field']);
		}
	}

	public function testUnassigningSomebodyNotAssignedIsIgnoredWithAWarning(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->cardAssignedTo(['ana']));
		$gateway->expects(self::never())->method('unassignCardUser');
		$gateway->expects(self::never())->method('updateCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7, 'unassign' => ['pedro']], 'alice'));

		self::assertSame([], $payload['unassigned']);
		self::assertSame(['ana'], $this->assignedUids($payload));
		self::assertCount(1, $payload['warnings']);
		self::assertStringContainsString('pedro', $payload['warnings'][0]);
	}

	public function testAssigningSomebodyAlreadyAssignedIsIgnoredWithAWarning(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->cardAssignedTo(['ana']));
		$gateway->expects(self::never())->method('assignCardUser');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['cardId' => 7, 'assign' => ['ana']], 'alice'));

		self::assertSame([], $payload['assigned']);
		self::assertCount(1, $payload['warnings']);
		self::assertStringContainsString('ana', $payload['warnings'][0]);
	}

	public function testAFailureInTheMiddleIsAPartialSuccessThatIsNotRolledBack(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->cardAssignedTo(['ana', 'luis']));
		$gateway->method('validateAssignees')->willReturn([
			['uid' => 'pedro', 'displayName' => 'Pedro'],
			['uid' => 'rosa', 'displayName' => 'Rosa'],
		]);
		$gateway->method('assignCardUser')->willReturnCallback(function (string $user, int $card, string $uid): Assignment {
			if ($uid === 'pedro') {
				throw new RuntimeException('SQLSTATE[HY000] secret detail');
			}

			return $this->assignment($uid);
		});
		$gateway->expects(self::once())->method('unassignCardUser')->with('alice', 7, 'luis')
			->willReturn($this->assignment('luis'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::atLeastOnce())->method('warning')->with(
			self::anything(),
			self::callback(static fn (array $context): bool => ($context['exception'] ?? null) === RuntimeException::class
				&& !str_contains(json_encode($context), 'secret detail')),
		);
		$handler = new EditCardHandler($gateway, $logger, $this->cardFormatter());

		$result = $handler->handle(['cardId' => 7, 'assign' => ['pedro', 'rosa'], 'unassign' => ['luis']], 'alice');

		self::assertArrayNotHasKey('isError', $result);
		$payload = $this->payload($result);
		self::assertSame(['rosa'], $payload['assigned']);
		self::assertSame(['luis'], $payload['unassigned']);
		self::assertSame([['uid' => 'pedro', 'reason' => DeckMessages::errorGeneric()]], $payload['failed']);
		self::assertSame(['ana', 'rosa'], $this->assignedUids($payload));
		self::assertStringNotContainsString('secret detail', $this->text($result));
	}

	public function testFieldsAndAssigneesCanBeEditedTogetherAndFieldsGoFirst(): void {
		$calls = [];
		$gateway = $this->gatewayOwnedBy('alice');
		$current = $this->cardAssignedTo(['ana']);
		$gateway->method('findCard')->willReturn($current);
		$gateway->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->method('updateCard')->willReturnCallback(function () use (&$calls, $current) {
			$calls[] = 'update';

			return $current;
		});
		$gateway->method('assignCardUser')->willReturnCallback(function () use (&$calls) {
			$calls[] = 'assign';

			return $this->assignment('pedro');
		});
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$handler->handle(['cardId' => 7, 'title' => 'Novo', 'assign' => ['pedro']], 'alice');

		self::assertSame(['update', 'assign'], $calls);
	}

	public function testEmptyListsAloneAreNotAnEdit(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('findCard');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorNoFieldToEdit());

		$handler->handle(['cardId' => 7, 'assign' => [], 'unassign' => []], 'alice');
	}

	public function testStaleLastModifiedAlsoBlocksAnAssigneeOnlyEdit(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('findCard')->willReturn($this->cardAssignedTo([]));
		$gateway->expects(self::never())->method('assignCardUser');
		$handler = new EditCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle(['cardId' => 7, 'assign' => ['pedro'], 'lastModified' => 1], 'alice');

		self::assertSame(DeckMessages::errorConflict(), $this->text($result));
	}

	public function testPlainFieldEditKeepsThePayloadFreeOfAssigneeKeys(): void {
		$payload = $this->payload($this->handler()->handle(['cardId' => 7, 'title' => 'Novo'], 'alice'));

		self::assertArrayNotHasKey('assigned', $payload);
		self::assertArrayNotHasKey('failed', $payload);
	}
}
