<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

require_once __DIR__ . '/EditingFixture.php';

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Editing\EditFlow;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Editing\EditPlan;
use OCA\Mcp\Modules\Forms\Editing\EditSettings;
use OCA\Mcp\Modules\Forms\Integration\EditState;
use OCA\Mcp\Modules\Forms\Editing\EditWriter;
use OCA\Mcp\Modules\Forms\Editing\EditOptionHandler;
use OCA\Mcp\Modules\Forms\Editing\EditQuestionHandler;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use PHPUnit\Framework\TestCase;

/** Discriminates invisible-only labels through both actual handlers without stripping valid format characters. */
final class EditInvisibleLabelsTest extends TestCase {
	/** Refuses every format-only label in preview and confirmation before any native effect.
	 * @return void
	 */
	public function testBothHandlersRefuseFormatOnlyLabelsBeforeSink(): void {
		foreach ([false, true] as $option) {
			foreach (["\u{200B}", "\u{2060}", "\u{FEFF}", "\u{180E}", " \t\u{00A0}\u{200B}\u{2060}\u{FEFF}\u{180E}"] as $text) {
				$f = new EditingFixture();
				$handler = $this->handler($f, $option);
				$args = $this->args($option, $text);
				$validPlan = $handler->preview($this->args($option, 'Visible'), 'alice');
				foreach ([false, true] as $confirm) {
					try {
						if ($confirm) {
							$handler->handle($args + ['confirm' => true, 'plan_state' => $validPlan['plan_state']], 'alice');
						} else {
							$handler->preview($args, 'alice');
						}
						self::fail('Invisible-only text reached a handler plan or sink');
					} catch (ArgumentValidationException $error) {
						self::assertStringContainsString('changes.text', $error->getMessage());
						self::assertSame([], $f->calls);
						self::assertNull($f->form->fields['lockedBy']);
					}
				}
			}
		}
	}

	/** Preserves a visible label containing a format character across plan, PATCH and observed receipt.
	 * @return void
	 */
	public function testVisibleTextKeepsFormatCharacterBytesInBothHandlers(): void {
		foreach ([false, true] as $option) {
			$f = new EditingFixture();
			$handler = $this->handler($f, $option);
			$text = "a\u{200B}b";
			$args = $this->args($option, $text);
			$plan = $handler->preview($args, 'alice');
			self::assertSame([], $f->calls);
			$reply = $handler->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
			$receipt = json_decode($reply['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
			self::assertSame('applied', $receipt['status']);
			self::assertSame([['text' => $text]], $f->calls);
			self::assertSame($text, $option ? $f->option->data['text'] : $f->question->data['text']);
		}
	}

	/** Keeps description clearing and zero-minimum settings values distinct from required nonblank labels.
	 * @return void
	 */
	public function testZeroMinimumDescriptionAndSettingsSemanticsStayUnchanged(): void {
		foreach (['', "\u{200B}\u{2060}\u{FEFF}\u{180E}"] as $text) {
			$f = new EditingFixture();
			$handler = $this->handler($f, false);
			$f->question->data['description'] = 'Previous description';
			$args = ['form_id' => 1, 'question_id' => 2, 'changes' => ['description' => $text]];
			$plan = $handler->preview($args, 'alice');
			$reply = $handler->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
			self::assertSame('applied', json_decode($reply['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)['status']);
			self::assertSame($text, $f->question->data['description']);
			self::assertSame(['optionsLabelLowest' => $text], EditSettings::merge('linearscale', [], ['settings' => ['optionsLabelLowest' => $text]]));
		}
		self::assertSame(['dateMin' => null], EditSettings::merge('date', [], ['settings' => ['dateMin' => null]]));
		self::assertSame([], EditSettings::merge('date', ['dateMin' => 1], ['unset_settings' => ['dateMin']]));
	}

	/** Builds a real focused edit flow behind a handler and explicit unit-only native ports.
	 * @param EditingFixture $f Synthetic native state.
	 * @param bool $option Whether this is the option handler.
	 * @return EditQuestionHandler|EditOptionHandler Actual product handler.
	 */
	private function handler(EditingFixture $f, bool $option): EditQuestionHandler|EditOptionHandler {
		$factory = $this->createMock(EditNativeFactory::class);
		$factory->method('open')->willReturn($f->context());
		$states = new EditState();
		$plans = new EditPlan(new PlanState((new InMemoryConfig())->mock($this)));
		$throttle = new CreateNativeThrottle($this->createMock(\OCP\Security\Bruteforce\IThrottler::class), $this->createMock(\OCP\IRequest::class));
		$flow = new EditFlow($factory, $states, $plans, new EditWriter($factory, $states, $plans, $throttle));
		return $option ? new EditOptionHandler($flow) : new EditQuestionHandler($flow);
	}

	/** Supplies only immutable IDs and one label change, never an existing state bypass.
	 * @param bool $option Whether an option ID is required.
	 * @param string $text Exact label bytes.
	 * @return array<string,mixed> Closed handler input.
	 */
	private function args(bool $option, string $text): array {
		return ['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => $text]] + ($option ? ['option_id' => 3] : []);
	}
}
