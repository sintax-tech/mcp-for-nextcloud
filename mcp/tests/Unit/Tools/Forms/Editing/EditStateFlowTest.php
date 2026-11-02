<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

require_once __DIR__ . '/EditingFixture.php';
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Forms\Editing\{EditState, EditFlow, EditNativeFactory, EditPlan, EditPlanRenderer, EditRequest, EditWriter, EditMessages};
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Mcp\{Confirmation\PlanState, Confirmation\PlanChanged, Tools\Results\ToolFailure};
use PHPUnit\Framework\TestCase;
/** Discriminates native guards, matched shared confirmation, B1 readback and one-shot partial effects. */
final class EditStateFlowTest extends TestCase
{
	/** Checks ACL before active lookup, roots deleted/foreign before options and all native state refusals.
	 * @return void
	 */
	public function testActiveMapAndNativeGuardsRefuseWithoutForeignLookup(): void
	{
		foreach (['acl', 'deleted', 'foreign', 'archive', 'lock', 'future', 'mapper'] as $mode) {
			$f = new EditingFixture();
			$r = $this->args();
			match ($mode) {
				'acl' => $f->allow = false,
				'deleted' => $f->deleted = true,
				'foreign' => $r['question_id'] = 999,
				'archive' => $f->form->fields['state'] = 2,
				'lock' => $f->form->fields['lockedBy'] = 'bob',
				'future' => $f->question->data['type'] = 'future',
				'mapper' => $f->readFails = true,
			};
			if ($mode === 'lock') {
				$f->form->fields['lockedUntil'] = 0;
			}
			try {
				(new EditState())->read($f->context(), EditRequest::of($r, false), 'alice');
				self::fail($mode . ' admitted');
			} catch (\Throwable $error) {
				self::assertInstanceOf(in_array($mode, ['mapper', 'acl'], true) ? \RuntimeException::class : ToolFailure::class, $error);
				self::assertSame([], $f->calls);
			}
			if (in_array($mode, ['acl', 'deleted', 'foreign', 'archive', 'lock'], true)) {
				self::assertNotContains('options:2', $f->reads);
			}
		}
	}
	/** Rejects a native submit-only collaborator, even with an already admitted MCP edit grant.
	 * @return void
	 */
	public function testSubmitOnlyDoesNotAuthorizeEditing(): void
	{
		$f = new EditingFixture();
		$f->nativePermissions = ['submit'];
		[$flow] = $this->flow($f);
		try {
			$flow->preview($this->args(), 'alice', false);
			self::fail('SUBMIT-only native permission authorized an edit');
		} catch (ToolFailure) {
			self::assertSame([], $f->calls);
			self::assertSame([], $f->reads);
		}
	}
	/** Shows the B1 consequence faithfully even when disabled, without a sink or a recipient/owner leak.
	 * @return void
	 */
	public function testPreviewBindsWarningsHostileTextAndStateDrift(): void
	{
		$f = new EditingFixture();
		[$flow] = $this->flow($f);
		$args = $this->args();
		$args['changes'] = ['text' => "<script>\n# evil", 'settings' => ['validationType' => 'phone']];
		$plan = $flow->preview($args, 'alice', false);
		self::assertTrue($plan['confirmation']['selection_clear_predicted']);
		self::assertSame([], $f->calls);
		self::assertNull($f->form->fields['lockedBy']);
		$body = (new EditPlanRenderer())->render($plan);
		self::assertStringNotContainsString('<script>', $body);
		self::assertStringNotContainsString("\n# evil", $body);
		self::assertStringContainsString('15 minutes', $body);
		self::assertStringNotContainsString('owner', json_encode($plan));
		self::assertStringNotContainsString('selector', json_encode($plan));
		$f->form->fields['confirmationEmailEnabled'] = false;
		try {
			$flow->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice', false);
			self::fail('changed flag admitted');
		} catch (PlanChanged $error) {
			self::assertSame([], $f->calls);
			self::assertStringContainsString('already disabled', implode(' ', $error->plan['notes']));
		}
	}
	/** Requires explicit shared admission and detects a changed impact even with an old confirmation bit.
	 * @return void
	 */
	public function testSharedConfirmAndChangedImpact(): void
	{
		$f = new EditingFixture();
		$f->form->setOwnerId('bob');
		[$flow] = $this->flow($f);
		$args = $this->args();
		$plan = $flow->preview($args, 'alice', false);
		$result = $flow->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice', false);
		self::assertTrue($this->json($result)['requiresConfirmation']);
		self::assertSame([], $f->calls);
		$result = $flow->handle($args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan['plan_state']], 'alice', false);
		self::assertSame('applied', $this->json($result)['status']);
		self::assertCount(1, $f->calls);
		$f = new EditingFixture();
		[$flow] = $this->flow($f);
		$plan = $flow->preview($args, 'alice', false);
		$f->form->setOwnerId('bob');
		$this->expectException(PlanChanged::class);
		$flow->handle($args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan['plan_state']], 'alice', false);
	}
	/** Exercises applied B1, inactive/inconsistent state, a native void veto, post-target failure and deleted readback.
	 * @return void
	 */
	public function testObservedOutcomesNeverRelyOnVoidOrStatusAlone(): void
	{
		foreach (['normal', 'disabled', 'app-disabled', 'inconsistent', 'partial', 'void', 'status-only', 'deleted', 'revoke'] as $mode) {
			$f = new EditingFixture();
			if ($mode === 'app-disabled') {
				$f->allowConfirmation = false;
			}
			if ($mode === 'disabled') {
				$f->form->fields['confirmationEmailEnabled'] = false;
			}
			if ($mode === 'inconsistent') {
				$f->question->data['extraSettings'] = ['validationType' => 'phone'];
			}
			$f->throwAfterTarget = $mode === 'partial';
			$f->voidWithoutWrite = $mode === 'void';
			$f->statusWithoutWrite = $mode === 'status-only';
			$f->deleteAfterTarget = $mode === 'deleted';
			$afterChecks = 0;
			$check = $mode === 'revoke' ? static function () use (&$afterChecks, $f): void {
				if (++$afterChecks === 2) {
					$f->allow = false;
				}
			} : null;
			[$flow] = $this->flow($f, $check);
			$args = $this->args();
			if ($mode !== 'inconsistent') {
				$args['changes']['settings'] = ['validationType' => 'phone'];
			}
			$plan = $flow->preview($args, 'alice', false);
			$reply = $this->json($flow->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice', false));
			self::assertCount(1, $f->calls);
			self::assertSame(match ($mode) {
				'partial' => 'partial',
				'void', 'status-only', 'deleted', 'revoke' => 'uncertain',
				default => 'applied',
			}, $reply['status'], $mode);
			self::assertStringNotContainsString('private', json_encode($reply));
			if (in_array($mode, ['normal', 'disabled', 'app-disabled', 'inconsistent'], true)) {
				self::assertNull($f->form->fields['confirmationEmailQuestionId']);
				self::assertTrue($reply['confirmation']['selection_empty_observed']);
			}
			if ($mode === 'partial') {
				self::assertSame(2, $f->form->fields['confirmationEmailQuestionId']);
				self::assertFalse($reply['lock']['actorLockObserved']);
				self::assertFalse($reply['confirmation']['selection_empty_observed']);
				self::assertStringNotContainsString('native update clears', implode(' ', $reply['notes']));
			}
			if (in_array($mode, ['deleted', 'revoke'], true)) {
				self::assertArrayNotHasKey('observed', $reply);
			}
		}
	}
	/** Revalidates after the throttle, before marking native entry; refusal has zero sink.
	 * @return void
	 */
	public function testLastBoundaryDriftAndNoopNeverCallController(): void
	{
		$f = new EditingFixture();
		$checks = 0;
		[$flow] = $this->flow($f, static function () use (&$checks, $f): void {
			if (++$checks === 1) {
				$f->question->data['text'] = 'External';
			}
		});
		$args = $this->args();
		$plan = $flow->preview($args, 'alice', false);
		try {
			$flow->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice', false);
			self::fail('drift reached sink');
		} catch (PlanChanged) {
			self::assertSame([], $f->calls);
		}
		$f = new EditingFixture();
		[$flow] = $this->flow($f);
		$args = ['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => 'Email']];
		$plan = $flow->preview($args, 'alice', false);
		$reply = $this->json($flow->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice', false));
		self::assertSame('not_attempted', $reply['status']);
		self::assertSame([], $f->calls);
	}
	/** Keeps the point option path independent of native question B1, including a non-email selected question.
	 * @return void
	 */
	public function testOptionIsSingleTextPatchWithoutSelectionCleanup(): void
	{
		$f = new EditingFixture();
		$f->question->data['extraSettings'] = [];
		[$flow] = $this->flow($f);
		$args = ['form_id' => 1, 'question_id' => 2, 'option_id' => 3, 'changes' => ['text' => 'Renamed']];
		$plan = $flow->preview($args, 'alice', true);
		self::assertNull($plan['confirmation']);
		$reply = $this->json($flow->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice', true));
		self::assertSame('applied', $reply['status']);
		self::assertSame([['text' => 'Renamed']], $f->calls);
		self::assertSame(2, $f->form->fields['confirmationEmailQuestionId']);
	}
	/** Creates real product flow/state/plan/writer around explicit unit-only native ports.
	 * @param EditingFixture $f Synthetic state.
	 * @param callable|null $check Last-boundary admission callback.
	 * @return array{EditFlow,EditNativeFactory} Flow and admission double.
	 */
	private function flow(EditingFixture $f, ?callable $check = null): array
	{
		$factory = $this->createMock(EditNativeFactory::class);
		$factory->method('open')->willReturn($f->context());
		if ($check !== null) {
			$factory->method('assertAllowed')->willReturnCallback($check);
		}
		$plans = new EditPlan(new PlanState((new InMemoryConfig())->mock($this)));
		$states = new EditState();
		$throttle = new CreateNativeThrottle($this->createMock(\OCP\Security\Bruteforce\IThrottler::class), $this->createMock(\OCP\IRequest::class));
		return [new EditFlow($factory, $states, $plans, new EditWriter($factory, $states, $plans, $throttle)), $factory];
	}
	/** Returns one admitted text change.
	 * @return array<string,mixed> Request.
	 */
	private function args(): array
	{
		return ['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => 'New']];
	}
	/** Parses an actual MCP JSON text result.
	 * @param array<string,mixed> $reply MCP result.
	 * @return array<string,mixed> Receipt.
	 */
	private function json(array $reply): array
	{
		return json_decode($reply['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
