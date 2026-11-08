<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\ArchivedItemException;
use OCA\Deck\Exceptions\ConflictException;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Integration\DeckErrors;
use OCA\Mcp\Modules\Deck\Integration\DeckFailureLog;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages as DeckMessages;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\DeckModule as DeckToolModule;
use OCA\Mcp\Modules\Deck\Failures\DeckUnconfirmedException;
use OCA\Mcp\Modules\Deck\AbstractHandler;
use OCA\Mcp\Modules\Deck\Reading\ReadCardHandler;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Checks actual preview/handler logging, unchanged replies and separate post-write warning paths.
 */
final class DeckFailureLogTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * Enumerate known business refusals and failures that must remain visible to administrators.
	 *
	 * @return array<string, array{Throwable, bool}> Failure and whether it is an expected refusal.
	 */
	public static function failures(): array {
		return [
			'rule' => [new DeckRefusalException('safe rule'), true],
			'conflict' => [new DeckConflictException('safe conflict'), true],
			'acl' => [new NoPermissionException('private ACL'), true],
			'absent' => [new DoesNotExistException('private id'), true],
			'archived' => [new ArchivedItemException('private card'), true],
			'native conflict' => [new ConflictException('private state'), true],
			'status defaults to 500' => [new StatusException('private server failure'), false],
			'ambiguous database result' => [new MultipleObjectsReturnedException('private query'), false],
			'text cannot classify' => [new \RuntimeException('safe rule'), false],
		];
	}

	/**
	 * The real preview catch logs by type and still returns the existing sanitized failure.
	 *
	 * @param Throwable $failure Gateway failure before any write.
	 * @param bool $expected Whether its type is a known refusal.
	 * @return void
	 */
	#[DataProvider('failures')]
	public function testPreviewLogLevelAndReply(Throwable $failure, bool $expected): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method($expected ? 'debug' : 'warning')->with(
			'MCP Deck plan failed: {tool} ({exception})',
			['tool' => 'deck_move_card', 'exception' => $failure::class],
		);
		$logger->expects(self::never())->method($expected ? 'warning' : 'debug');
		$logger->expects(self::never())->method('error');
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())->method('findCard')->willThrowException($failure);
		$gateway->expects(self::never())->method('moveCard');
		$module = \OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestModule::build($this->createMock(ContainerInterface::class), $this->createMock(IAppManager::class),
			$logger, $this->createMock(IURLGenerator::class), $this->createMock(ITimeFactory::class));
		\OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestModule::setGateway($module, $gateway);
		try {
			$module->preview('deck_move_card', ['cardId' => 7, 'stackId' => 10], 'alice');
			self::fail('The preview must still refuse.');
		} catch (ToolFailure $reply) {
			self::assertSame(DeckErrors::messageFor($failure), $reply->getMessage());
		}
	}

	/**
	 * The real handler retains its error envelope and sanitized message, changing only severity.
	 *
	 * @param Throwable $failure Read failure without any write.
	 * @param bool $expected Whether the failure type is an expected refusal.
	 * @return void
	 */
	#[DataProvider('failures')]
	public function testHandlerLogLevelAndReply(Throwable $failure, bool $expected): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method($expected ? 'debug' : 'error')->with(
			$expected ? 'MCP Deck tool refused or conflicted: {tool} ({exception})' : 'MCP Deck tool failed: {tool} ({exception})',
			['tool' => 'deck_read_card', 'exception' => $failure::class],
		);
		$logger->expects(self::never())->method($expected ? 'error' : 'debug');
		$logger->expects(self::never())->method('warning');
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->willThrowException($failure);
		$result = (new ReadCardHandler($gateway, $logger, $this->cardFormatter()))->handle(['cardId' => 7], 'alice');
		self::assertTrue($result['isError']);
		self::assertSame(DeckErrors::messageFor($failure), $this->text($result));
	}

	/**
	 * A marked partial refusal must not be reclassified as a quiet preview refusal.
	 *
	 * @return void
	 */
	public function testPartialRefusalRemainsAWarningInPreview(): void {
		$failure = DeckRefusalException::stackReceivedCards(false);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		$logger->expects(self::never())->method('debug');
		DeckFailureLog::plan($logger, 'deck_delete_stack', $failure);
	}

	/**
	 * Exercise unchanged partial, uncertain and saved-write paths around the new logging delegate.
	 *
	 * @param string $phase Which established post-write path to exercise.
	 * @return void
	 */
	#[DataProvider('writePhases')]
	public function testPostWriteResultsStillWarn(string $phase): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		$logger->expects(self::never())->method('debug');
		$logger->expects(self::never())->method('error');
		$handler = new class($this->createMock(DeckGatewayInterface::class), $logger, $phase) extends AbstractHandler {
			/**
			 * Bind a controlled failure phase to the normal handler boundary.
			 *
			 * @param DeckGatewayInterface $gateway Unused gateway double.
			 * @param LoggerInterface $logger Logger whose severity is asserted.
			 * @param string $phase Established post-write phase.
			 */
			public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger, private string $phase) {
				parent::__construct($gateway, $logger);
			}

			/**
			 * Execute the selected phase through the production wrapper.
			 *
			 * @param array<string, mixed> $arguments Unused tool arguments.
			 * @param string $userId Unused authenticated UID.
			 * @return array<string, mixed> Existing handler result envelope.
			 */
			public function handle(array $arguments, string $userId): array {
				return $this->run(function (): array {
					if ($this->phase === 'partial') {
						throw DeckRefusalException::stackReceivedCards(false);
					}
					if ($this->phase === 'uncertain') {
						throw new DeckUnconfirmedException('deck_read_card', new NoPermissionException('private'));
					}
					$warnings = [];
					$saved = $this->afterWrite(static fn () => throw new NoPermissionException('private'),
						static fn (): array => ['id' => 7], $warnings);
					return $saved + ['warnings' => $warnings];
				});
			}

			/**
			 * Name the controlled tool for diagnostics.
			 *
			 * @return string Existing technical tool name.
			 */
			protected function toolName(): string {
				return 'deck_delete_stack';
			}
		};
		$result = $handler->handle([], 'alice');
		self::assertArrayNotHasKey('isError', $result);
		$payload = $this->payload($result);
		if ($phase === 'partial') {
			self::assertSame(['partial' => true, 'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorStackReceivedCards(false)], $payload);
		} elseif ($phase === 'uncertain') {
			self::assertSame(['confirmed' => false, 'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckWriteMessages::writeUnconfirmed('deck_read_card')], $payload);
		} else {
			self::assertSame(['id' => 7, 'warnings' => [\OCA\Mcp\Modules\Deck\Presentation\DeckWriteMessages::writtenThenFailed()]], $payload);
		}
	}

	/**
	 * List the established warning phases, independently of exception wording.
	 *
	 * @return array<string, array{string}> Phases that must keep warning severity.
	 */
	public static function writePhases(): array {
		return ['partial' => ['partial'], 'uncertain' => ['uncertain'], 'saved' => ['saved']];
	}
}
