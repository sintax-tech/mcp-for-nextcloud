<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

require_once __DIR__ . '/../../Tools/Forms/Editing/EditingFixture.php';

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\Forms\Editing\EditingFixture;
use OCA\Mcp\Modules\Forms\Creation\CreateFormFlow;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Editing\EditFlow;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Editing\EditPlan;
use OCA\Mcp\Modules\Forms\Integration\EditState;
use OCA\Mcp\Modules\Forms\Editing\EditWriter;
use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Modules\Forms\Creation\CreateFormHandler;
use OCA\Mcp\Modules\Forms\Editing\EditOptionHandler;
use OCA\Mcp\Modules\Forms\Editing\EditQuestionHandler;
use OCA\Mcp\Modules\Forms\Reading\ListFormsHandler;
use OCA\Mcp\Modules\Forms\Reading\ListSubmissionsHandler;
use OCA\Mcp\Modules\Forms\Reading\ReadFormHandler;
use OCA\Mcp\Modules\Forms\Reading\ReadSubmissionHandler;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of forms_update_option (RE-01): renaming one option of a question, frozen through the endpoint behind
 * the real edit flow, plan and writer over {@see EditingFixture} (the stored option, the native patches and the form's
 * native lastUpdated stamp).
 *
 * The native factory is a double that hands out the fixture's graph, as in every Forms test: its real open() builds
 * Forms' own ApiController and services, which unit tests cannot provide (see {@see FormsNativeAdmissionContractTest}
 * for what of its admission can be frozen). The endpoint's grant and Guests gates in front of it are part of this
 * contract.
 */
final class FormsOptionEffectsContractTest extends TestCase {
	/** The new text of the option. */
	private const TEXT = 'Opção revisada';

	/** The native Forms state the edit reads and patches. */
	private EditingFixture $edited;
	/** The endpoint the flow goes through. */
	private EffectsGate $gate;

	/** @return void builds the native Forms state of the edit and the endpoint */
	protected function setUp(): void {
		$this->edited = new EditingFixture();
		// A plain question: the option path never depends on the confirmation-email selection of its question.
		$this->edited->question->data['extraSettings'] = [];
		$this->gate = new EffectsGate($this);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return void forms_update_option changing a text: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testRenamingAnOption(): void {
		$edited = $this->edited;
		$flow = (new CriticalFlow('update-option', 'forms_update_option', 'forms', 'edit',
			['form_id' => 1, 'question_id' => 2, 'option_id' => 3, 'changes' => ['text' => self::TEXT]],
			// The form's native lastUpdated is part of the effect: Forms stamps it at the write, and the receipt quotes it.
			fn (): array => ['option' => $edited->option->data, 'question' => $edited->question->data, 'patches' => $edited->calls,
				'lastUpdated' => $edited->form->fields['lastUpdated'],
				'confirmationEmailQuestionId' => $edited->form->fields['confirmationEmailQuestionId']]))
			// Another editor renamed the option after the plan: the plan showed the old text as "before".
			->changing(function () use ($edited): void {
				$edited->option->data['text'] = 'Escolha de outro editor';
			})
			->withoutVisibility('Forms live in the Forms database, not in Files: no system tag or excluded folder can hide a form from MCP');
		(new FlowRunner($this, $this->gate, 'forms'))->run($flow);
		$this->assertTheConfirmedPatchIsThePlan();
	}

	/**
	 * The key effect facts, outside the golden: the one native patch of the option carried only the planned text, the
	 * option holds it, and nothing else of the question or of the form's selection changed.
	 *
	 * @return void
	 */
	private function assertTheConfirmedPatchIsThePlan(): void {
		$this->assertNotSame([], $this->edited->calls, 'the confirmed edit must reach the native option patch');
		$this->assertSame(['text' => self::TEXT], $this->edited->calls[0], 'the first native patch is exactly the planned text');
		$this->assertSame(self::TEXT, $this->edited->option->data['text']);
		$this->assertSame(['id' => 3, 'questionId' => 2, 'order' => 1, 'text' => self::TEXT, 'optionType' => 'choice'], $this->edited->option->data);
		$this->assertSame('Email', $this->edited->question->data['text'], 'an option edit never patches its question');
		$this->assertSame(2, $this->edited->form->fields['confirmationEmailQuestionId'], 'an option edit never clears the email selection');
	}

	/**
	 * The module of one request: the real edit flow and writer over a fresh native graph of the fixture, the creation
	 * flow and the reads doubles the option tool never reaches.
	 *
	 * @return FormsModule the module
	 */
	private function module(): FormsModule {
		$edited = $this->edited;
		$factory = $this->createMock(EditNativeFactory::class);
		$factory->method('open')->willReturnCallback(static fn (): EditContext => $edited->context());
		$plans = new EditPlan(new PlanState($this->gate->config()));
		$states = new EditState();
		$throttle = new CreateNativeThrottle($this->createMock(IThrottler::class), $this->createMock(IRequest::class));
		$edits = new EditFlow($factory, $states, $plans, new EditWriter($factory, $states, $plans, $throttle));
		$unused = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		$create = $this->getMockBuilder(CreateFormFlow::class)->disableOriginalConstructor()->getMock();
		return new FormsModule($unused(ListFormsHandler::class), $unused(ReadFormHandler::class), $unused(ListSubmissionsHandler::class),
			$unused(ReadSubmissionHandler::class), new CreateFormHandler($create), new EditQuestionHandler($edits), new EditOptionHandler($edits));
	}
}
