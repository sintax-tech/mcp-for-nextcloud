<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\CreateCardHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_create_card`.
 */
final class CreateCardHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param \Throwable|null $failure Exception the gateway raises, or null for a happy path.
	 * @return CreateCardHandler Handler under test.
	 */
	private function handler(?\Throwable $failure = null): CreateCardHandler {
		$gateway = $this->gatewayOwnedBy('alice');
		$expectation = $gateway->method('createCard');
		if ($failure !== null) {
			$expectation->willThrowException($failure);
		} else {
			$expectation->willReturn($this->card(['id' => 9, 'title' => 'Fechar contrato']));
		}

		return new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
	}

	/** A rejected assignee stops the creation before any write. */
	public function testInvalidAssigneeCreatesNothing(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())->method('validateAssignees')->with('alice', 10, ['missing'])->willThrowException(new InvalidArgumentException('Invalid argument: assignees'));
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
		$this->expectException(InvalidArgumentException::class);
		$handler->handle(['stackId' => 10, 'title' => 'New', 'assignees' => ['missing']], 'alice');
	}

	/** A race after creation returns the created card with a safe warning instead of an error or deletion. */
	public function testAssignmentRaceKeepsCreatedCard(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
		$gateway->expects(self::once())->method('assignCardUser')->with('alice', 9, 'pedro')->willThrowException(new RuntimeException('secret'));
		$gateway->expects(self::never())->method('deleteCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
		$result = $handler->handle(['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro']], 'alice');
		$payload = $this->payload($result);
		self::assertSame(9, $payload['id']);
		self::assertSame(
			['The card was created, but one or more assignees could not be assigned. Read the card before retrying assignment in Deck.'],
			$payload['warnings'],
		);
		self::assertArrayNotHasKey('isError', $result);
		self::assertStringNotContainsString('secret', json_encode($result));
	}

	/** Successful assignments are included in the created card returned to the caller. */
	public function testCreatedCardIncludesSuccessfulAssignments(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
		$gateway->expects(self::once())->method('assignCardUser')->with('alice', 9, 'pedro')
			->willReturn(new \OCA\Deck\Db\Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => 0]));
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());
		$payload = $this->payload($handler->handle(['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro']], 'alice'));
		self::assertSame('pedro', $payload['assignedUsers'][0]['uid']);
		self::assertArrayNotHasKey('warnings', $payload);
	}

	/** Only the accounts the validator returned are assigned, whatever the client asked for. */
	public function testOnlyTheValidatedAssigneesAreAssigned(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro', 'intruso'])
			->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$gateway->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
		$gateway->expects(self::once())->method('assignCardUser')->with('alice', 9, 'pedro')
			->willReturn(new \OCA\Deck\Db\Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => 0]));
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle(['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro', 'intruso']], 'alice'));

		self::assertSame(['pedro'], array_column($payload['assignedUsers'], 'uid'));
	}

	public function testHappyPathSendsTheCardOwnedByTheCaller(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())
			->method('createCard')
			->with('alice', 10, 'Fechar contrato', 'Com o cliente', '2026-03-01')
			->willReturn($this->card(['id' => 9]));
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle([
			'stackId' => 10,
			'title' => '  Fechar contrato  ',
			'description' => 'Com o cliente',
			'duedate' => '2026-03-01',
		], 'alice'));

		self::assertSame(9, $payload['id']);
	}

	public function testOptionalFieldsFallBackToEmptyAndNoDate(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::once())
			->method('createCard')
			->with('alice', 10, 'Fechar', '', null)
			->willReturn($this->card(['id' => 9]));
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$handler->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');
	}

	public function testBlankTitleIsAParameterErrorAndNeverReachesDeck(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorTitleRequired());

		$handler->handle(['stackId' => 10, 'title' => '   '], 'alice');
	}

	public function testImpossibleDateIsAParameterError(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorInvalidDuedate());

		$handler->handle(['stackId' => 10, 'title' => 'Fechar', 'duedate' => '2026-02-31'], 'alice');
	}

	public function testOversizedDescriptionIsRefusedInsteadOfTruncated(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorDescriptionTooLong());

		$handler->handle([
			'stackId' => 10,
			'title' => 'Fechar',
			'description' => str_repeat('a', CardInput::MAX_DESCRIPTION_LENGTH + 1),
		], 'alice');
	}

	public function testDeniedAccessBecomesTheGenericMessage(): void {
		$result = $this->handler(new NoPermissionException('Permission denied'))
			->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}

	public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
		$result = $this->handler(new StatusException('Operation not allowed. This board is archived.'))
			->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');

		self::assertSame(DeckMessages::errorNotAllowed(), $this->text($result));
	}

	public function testBackendFailureBecomesTheGenericMessage(): void {
		$result = $this->handler(new RuntimeException('foreign key violation'))
			->handle(['stackId' => 10, 'title' => 'Fechar'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
		self::assertStringNotContainsString('foreign key', $this->text($result));
	}

	public function testSharedBoardWithoutConfirmationCreatesNothing(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('stackOwnership')
			->with('alice', 10)
			->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->assertSharedConfirmation($handler->handle([
			'stackId' => 10,
			'title' => 'Fechar contrato',
		], 'alice'));
	}

	public function testSharedBoardWithConfirmationCreatesTheCard(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('stackOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
		$gateway->expects(self::once())
			->method('createCard')
			->with('alice', 10, 'Fechar contrato', '', null)
			->willReturn($this->card(['id' => 9]));
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle([
			'stackId' => 10,
			'title' => 'Fechar contrato',
			'confirm_shared' => true,
		], 'alice'));

		self::assertSame(9, $payload['id']);
	}

	public function testOwnershipDeniedIsRefusedBeforeAnythingIsCreated(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('stackOwnership')->willThrowException(new NoPermissionException('Permission denied'));
		$gateway->expects(self::never())->method('createCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$result = $handler->handle(['stackId' => 10, 'title' => 'Fechar contrato'], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorNotFoundOrForbidden(), $this->text($result));
	}
}
