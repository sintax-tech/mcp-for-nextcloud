<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Creation\CreateCardHandler;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Labels\CardLabelPlan;
use OCA\Mcp\Modules\Deck\Labels\LabelInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/** deck_create_card with labels, like a GitHub issue: titles or IDs, missing titles created on the board. */
final class CardCreationLabelsTest extends TestCase {
	use DeckTestHelpers;

	private const CATALOG = [['id' => 1, 'boardId' => 20, 'title' => 'bug', 'color' => 'ff7a66'], ['id' => 4, 'boardId' => 20, 'title' => 'Docs', 'color' => '317ccc']];

	/** @return iterable<string,array{array}> Invalid label lists. */
	public static function invalid(): iterable {
		yield 'not a list' => [['a' => 'bug']];
		yield 'zero id' => [[0]];
		yield 'boolean' => [[true]];
		yield 'blank title' => [['  ']];
		yield 'too many' => [array_map(static fn (int $i): string => 'l' . $i, range(1, 21))];
	}

	/** @param array $labels Invalid argument. @return void Rejects invalid label lists. */
	#[DataProvider('invalid')]
	public function testInvalidLabelsAreRefused(array $labels): void {
		$this->expectException(ArgumentValidationException::class);
		CardLabelPlan::requested($labels);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testTitlesMatchExactlyAndMissingOnesAreCreated(): void {
		$plan = CardLabelPlan::resolve(CardLabelPlan::requested(['bug', 4, 'Docs', 'sev:medium', 'sev:medium']), self::CATALOG);
		self::assertSame([1, 4], array_column($plan['assign'], 'id'));
		self::assertSame([['title' => 'sev:medium', 'color' => LabelInput::defaultColor('sev:medium')]], $plan['create']);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testUnknownIdAndAmbiguousTitleAreRefused(): void {
		try {
			CardLabelPlan::resolve([9], self::CATALOG);
			self::fail('unknown id accepted');
		} catch (DeckRefusalException $e) {
			self::assertStringContainsString('no label with ID 9', $e->getMessage());
		}
		$this->expectException(DeckRefusalException::class);
		CardLabelPlan::resolve(['DOCS'], [...self::CATALOG, ['id' => 5, 'boardId' => 20, 'title' => 'docs', 'color' => '000000']]);
	}

	/** Label catalog behind the gateway of the last {@see self::gateway()}. */
	private \OCA\Mcp\Modules\Deck\Integration\DeckLabelCatalog&\PHPUnit\Framework\MockObject\MockObject $labels;

	/** @return \OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface&\PHPUnit\Framework\MockObject\MockObject */
	private function gateway(bool $manage = true): object {
		$gateway = $this->gatewayOwnedBy('alice');
		$this->labels = $this->createMock(\OCA\Mcp\Modules\Deck\Integration\DeckLabelCatalog::class);
		$gateway->method('boardLabels')->willReturn($this->labels);
		$this->labels->method('stackLabels')->willReturn(['boardId' => 20, 'labels' => self::CATALOG, 'manage' => $manage]);
		return $gateway;
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testCreationOfMissingLabelsNeedsTheGrantAndManageBeforeTheCardExists(): void {
		foreach ([[false, true, 'deck.labels'], [true, false, 'manage the board']] as [$granted, $manage, $reason]) {
			$gateway = $this->gateway($manage);
			$gateway->expects(self::never())->method('createCard');
			$this->labels->expects(self::never())->method('createLabel');
			$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter(), static fn (): bool => $granted);
			$result = $handler->handle(['stackId' => 10, 'title' => 'Crash', 'labels' => ['bug', 'sev:high']], 'alice');
			self::assertTrue($result['isError']);
			self::assertStringContainsString($reason, $this->text($result));
		}
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testExistingLabelsNeedGrantAndNativeEditToBeAssigned(): void {
		$gateway = $this->gateway(false);
		$gateway->method('createCard')->willReturn($this->card(['id' => 9]));
		$this->labels->expects(self::never())->method('createLabel');
		$this->labels->expects(self::once())->method('assignCardLabel')->with('alice', 9, 1);
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter(), static fn (): bool => true);
		$payload = $this->payload($handler->handle(['stackId' => 10, 'title' => 'Crash', 'labels' => ['bug']], 'alice'));
		self::assertSame([['id' => 1, 'title' => 'bug', 'color' => 'ff7a66']], $payload['labels']);
		self::assertArrayNotHasKey('labelsCreated', $payload);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testMissingLabelIsCreatedThenEveryLabelIsAssigned(): void {
		$gateway = $this->gateway();
		$gateway->method('createCard')->willReturn($this->card(['id' => 9]));
		$this->labels->expects(self::once())->method('createLabel')->with('alice', 20, 'sev:medium', LabelInput::defaultColor('sev:medium'))
			->willReturn(['id' => 7, 'boardId' => 20, 'title' => 'sev:medium', 'color' => 'abcdef']);
		$assigned = [];
		$this->labels->method('assignCardLabel')->willReturnCallback(static function (string $uid, int $card, int $label) use (&$assigned): void { $assigned[] = $label; });
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter(), static fn (): bool => true);
		$payload = $this->payload($handler->handle(['stackId' => 10, 'title' => 'Crash', 'labels' => ['bug', 'sev:medium']], 'alice'));
		self::assertSame([1, 7], $assigned);
		self::assertSame([1, 7], array_column($payload['labels'], 'id'));
		self::assertSame(7, $payload['labelsCreated'][0]['id']);
		self::assertArrayNotHasKey('warnings', $payload);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testALabelFailureAfterCreationKeepsTheCardAndWarns(): void {
		$gateway = $this->gateway();
		$gateway->method('createCard')->willReturn($this->card(['id' => 9]));
		$this->labels->method('createLabel')->willThrowException(new \RuntimeException('secret'));
		$this->labels->method('assignCardLabel')->willThrowException(new \RuntimeException('secret'));
		$gateway->method('cardLabelState')->willReturn(['labels' => []]);
		$gateway->expects(self::never())->method('deleteCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter(), static fn (): bool => true);
		$result = $handler->handle(['stackId' => 10, 'title' => 'Crash', 'labels' => ['bug', 'sev:medium']], 'alice');
		self::assertArrayNotHasKey('isError', $result);
		$payload = $this->payload($result);
		self::assertSame(9, $payload['id']);
		self::assertSame([], $payload['labels']);
		self::assertStringContainsString('labels sev:medium, bug were not completed', $payload['warnings'][0]);
		self::assertStringNotContainsString('secret', json_encode($result));
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testAssignmentThatThrowsButIsObservedCounts(): void {
		$gateway = $this->gateway();
		$gateway->method('createCard')->willReturn($this->card(['id' => 9]));
		$this->labels->method('assignCardLabel')->willThrowException(new \RuntimeException('activity failed'));
		$gateway->method('cardLabelState')->willReturn(['labels' => [['id' => 1]]]);
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter(), static fn (): bool => true);
		$payload = $this->payload($handler->handle(['stackId' => 10, 'title' => 'Crash', 'labels' => [1]], 'alice'));
		self::assertSame([1], array_column($payload['labels'], 'id'));
		self::assertCount(1, $payload['warnings']);
	}
	/** @return void A failed second label keeps the first label and card, stops all later writes and reports uncertainty. */
	public function testPartialCreationStopsWithoutRollbackOrRetry(): void {
		$gateway = $this->gateway();
		$gateway->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
		$calls = [];
		$this->labels->expects(self::exactly(2))->method('createLabel')->willReturnCallback(static function ($uid, $board, $title, $color) use (&$calls): array {
			$calls[] = $title;
			if ($title === 'second') { throw new \RuntimeException('private failure'); }
			return ['id' => 7, 'boardId' => 20, 'title' => $title, 'color' => $color];
		});
		$this->labels->expects(self::never())->method('assignCardLabel');
		$this->labels->expects(self::never())->method('deleteLabel');
		$gateway->expects(self::never())->method('deleteCard');
		$handler = new CreateCardHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter(), static fn (): bool => true);
		$payload = $this->payload($handler->handle(['stackId' => 10, 'title' => 'Card', 'labels' => ['first', 'second', 'third']], 'alice'));
		self::assertSame(['first', 'second'], $calls);
		self::assertSame([7], array_column($payload['labelsCreated'], 'id'));
		self::assertSame([], $payload['labels']);
		self::assertStringContainsString('Some writes may have succeeded', $payload['warnings'][0]);
		self::assertStringNotContainsString('private failure', json_encode($payload));
	}

	/** @return void Case-only new titles are refused before sequential native creation can partially duplicate them. */
	public function testCaseOnlyDuplicatesRequireExplicitIdentity(): void {
		$this->expectException(DeckRefusalException::class);
		CardLabelPlan::requested(['Bug', 'bug']);
	}

}
