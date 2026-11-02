<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

require_once __DIR__ . '/../../Tools/Forms/Creation/CreateNativeState.php';
require_once __DIR__ . '/../../Tools/Forms/Editing/EditingFixture.php';

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\Forms\Creation\CreateNativeState;
use OCA\Mcp\Tests\Unit\Tools\Forms\Editing\EditingFixture;
use OCA\Mcp\Modules\Forms\Creation\CreateFormContext;
use OCA\Mcp\Modules\Forms\Creation\CreateFormFlow;
use OCA\Mcp\Modules\Forms\Creation\CreateFormPlan;
use OCA\Mcp\Modules\Forms\Integration\CreateFormReadback;
use OCA\Mcp\Modules\Forms\Creation\CreateFormWriter;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory;
use OCA\Mcp\Modules\Forms\Creation\UploadFolderPrecheck;
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
use OCP\IURLGenerator;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Forms module (RE-01), frozen through the endpoint behind the real flows, plans and writers:
 * - creating a form with one question over {@see CreateNativeState} (the created form, its questions and the native
 *   stages it received), with the real readback, upload precheck and throttle;
 * - changing the text of a question over {@see EditingFixture} (the stored question and the native patches).
 *
 * Both native factories are doubles that hand out the fixture's graph, as every Forms test does: the real ones build
 * Forms' own ApiController and services from the Forms app container, which unit tests cannot provide. Their admission
 * (session, Guests, renewed policy, canCreateForms) is therefore not part of this contract; the endpoint's grant and
 * Guests gates in front of them are.
 */
final class FormsEffectsContractTest extends TestCase {
	private CreateNativeState $forms;
	private EffectsGate $gate;
	/** Forms version the native graph reports. */
	private string $version = '5.4.0';

	/** @return void builds the native Forms state and the endpoint */
	protected function setUp(): void {
		$this->forms = new CreateNativeState();
		$this->gate = new EffectsGate($this);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return FormsModule the module of one request: the real creation flow and writer over a fresh native graph */
	private function module(): FormsModule {
		$factory = $this->createMock(FormsCreateNativeFactory::class);
		$factory->method('open')->willReturnCallback(fn (): CreateFormContext => $this->context());
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $args): string => 'https://cloud.example/apps/forms/' . $args['hash'] . '/' . $args['view']);
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$writer = new CreateFormWriter($factory, new CreateFormReadback($urls), new UploadFolderPrecheck(),
			new CreateNativeThrottle($this->createMock(IThrottler::class), $request));
		$flow = new CreateFormFlow($factory, new CreateFormPlan(new PlanState($this->gate->config())), $writer);
		$unused = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		$edits = $this->getMockBuilder(EditFlow::class)->disableOriginalConstructor()->getMock();
		return new FormsModule($unused(ListFormsHandler::class), $unused(ReadFormHandler::class), $unused(ListSubmissionsHandler::class),
			$unused(ReadSubmissionHandler::class), new CreateFormHandler($flow), new EditQuestionHandler($edits), new EditOptionHandler($edits));
	}

	/**
	 * The native graph of one operation: the fixture for every port, the form service extended with the settings
	 * validator the preflight asks before any sink.
	 *
	 * @return CreateFormContext the graph
	 */
	private function context(): CreateFormContext {
		$service = new class($this->forms) {
			/** @param CreateNativeState $state the native state the reads come from */
			public function __construct(private CreateNativeState $state) {
			}

			/**
			 * @param array<string, mixed> $settings settings of a question
			 * @param string $type question type
			 * @return bool always valid: native validation is covered by the source contracts
			 */
			public function areExtraSettingsValid(array $settings, string $type): bool {
				return true;
			}

			/**
			 * @param int $id form id
			 * @param string $permission required native permission
			 * @return \OCA\Forms\Db\Form the form
			 */
			public function getFormIfAllowed(int $id, string $permission): \OCA\Forms\Db\Form {
				return $this->state->getFormIfAllowed($id, $permission);
			}

			/**
			 * @param int $id form id
			 * @return array<mixed> its shares
			 */
			public function getShares(int $id): array {
				return $this->state->getShares($id);
			}
		};
		$s = $this->forms;
		return new CreateFormContext($this->version, $s, $s, $service, $s, $s, $s, $s);
	}

	/** @return void forms_create_form with one question: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testCreatingAForm(): void {
		$flow = (new CriticalFlow('create-form', 'forms_create_form', 'forms', 'create',
			['title' => 'Inscrição', 'description' => 'Evento de outubro', 'questions' => [['type' => 'short', 'text' => 'Nome']]],
			fn (): array => $this->state()))
			// Forms was upgraded between the plan and the confirmation: the plan named the native version it would run on.
			->changing(function (): void {
				$this->version = '5.4.1';
			})
			->withoutVisibility('Forms live in the Forms database, not in Files: no system tag or excluded folder can hide a form from MCP');
		(new FlowRunner($this, $this->gate, 'forms'))->run($flow);
	}

	/** @return void forms_update_question changing a text: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testChangingTheTextOfAQuestion(): void {
		$edited = new EditingFixture();
		$this->gate->serve(fn (): array => [$this->editModule($edited)]);
		$flow = (new CriticalFlow('update-question', 'forms_update_question', 'forms', 'edit',
			['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => 'E-mail para contato']],
			// The form's native lastUpdated is part of the effect: Forms stamps it at the write, and the receipt quotes it.
			fn (): array => ['question' => $edited->question->data, 'patches' => $edited->calls, 'lastUpdated' => $edited->form->fields['lastUpdated']]))
			// Another editor rewrote the description of the question after the plan.
			->changing(function () use ($edited): void {
				$edited->question->data['description'] = 'Usado só para o recibo';
			})
			->withoutVisibility('Forms live in the Forms database, not in Files: no system tag or excluded folder can hide a form from MCP');
		(new FlowRunner($this, $this->gate, 'forms'))->run($flow);
	}

	/**
	 * The module of one request for the edit flow: the real edit flow and writer over a fresh native graph of the
	 * {@see EditingFixture}, the creation flow a double.
	 *
	 * @param EditingFixture $edited the native state the edit reads and patches
	 * @return FormsModule the module
	 */
	private function editModule(EditingFixture $edited): FormsModule {
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

	/** @return array{form: array<string, mixed>|null, questions: array<int, array<string, mixed>>, stages: list<string>} what Forms holds and every native stage */
	private function state(): array {
		return ['form' => $this->forms->form?->data, 'questions' => $this->forms->questions, 'stages' => $this->forms->calls];
	}
}
