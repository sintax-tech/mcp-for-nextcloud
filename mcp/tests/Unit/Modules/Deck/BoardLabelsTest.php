<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckLabelCatalog;
use OCA\Mcp\Modules\Deck\Labels\CreateLabelHandler;
use OCA\Mcp\Modules\Deck\Labels\DeleteLabelHandler;
use OCA\Mcp\Modules\Deck\Labels\LabelInput;
use OCA\Mcp\Modules\Deck\Labels\LabelSnapshot;
use OCA\Mcp\Modules\Deck\Labels\UpdateLabelHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/** deck_create_label, deck_update_label and deck_delete_label: plans, refusals, writes and read-backs. */
final class BoardLabelsTest extends TestCase {
	use DeckTestHelpers;

	/** @return array<string, mixed> catalog of board 20 with bug (2 cards) and Feature */
	private function board(string $owner = 'alice'): array {
		return ['boardId' => 20, 'board' => $this->ownershipOf($owner, 'Comercial'),
			'labels' => [['id' => 1, 'boardId' => 20, 'title' => 'bug', 'color' => 'ff7a66'], ['id' => 2, 'boardId' => 20, 'title' => 'Feature', 'color' => '317ccc']],
			'usage' => [1 => 2]];
	}

	/** @return array<string, mixed> state of label 1 */
	private function label(string $owner = 'alice'): array {
		$board = $this->board($owner);
		return $board + ['label' => $board['labels'][0], 'cards' => 2];
	}

	/** @return \Closure(): PlanState fingerprint service with a fixed secret */
	private function states(): \Closure {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getSystemValueString')->willReturn('secret');
		return static fn (): PlanState => new PlanState($config);
	}

	/** Gateway of the handler under test; its boardLabels() is the catalog of the last {@see self::catalog()}. */
	private DeckGatewayInterface&MockObject $deck;

	/** @return DeckLabelCatalog&MockObject catalog whose label reads answer the given states, behind {@see self::$deck} */
	private function catalog(array &$label, array &$board): DeckLabelCatalog&MockObject {
		$labels = $this->createMock(DeckLabelCatalog::class);
		$labels->method('labelState')->willReturnCallback(static function () use (&$label): array { return $label; });
		$labels->method('boardLabelState')->willReturnCallback(static function () use (&$board): array { return $board; });
		$this->deck = $this->createMock(DeckGatewayInterface::class);
		$this->deck->method('boardLabels')->willReturn($labels);
		return $labels;
	}

	/** @return DeckGatewayInterface&MockObject gateway over a fresh catalog of the given states */
	private function deck(array &$label, array &$board): DeckGatewayInterface&MockObject {
		$this->catalog($label, $board);
		return $this->deck;
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testCreateRefusesATitleTheBoardHasInAnotherCase(): void {
		$board = $this->board(); $label = [];
		$labels = $this->catalog($label, $board);
		$labels->expects(self::never())->method('createLabel');
		$result = (new CreateLabelHandler($this->deck, $this->createMock(LoggerInterface::class)))->handle(['boardId' => 20, 'title' => ' BUG '], 'alice');
		self::assertTrue($result['isError']);
		self::assertStringContainsString('already has a label named BUG', $this->text($result));
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testCreateWritesTheTrimmedTitleAndTheDefaultColour(): void {
		$board = $this->board(); $label = [];
		$labels = $this->catalog($label, $board);
		$labels->expects(self::once())->method('createLabel')->with('alice', 20, 'sev:medium', LabelInput::defaultColor('sev:medium'))
			->willReturn(['id' => 3, 'boardId' => 20, 'title' => 'sev:medium', 'color' => LabelInput::defaultColor('sev:medium')]);
		$payload = $this->payload((new CreateLabelHandler($this->deck, $this->createMock(LoggerInterface::class)))
			->handle(['boardId' => 20, 'title' => ' sev:medium '], 'alice'));
		self::assertSame(3, $payload['label']['id']);
		self::assertSame(LabelInput::defaultColor('SEV:MEDIUM'), $payload['label']['color'], 'the colour ignores case');
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testCreateThatThrowsAfterTheInsertIsAnsweredAsSaved(): void {
		$board = $this->board(); $label = [];
		$labels = $this->catalog($label, $board);
		$labels->method('createLabel')->willReturnCallback(function () use (&$board): never {
			$board['labels'][] = ['id' => 3, 'boardId' => 20, 'title' => 'sev:high', 'color' => 'aa0000'];
			throw new \RuntimeException('activity failed');
		});
		$payload = $this->payload((new CreateLabelHandler($this->deck, $this->createMock(LoggerInterface::class)))
			->handle(['boardId' => 20, 'title' => 'sev:high', 'color' => '#AA0000'], 'alice'));
		self::assertSame(3, $payload['label']['id']);
		self::assertCount(1, $payload['warnings']);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testCreateOnSomebodyElsesBoardNeedsConfirmShared(): void {
		$board = $this->board('pedro'); $label = [];
		$labels = $this->catalog($label, $board);
		$labels->expects(self::never())->method('createLabel');
		$this->assertSharedConfirmation((new CreateLabelHandler($this->deck, $this->createMock(LoggerInterface::class)))->handle(['boardId' => 20, 'title' => 'x'], 'alice'));
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testUpdateNeedsSomethingToChangeAndAFreeTitle(): void {
		$label = $this->label(); $board = $this->board();
		$handler = new UpdateLabelHandler($this->deck($label, $board), $this->createMock(LoggerInterface::class), $this->states());
		try {
			$handler->preview(['labelId' => 1], 'alice');
			self::fail('empty update accepted');
		} catch (ArgumentValidationException) {
		}
		$this->expectExceptionMessageMatches('/already has a label named feature/');
		$handler->preview(['labelId' => 1, 'title' => 'feature'], 'alice');
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testUpdateWritesTheApprovedPlanOnly(): void {
		$label = $this->label(); $board = $this->board();
		$labels = $this->catalog($label, $board);
		$labels->expects(self::once())->method('updateLabel')->with('alice', 1, 'defect', 'ff7a66', LabelSnapshot::of($label, false))
			->willReturn(['id' => 1, 'boardId' => 20, 'title' => 'defect', 'color' => 'ff7a66']);
		$handler = new UpdateLabelHandler($this->deck, $this->createMock(LoggerInterface::class), $this->states());
		$plan = $handler->preview(['labelId' => 1, 'title' => 'defect'], 'alice');
		self::assertSame(['title' => 'defect', 'color' => 'ff7a66'], $plan['after']);
		self::assertSame(2, $plan['cards']);
		$payload = $this->payload($handler->handle(['labelId' => 1, 'title' => 'defect', 'confirm' => true, 'plan_state' => $plan['plan_state']], 'alice'));
		self::assertTrue($payload['changed']);
		self::assertSame('defect', $payload['label']['title']);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testUpdateOfALabelChangedAfterThePlanNeedsANewApproval(): void {
		$label = $this->label(); $board = $this->board();
		$labels = $this->catalog($label, $board);
		$labels->expects(self::never())->method('updateLabel');
		$handler = new UpdateLabelHandler($this->deck, $this->createMock(LoggerInterface::class), $this->states());
		$plan = $handler->preview(['labelId' => 1, 'color' => '00ff00'], 'alice');
		$label['label']['title'] = 'renamed by pedro';
		$this->expectException(PlanChanged::class);
		$handler->handle(['labelId' => 1, 'color' => '00ff00', 'confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testUpdateConflictRightBeforeTheWriteReturnsAFreshPlan(): void {
		$label = $this->label(); $board = $this->board();
		$labels = $this->catalog($label, $board);
		$labels->expects(self::once())->method('updateLabel')->willThrowException(new DeckConflictException());
		$handler = new UpdateLabelHandler($this->deck, $this->createMock(LoggerInterface::class), $this->states());
		$plan = $handler->preview(['labelId' => 1, 'color' => '00ff00'], 'alice');
		$this->expectException(PlanChanged::class);
		$handler->handle(['labelId' => 1, 'color' => '00ff00', 'confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testDeletePlanIsIrreversibleAndBindsTheCardCount(): void {
		$label = $this->label(); $board = $this->board();
		$labels = $this->catalog($label, $board);
		$labels->expects(self::never())->method('deleteLabel');
		$handler = new DeleteLabelHandler($this->deck, $this->createMock(LoggerInterface::class), $this->states());
		$plan = $handler->preview(['labelId' => 1], 'alice');
		self::assertFalse($plan['recoverable']);
		self::assertSame(2, $plan['cards']);
		$label['cards'] = 3;
		$this->expectException(PlanChanged::class);
		$handler->handle(['labelId' => 1, 'confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testDeleteThatThrowsIsDoneWhenTheLabelIsGone(): void {
		$label = $this->label(); $board = $this->board();
		$labels = $this->catalog($label, $board);
		$labels->expects(self::once())->method('deleteLabel')->with('alice', 1, LabelSnapshot::of($label, true))
			->willReturnCallback(function () use (&$board): never {
				array_shift($board['labels']);
				throw new \RuntimeException('event failed');
			});
		$handler = new DeleteLabelHandler($this->deck, $this->createMock(LoggerInterface::class), $this->states());
		$plan = $handler->preview(['labelId' => 1], 'alice');
		$payload = $this->payload($handler->handle(['labelId' => 1, 'confirm' => true, 'plan_state' => $plan['plan_state']], 'alice'));
		self::assertTrue($payload['deleted']);
		self::assertSame(2, $payload['cards']);
		self::assertCount(1, $payload['warnings']);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testDeleteThatFailsCleanlyIsAnError(): void {
		$label = $this->label(); $board = $this->board();
		$labels = $this->catalog($label, $board);
		$labels->method('deleteLabel')->willThrowException(new \OCA\Deck\StatusException('archived'));
		$handler = new DeleteLabelHandler($this->deck, $this->createMock(LoggerInterface::class), $this->states());
		$plan = $handler->preview(['labelId' => 1], 'alice');
		$result = $handler->handle(['labelId' => 1, 'confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		self::assertTrue($result['isError']);
	}
}
