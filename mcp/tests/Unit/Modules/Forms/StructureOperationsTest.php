<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

require_once __DIR__ . '/StructureHarness.php';

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Modules\Forms\Restructuring\QuestionTypes;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use PHPUnit\Framework\TestCase;

/** Operation rules of F01 (add/reorder), F02 (delete) and F03 (type change), checked through the real flow. */
final class StructureOperationsTest extends TestCase {
	/** Insertion follows the native shift on non-contiguous orders; out-of-range positions and grid rules are refused. @return void */
	public function testAddQuestion(): void {
		$f = new StructureFixture();
		$f->questions[4]->data['order'] = 7;
		$plan = $this->plan($f, 'forms_add_question', ['form_id' => 1, 'type' => 'short', 'text' => 'New', 'position' => 3]);
		self::assertSame(['id' => 4, 'order' => 8], $plan['after']['questions'][2]);
		self::assertSame(3, $plan['after']['newQuestion']['order']);
		self::assertSame(7 + 1, $this->apply($f, 'forms_add_question', ['form_id' => 1, 'type' => 'short', 'text' => 'New'])['observed']['question']['order']);
		foreach ([['position' => 10], ['type' => 'datetime'], ['type' => 'grid'], ['grid_type' => 'radio'], ['type' => 'grid', 'grid_type' => 'star'], ['text' => ' '], ['extra' => 1]] as $bad) {
			$this->refused(new StructureFixture(), 'forms_add_question', $bad + ['form_id' => 1, 'type' => 'short', 'text' => 'New']);
		}
		$f = new StructureFixture();
		for ($i = 0; $i < 197; $i++) {
			$f->question(500 + $i, 10 + $i, 'long', 'q', []);
		}
		$this->refused($f, 'forms_add_question', ['form_id' => 1, 'type' => 'short', 'text' => 'New'], StructureMessages::limit());
	}

	/** Options go to an explicit partition; a skipped native insert is reported as partial. @return void */
	public function testAddOption(): void {
		$f = new StructureFixture();
		$receipt = $this->apply($f, 'forms_add_option', ['form_id' => 1, 'question_id' => 3, 'texts' => ['C', 'D'], 'option_type' => 'choice']);
		self::assertSame('applied', $receipt['status']);
		self::assertSame([3, 4], array_column($receipt['observed']['options'], 'order'));
		self::assertSame('choice', $f->calls[0][1][3]);
		$f = new StructureFixture();
		$f->skipInsert = true;
		self::assertSame('partial', $this->apply($f, 'forms_add_option', ['form_id' => 1, 'question_id' => 3, 'texts' => ['C', 'D'], 'option_type' => 'choice'])['status']);
		$f = new StructureFixture();
		$f->option(12, 3, 5, 'Legacy', null);
		self::assertContains(StructureMessages::legacyOptions(), $this->plan($f, 'forms_add_option', ['form_id' => 1, 'question_id' => 3, 'texts' => ['C'], 'option_type' => 'choice'])['effects']);
		$f->question(6, 4, 'grid', 'Grid', ['questionType' => 'radio']);
		$f->question(7, 5, 'linearscale', 'Scale', []);
		self::assertSame([1], array_column($this->plan($f, 'forms_add_option', ['form_id' => 1, 'question_id' => 6, 'texts' => ['R1'], 'option_type' => 'row'])['after']['newOptions'], 'order'));
		foreach ([[3, 'row'], [6, 'choice'], [7, 'choice'], [2, 'choice']] as [$question, $type]) {
			$this->refused($f, 'forms_add_option', ['form_id' => 1, 'question_id' => $question, 'texts' => ['X'], 'option_type' => $type]);
		}
		$this->refused($f, 'forms_add_option', ['form_id' => 1, 'question_id' => 3, 'texts' => ['C'], 'option_type' => 'choice', 'position' => 1]);
	}

	/** Reorder needs the complete partition; another partition is invalid, a foreign ID is not found. @return void */
	public function testReorderOptionsPartitions(): void {
		$f = new StructureFixture();
		$f->question(6, 4, 'grid', 'Grid', ['questionType' => 'radio']);
		$f->option(20, 6, 1, 'R1', 'row');
		$f->option(21, 6, 2, 'C1', 'column');
		$f->option(22, 6, 3, 'R2', 'row');
		$receipt = $this->apply($f, 'forms_reorder_options', ['form_id' => 1, 'question_id' => 6, 'option_type' => 'row', 'option_ids' => [22, 20]]);
		self::assertSame('applied', $receipt['status']);
		self::assertSame('row', $f->calls[0][1][3]);
		$this->refused($f, 'forms_reorder_options', ['form_id' => 1, 'question_id' => 6, 'option_ids' => [22, 20]]);
		$this->refused($f, 'forms_reorder_options', ['form_id' => 1, 'question_id' => 6, 'option_type' => 'row', 'option_ids' => [20, 21]]);
		$this->refused($f, 'forms_reorder_options', ['form_id' => 1, 'question_id' => 6, 'option_type' => 'row', 'option_ids' => [20, 10]], CommonMessages::notFound());
		$this->refused($f, 'forms_reorder_questions', ['form_id' => 1, 'question_ids' => [2, 3]]);
		$this->refused($f, 'forms_reorder_questions', ['form_id' => 1, 'question_ids' => [2, 2, 3, 4, 6]]);
		$this->apply($f, 'forms_reorder_options', ['form_id' => 1, 'question_id' => 3, 'option_ids' => [11, 10]]);
		self::assertNull($f->calls[1][1][3], 'a choice partition is sent without a type filter');
	}

	/** Deleting the selected question clears the selection; deleting a file question observes the queued job only. @return void */
	public function testDeleteQuestion(): void {
		$f = new StructureFixture();
		$plan = $this->plan($f, 'forms_delete_question', ['form_id' => 1, 'question_id' => 2]);
		self::assertTrue($plan['irreversible']);
		self::assertTrue($plan['confirmation']['selection_clear_predicted']);
		self::assertSame(['3' => 1, '4' => 2], $plan['after']);
		self::assertSame('applied', $this->apply($f, 'forms_delete_question', ['form_id' => 1, 'question_id' => 2])['status']);
		self::assertNull($f->form->fields['confirmationEmailQuestionId']);
		$f = new StructureFixture();
		self::assertContains(StructureMessages::uploadFoldersJob(), $this->plan($f, 'forms_delete_question', ['form_id' => 1, 'question_id' => 4])['effects']);
		$receipt = $this->apply($f, 'forms_delete_question', ['form_id' => 1, 'question_id' => 4]);
		self::assertSame(['queuedByNativeDeletion' => true, 'observed' => false], $receipt['uploadFolderJob']);
		self::assertFalse($receipt['filesObserved']);
	}

	/** Deleting an option is permanent and natively renumbers the whole question across grid partitions. @return void */
	public function testDeleteOptionRenumbersEveryOption(): void {
		$f = new StructureFixture();
		$f->question(6, 4, 'grid', 'Grid', ['questionType' => 'radio']);
		$f->option(20, 6, 1, 'R1', 'row');
		$f->option(21, 6, 1, 'C1', 'column');
		$f->option(22, 6, 2, 'R2', 'row');
		$plan = $this->plan($f, 'forms_delete_option', ['form_id' => 1, 'question_id' => 6, 'option_id' => 20]);
		self::assertSame([['id' => 21, 'order' => 1, 'optionType' => 'column'], ['id' => 22, 'order' => 2, 'optionType' => 'row']], $plan['after']);
		self::assertTrue($plan['irreversible']);
		self::assertSame('applied', $this->apply($f, 'forms_delete_option', ['form_id' => 1, 'question_id' => 6, 'option_id' => 20])['status']);
	}

	/** Grid and datetime are never targets; incompatible settings need explicit settings accepted by both validators. @return void */
	public function testChangeQuestionType(): void {
		$f = new StructureFixture();
		try {
			$this->plan($f, 'forms_change_question_type', ['form_id' => 1, 'question_id' => 3, 'type' => 'grid']);
			self::fail('grid target admitted');
		} catch (ArgumentValidationException $error) {
			self::assertStringContainsString(StructureMessages::gridTarget(), $error->clientMessage());
		}
		$this->refused($f, 'forms_change_question_type', ['form_id' => 1, 'question_id' => 3, 'type' => 'datetime']);
		$this->refused($f, 'forms_change_question_type', ['form_id' => 1, 'question_id' => 2, 'type' => 'long']);
		$this->refused($f, 'forms_change_question_type', ['form_id' => 1, 'question_id' => 2, 'type' => 'long', 'settings' => ['validationType' => 'email']]);
		$f->validSettings = false;
		$this->refused($f, 'forms_change_question_type', ['form_id' => 1, 'question_id' => 3, 'type' => 'multiple', 'settings' => ['shuffleOptions' => true]]);
		$f = new StructureFixture();
		$plan = $this->plan($f, 'forms_change_question_type', ['form_id' => 1, 'question_id' => 2, 'type' => 'long', 'settings' => []]);
		self::assertTrue($plan['confirmation']['selection_clear_predicted']);
		self::assertContains(StructureMessages::answersNotConverted(), $plan['effects']);
		$f->question(8, 4, 'datetime', 'When', []);
		self::assertSame('applied', $this->apply($f, 'forms_change_question_type', ['form_id' => 1, 'question_id' => 8, 'type' => 'date'])['status']);
		foreach (QuestionTypes::TARGETS as $target) {
			$f = new StructureFixture();
			$receipt = $this->apply($f, 'forms_change_question_type', ['form_id' => 1, 'question_id' => 3, 'type' => $target, 'settings' => []]);
			self::assertSame($target === 'dropdown' ? 'not_attempted' : 'applied', $receipt['status'], $target);
		}
		self::assertCount(11, QuestionTypes::TARGETS);
		self::assertNotContains('grid', QuestionTypes::TARGETS);
		self::assertNotContains('datetime', QuestionTypes::TARGETS);
	}

	/**
	 * @param StructureFixture $f fixture
	 * @param string $tool tool
	 * @param array<string,mixed> $args arguments
	 * @return array<string,mixed> plan
	 */
	private function plan(StructureFixture $f, string $tool, array $args): array {
		return StructureHarness::flow($this, $f)->preview(StructureHarness::operations()[$tool], $args, 'alice');
	}

	/**
	 * @param StructureFixture $f fixture
	 * @param string $tool tool
	 * @param array<string,mixed> $args arguments
	 * @return array<string,mixed> receipt
	 */
	private function apply(StructureFixture $f, string $tool, array $args): array {
		$plan = $this->plan($f, $tool, $args);
		$reply = StructureHarness::flow($this, $f)->handle(StructureHarness::operations()[$tool], $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		return json_decode($reply['content'][0]['text'], true);
	}

	/**
	 * @param StructureFixture $f fixture
	 * @param string $tool tool
	 * @param array<string,mixed> $args arguments
	 * @param string|null $message expected refusal text, when it is a ToolFailure
	 * @return void
	 */
	private function refused(StructureFixture $f, string $tool, array $args, ?string $message = null): void {
		$calls = count($f->calls);
		try {
			$this->plan($f, $tool, $args);
			self::fail($tool . ' admitted ' . json_encode($args));
		} catch (ToolFailure $error) {
			if ($message !== null) {
				self::assertSame($message, $error->getMessage());
			}
		} catch (ArgumentValidationException) {
			self::assertNull($message, $tool . ' expected ' . $message);
		}
		self::assertCount($calls, $f->calls);
	}
}
