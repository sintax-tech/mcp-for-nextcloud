<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

require_once __DIR__ . '/StructureHarness.php';

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use PHPUnit\Framework\TestCase;

/** Common flow of the seven structure tools: inert preview, no leak, lock/archive, strict confirmation and receipts. */
final class StructureFlowTest extends TestCase {
	/** Every preview is inert: no native mutator, no lock taken, lastUpdated untouched. @return void */
	public function testEveryPreviewIsInert(): void {
		foreach (StructureHarness::operations() as $name => $op) {
			$f = new StructureFixture();
			$plan = StructureHarness::flow($this, $f)->preview($op, StructureHarness::arguments()[$name], 'alice');
			self::assertSame([], $f->calls, $name);
			self::assertNull($f->form->fields['lockedBy'], $name);
			self::assertSame(1, $f->form->fields['lastUpdated'], $name);
			self::assertFalse($plan['lock']['obtainedByPreview'], $name);
			self::assertIsString($plan['plan_state'], $name);
		}
	}

	/** A missing form and an unreadable form give the same not-found; readable without EDIT gives forbidden. @return void */
	public function testNoExistenceLeak(): void {
		foreach (StructureHarness::operations() as $name => $op) {
			$answers = [];
			foreach (['missing' => [false, []], 'unreadable' => [true, []], 'readable' => [true, ['submit']]] as $case => [$exists, $permissions]) {
				$f = new StructureFixture();
				$f->exists = $exists;
				$f->permissions = $permissions;
				try {
					StructureHarness::flow($this, $f)->preview($op, StructureHarness::arguments()[$name], 'alice');
					self::fail("$name $case admitted");
				} catch (ToolFailure $error) {
					$answers[$case] = [get_class($error), $error->getMessage()];
				}
				self::assertSame([], $f->calls);
			}
			self::assertSame($answers['missing'], $answers['unreadable'], $name);
			self::assertSame(CommonMessages::notFound(), $answers['missing'][1]);
			self::assertSame(CommonMessages::forbidden(), $answers['readable'][1]);
		}
	}

	/** Foreign or deleted question and option IDs answer not found inside an editable form. @return void */
	public function testForeignAndDeletedIdsAreNotFound(): void {
		$ops = StructureHarness::operations();
		$f = new StructureFixture();
		$f->question(9, 0, 'short', 'Deleted', []);
		foreach ([['forms_delete_question', ['question_id' => 9]], ['forms_delete_question', ['question_id' => 77]],
			['forms_delete_option', ['option_id' => 77]], ['forms_add_option', ['question_id' => 9]],
			['forms_reorder_questions', ['question_ids' => [4, 2, 99]]], ['forms_change_question_type', ['question_id' => 77]]] as [$name, $patch]) {
			try {
				StructureHarness::flow($this, $f)->preview($ops[$name], $patch + StructureHarness::arguments()[$name], 'alice');
				self::fail($name . ' admitted a foreign id');
			} catch (ToolFailure $error) {
				self::assertSame(CommonMessages::notFound(), $error->getMessage(), $name);
			}
		}
	}

	/** Native lock condition (R2): no lock, own lock, third party valid/zero/expired; archived refused. @return void */
	public function testLockAndArchive(): void {
		$op = StructureHarness::operations()['forms_delete_option'];
		$args = StructureHarness::arguments()['forms_delete_option'];
		foreach ([[null, 0, true], ['alice', time() + 60, true], ['bob', time() + 60, false], ['bob', 0, false], ['bob', time() - 60, true], [null, time() + 60, true]] as [$by, $until, $ok]) {
			$f = new StructureFixture();
			$f->form->fields['lockedBy'] = $by;
			$f->form->fields['lockedUntil'] = $until;
			try {
				StructureHarness::flow($this, $f)->preview($op, $args, 'alice');
				self::assertTrue($ok, "lock $by/$until admitted");
			} catch (ToolFailure $error) {
				self::assertFalse($ok, "lock $by/$until refused");
				self::assertSame(StructureMessages::locked(), $error->getMessage());
			}
		}
		$f = new StructureFixture();
		$f->form->fields['state'] = 2;
		$this->expectExceptionMessage(StructureMessages::archived());
		StructureHarness::flow($this, $f)->preview($op, $args, 'alice');
	}

	/** Confirmation is strict: confirm, matching plan_state and confirm_shared on a shared form. @return void */
	public function testStrictConfirmation(): void {
		$op = StructureHarness::operations()['forms_delete_option'];
		$args = StructureHarness::arguments()['forms_delete_option'];
		$f = new StructureFixture();
		$flow = StructureHarness::flow($this, $f);
		foreach ([$args, $args + ['confirm' => false]] as $missing) {
			try {
				$flow->handle($op, $missing, 'alice');
				self::fail('write without confirm');
			} catch (ToolFailure) {
			}
		}
		try {
			$flow->handle($op, $args + ['confirm' => true, 'plan_state' => 'forged'], 'alice');
			self::fail('forged plan_state');
		} catch (PlanChanged) {
		}
		$plan = $flow->preview($op, $args, 'bob');
		self::assertTrue($plan['shared']);
		$reply = $flow->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'bob');
		self::assertTrue(json_decode($reply['content'][0]['text'], true)['requiresConfirmation']);
		self::assertSame([], $f->calls);
	}

	/** A change between plan and confirm, detected right before the call, is a PlanChanged without mutation. @return void */
	public function testConcurrentChangeBeforeTheCall(): void {
		$op = StructureHarness::operations()['forms_delete_option'];
		$args = StructureHarness::arguments()['forms_delete_option'];
		$f = new StructureFixture();
		$plan = StructureHarness::flow($this, $f)->preview($op, $args, 'alice');
		$flow = StructureHarness::flow($this, $f, function () use ($f): void { $f->options[11]->data['text'] = 'Changed'; });
		try {
			$flow->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
			self::fail('stale plan executed');
		} catch (PlanChanged $changed) {
			self::assertSame(StructureMessages::planChanged(), $changed->plan['message']);
		}
		self::assertSame([], $f->calls);
	}

	/**
	 * Review R1: description and required flag are signed too. Changed by another session in the same second (same
	 * lastUpdated, same lock), either one alone still turns the confirmation into a PlanChanged before any mutator.
	 *
	 * @return void
	 */
	public function testDescriptionAndRequiredChangesInvalidateThePlan(): void {
		$op = StructureHarness::operations()['forms_delete_question'];
		$args = StructureHarness::arguments()['forms_delete_question'];
		foreach (['description' => 'Changed elsewhere', 'isRequired' => true] as $field => $value) {
			$f = new StructureFixture();
			$plan = StructureHarness::flow($this, $f)->preview($op, $args, 'alice');
			$stamp = [$f->form->fields['lastUpdated'], $f->form->fields['lockedBy'], $f->form->fields['lockedUntil']];
			$flow = StructureHarness::flow($this, $f, function () use ($f, $field, $value): void { $f->questions[4]->data[$field] = $value; });
			try {
				$flow->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
				self::fail($field . ' change executed a stale plan');
			} catch (PlanChanged) {
			}
			self::assertSame($stamp, [$f->form->fields['lastUpdated'], $f->form->fields['lockedBy'], $f->form->fields['lockedUntil']]);
			self::assertSame([], $f->calls, $field);
		}
	}

	/** Every operation applies once and the receipt is built from the readback. @return void */
	public function testEveryOperationAppliesOnceWithReadback(): void {
		foreach (StructureHarness::operations() as $name => $op) {
			$f = new StructureFixture();
			$flow = StructureHarness::flow($this, $f);
			$args = StructureHarness::arguments()[$name];
			$plan = $flow->preview($op, $args, 'alice');
			$receipt = json_decode($flow->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice')['content'][0]['text'], true);
			self::assertSame('applied', $receipt['status'], $name . ' ' . json_encode($receipt));
			self::assertCount(1, $f->calls, $name);
			self::assertTrue($receipt['lock']['actorLockObserved'], $name);
			self::assertFalse($receipt['lock']['unlockAttempted'], $name);
		}
	}

	/** Failure after the write gives a partial receipt; a denied readback gives uncertain; nothing is retried. @return void */
	public function testPartialAndUncertainWithoutRetry(): void {
		$op = StructureHarness::operations()['forms_reorder_questions'];
		$args = StructureHarness::arguments()['forms_reorder_questions'];
		$f = new StructureFixture();
		$f->failAfterWrite = true;
		$plan = StructureHarness::flow($this, $f)->preview($op, $args, 'alice');
		$reply = StructureHarness::flow($this, $f)->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		self::assertSame('partial', json_decode($reply['content'][0]['text'], true)['status']);
		self::assertTrue($reply['isError']);
		self::assertCount(1, $f->calls);

		$f = new StructureFixture();
		$plan = StructureHarness::flow($this, $f)->preview($op, $args, 'alice');
		$calls = 0;
		$flow = StructureHarness::flow($this, $f, function () use (&$calls): void {
			if (++$calls === 2) {
				throw new ToolFailure('revoked');
			}
		});
		$receipt = json_decode($flow->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice')['content'][0]['text'], true);
		self::assertSame('uncertain', $receipt['status']);
		self::assertArrayNotHasKey('observed', $receipt);
		self::assertCount(1, $f->calls);
	}

	/** A request that changes nothing is not attempted but still needs the native EDIT admission. @return void */
	public function testNoopIsNotAttempted(): void {
		$op = StructureHarness::operations()['forms_reorder_questions'];
		$args = ['form_id' => 1, 'question_ids' => [2, 3, 4]];
		$f = new StructureFixture();
		$flow = StructureHarness::flow($this, $f);
		$plan = $flow->preview($op, $args, 'alice');
		self::assertTrue($plan['noChange']);
		$receipt = json_decode($flow->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice')['content'][0]['text'], true);
		self::assertSame('not_attempted', $receipt['status']);
		self::assertSame([], $f->calls);
		$f->permissions = ['submit'];
		$this->expectException(ToolFailure::class);
		$flow->preview($op, $args, 'alice');
	}
}
