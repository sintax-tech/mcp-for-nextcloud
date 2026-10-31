<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

require_once __DIR__ . '/EditingFixture.php';
use OCA\Mcp\Tools\Forms\Editing\{EditContext, EditState, EditRequest, EditPlan};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Mcp\{Tools\Validation\ArgumentValidationException, Confirmation\PlanState, Tools\Results\ToolFailure};
use PHPUnit\Framework\TestCase;
/** Discriminates complete inspection/plan budgets, native final-map validation and closed/expired versus archived/locked admission. */
final class EditLimitsStateTest extends TestCase
{
	/** Checks post-fetch limits on complete maps without treating a partial enumeration as authorization.
	 * @return void
	 */
	public function testInspectionAndCompletePlanBudgetsRefuseWithoutWrites(): void
	{
		foreach (['questions', 'options', 'settings', 'plan'] as $mode) {
			$f = new EditingFixture();
			$c = $f->context();
			$r = EditRequest::of(['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => 'New']], false);
			if ($mode === 'questions' || $mode === 'options') {
				$port = new EditOversizedReader($mode === 'questions' ? $f->question : $f->option);
				$c = new EditContext($c->version, $c->controller, $c->config, $c->service, $mode === 'questions' ? $port : $c->questions, $mode === 'options' ? $port : $c->options, $c->shares);
			}
			if ($mode === 'settings') {
				$f->question->data['extraSettings'] = ['validationRegex' => str_repeat('x', 8193)];
			}
			if ($mode === 'plan') {
				$f->form->fields['title'] = str_repeat('x', 131073);
			}
			try {
				$state = new EditState();
				$s = $state->read($c, $r, 'alice');
				$e = $state->effect($c, $r, $s);
				(new EditPlan(new PlanState((new InMemoryConfig())->mock($this))))->of($r, $c, $s, $e, 'alice');
				self::fail('Complete budget exceeded: ' . $mode);
			} catch (ToolFailure $e) {
				self::assertStringContainsString('limit', $e->getMessage());
				self::assertSame([], $f->calls);
			}
		}
	}
	/** Keeps native validation authoritative and preserves unknown stored settings for text-only edits without projecting them.
	 * @return void
	 */
	public function testNativeValidatorAndUnknownSettingPreservation(): void
	{
		$f = new EditingFixture();
		$state = new EditState();
		$f->validSettings = false;
		$c = $f->context();
		$r = EditRequest::of(['form_id' => 1, 'question_id' => 2, 'changes' => ['settings' => ['validationType' => 'phone']]], false);
		$s = $state->read($c, $r, 'alice');
		try {
			$state->effect($c, $r, $s);
			self::fail('Native final validator ignored');
		} catch (ArgumentValidationException) {
			self::assertSame([], $f->calls);
		}
		$f->question->data['extraSettings']['unrecognizedLegacy'] = 'private native setting';
		$r = EditRequest::of(['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => 'New']], false);
		$s = $state->read($c, $r, 'alice');
		$e = $state->effect($c, $r, $s);
		self::assertSame(['text' => 'New'], $e['patch']);
		self::assertSame($s['question']['extraSettings'], $e['expected']['extraSettings']);
		$plan = (new EditPlan(new PlanState((new InMemoryConfig())->mock($this))))->of($r, $c, $s, $e, 'alice');
		self::assertStringNotContainsString('private native setting', json_encode($plan));
	}
	/** Closed and expired metadata is not a fabricated native ban; expired or same-actor locks remain admissible.
	 * @return void
	 */
	public function testClosedAndExpiredOwnLocksRemainAdmissible(): void
	{
		$f = new EditingFixture();
		$f->form->fields['state'] = 1;
		$f->form->fields['expires'] = time() - 1;
		$f->form->fields['lockedBy'] = 'bob';
		$f->form->fields['lockedUntil'] = time() - 1;
		$r = EditRequest::of(['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => 'New']], false);
		self::assertSame(1, (new EditState())->read($f->context(), $r, 'alice')['header']['state']);
		$f->form->fields['lockedBy'] = 'alice';
		$f->form->fields['lockedUntil'] = 0;
		self::assertSame('alice', (new EditState())->read($f->context(), $r, 'alice')['header']['lockedBy']);
		self::assertSame([], $f->calls);
	}
}
