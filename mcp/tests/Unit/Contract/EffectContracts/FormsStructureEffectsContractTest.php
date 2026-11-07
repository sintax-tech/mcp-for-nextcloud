<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

require_once __DIR__ . '/../../Modules/Forms/StructureTestModule.php';

use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Modules\Forms\Integration\StructureCapabilities;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Modules\Forms\StructureFixture;
use OCA\Mcp\Tests\Unit\Modules\Forms\StructureHarness;
use OCA\Mcp\Tests\Unit\Modules\Forms\StructureTestModule;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Effect contracts of the seven Forms structure tools (F01–F03): plan, strict confirm, PlanChanged, grant/Guests refusals
 * and the observed effect, frozen through the endpoint behind the real structure flow over {@see StructureFixture}.
 */
final class FormsStructureEffectsContractTest extends TestCase {
	/** The native Forms state every request of the test reads and changes. */
	private StructureFixture $forms;
	/** The endpoint the flows go through. */
	private EffectsGate $gate;

	/** @return void builds the native state, the endpoint and the supported structure capability */
	protected function setUp(): void {
		$this->forms = new StructureFixture();
		$this->gate = new EffectsGate($this);
		$this->gate->serve(fn (): array => [StructureTestModule::build($this, $this->forms, new PlanState($this->gate->config()))],
			function (): CapabilityRegistry {
				$apps = $this->createMock(IAppManager::class);
				$apps->method('getAppVersion')->willReturn('5.4.1');
				$apps->method('isEnabledForAnyone')->willReturn(true);
				return new CapabilityRegistry([new StructureCapabilities($apps, 35, static fn (): bool => true)], $this->createMock(LoggerInterface::class));
			});
	}

	/** @return void forms_add_question inserting a grid question at position 2 */
	public function testAddingAQuestion(): void {
		$this->runFlow('add-question', 'forms_add_question', function (): void { $this->forms->questions[3]->data['text'] = 'Renamed by another editor'; });
		$this->assertSame(['questionType' => 'radio'], end($this->forms->questions)->data['extraSettings']);
	}

	/** @return void forms_reorder_questions with the complete active set */
	public function testReorderingQuestions(): void {
		$this->runFlow('reorder-questions', 'forms_reorder_questions', function (): void { $this->forms->questions[2]->data['order'] = 9; });
		$this->assertSame(1, $this->forms->questions[4]->data['order']);
	}

	/** @return void forms_add_option appending two choices */
	public function testAddingOptions(): void {
		$this->runFlow('add-option', 'forms_add_option', function (): void { $this->forms->option(12, 3, 3, 'Other editor', 'choice'); });
		$this->assertSame('choice', end($this->forms->options)->data['optionType']);
	}

	/** @return void forms_reorder_options with the complete choice partition */
	public function testReorderingOptions(): void {
		$this->runFlow('reorder-options', 'forms_reorder_options', function (): void { $this->forms->options[10]->data['text'] = 'Renamed'; });
		$this->assertSame(1, $this->forms->options[11]->data['order']);
	}

	/** @return void forms_delete_question on a file question (logical delete, native job, files not inspected) */
	public function testDeletingAQuestion(): void {
		// After the confirmed delete, the refusal steps target the last active question, which still exists.
		$this->runFlow('delete-question', 'forms_delete_question', function (): void { $this->forms->questions[4]->data['text'] = 'Renamed'; },
			fn (): array => ['form_id' => 1, 'question_id' => $this->lastActive()]);
		$this->assertSame(0, $this->forms->questions[4]->data['order']);
	}

	/** @return void forms_delete_option and the native renumbering */
	public function testDeletingAnOption(): void {
		// After the confirmed delete, the refusal steps target the first remaining option of the same question.
		$this->runFlow('delete-option', 'forms_delete_option', function (): void { $this->forms->options[11]->data['text'] = 'Renamed'; },
			fn (): array => ['form_id' => 1, 'question_id' => 3, 'option_id' => $this->forms->findByQuestion(3)[0]->data['id']]);
		$this->assertArrayNotHasKey(10, $this->forms->options);
	}

	/** @return void forms_change_question_type clearing the confirmation-email selection */
	public function testChangingAQuestionType(): void {
		$this->runFlow('change-question-type', 'forms_change_question_type', function (): void { $this->forms->questions[2]->data['text'] = 'Renamed'; });
		$this->assertSame('long', $this->forms->questions[2]->data['type']);
		$this->assertNull($this->forms->form->fields['confirmationEmailQuestionId']);
	}

	/** @return int the active question with the highest order */
	private function lastActive(): int {
		$active = $this->forms->findByForm(1);
		return end($active)->data['id'];
	}

	/**
	 * @param string $name flow name, part of the effect file name
	 * @param string $tool tool under contract
	 * @param \Closure(): void $change concurrent change made by another editor after the plan
	 * @param \Closure(): array<string,mixed>|null $arguments arguments as of now, for a delete whose target disappears
	 * @return void
	 */
	private function runFlow(string $name, string $tool, \Closure $change, ?\Closure $arguments = null): void {
		$forms = $this->forms;
		$flow = (new CriticalFlow($name, $tool, 'forms', 'edit', $arguments ?? StructureHarness::arguments()[$tool],
			static fn (): array => ['questions' => array_map(static fn ($q): array => $q->data, $forms->questions),
				'options' => array_map(static fn ($o): array => $o->data, $forms->options), 'calls' => $forms->calls,
				'confirmationEmailQuestionId' => $forms->form->fields['confirmationEmailQuestionId'], 'lastUpdated' => $forms->form->fields['lastUpdated']]))
			->changing($change)
			->withoutVisibility('Forms live in the Forms database, not in Files: no system tag or excluded folder can hide a form from MCP');
		(new FlowRunner($this, $this->gate, 'forms'))->run($flow);
		$this->assertCount(1, $forms->calls, 'exactly one native call for the whole flow');
	}
}
