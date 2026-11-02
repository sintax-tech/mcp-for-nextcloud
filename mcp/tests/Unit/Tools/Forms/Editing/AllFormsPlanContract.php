<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

require_once __DIR__.'/EditingFixture.php';

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use OCA\Mcp\Tools\Forms\Creation\{CreateFormContext,CreateFormFlow,CreateFormPlan,CreateFormRequest,CreateFormWriter,FormsCreateNativeFactory};
use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Tools\Forms\Handler\{CreateFormHandler,ListFormsHandler,ReadFormHandler,ListSubmissionsHandler,ReadSubmissionHandler};
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tools\Forms\Editing\{EditFlow,EditNativeFactory,EditState,EditPlan,EditWriter};
use OCA\Mcp\Tools\Forms\Handler\{EditQuestionHandler,EditOptionHandler};
use PHPUnit\Framework\TestCase;

/** Exercises the actual module's preview and renderer through the common readability contract. */
final class AllFormsPlanContract extends TestCase {
	use AssertsReadablePlans;
	/** Covers every write, full option text, hostile fields and the constant native-effects warning.
	 * @return void
	 */
	public function check(): void {
		// No domain data is fetched in the unit renderer context; this service validates inert settings only.
		$native = new class {
			/** Admits the isolated settings fixture.
			 * @param array $settings Supported settings.
			 * @param string $type Supported type.
			 * @return bool Validation result.
			 */
			public function areExtraSettingsValid(array $settings, string $type): bool { return true; }
		};
		$context = new CreateFormContext('5.4.1', $native, $native, $native, $native, $native, $native, $native);
		$factory = $this->createMock(FormsCreateNativeFactory::class); $factory->method('open')->willReturn($context);
		$writer = $this->createMock(CreateFormWriter::class); $writer->expects(self::never())->method('write');
		$flow = new CreateFormFlow($factory, new CreateFormPlan(new PlanState((new InMemoryConfig())->mock($this))), $writer);
		$fixture = new EditingFixture();
		$editFactory = $this->createMock(EditNativeFactory::class); $editFactory->method('open')->willReturn($fixture->context());
		$editWriter = $this->createMock(EditWriter::class); $editWriter->expects(self::never())->method('write');
		$edits = new EditFlow($editFactory, new EditState(), new EditPlan(new PlanState((new InMemoryConfig())->mock($this))), $editWriter);
		$module = new FormsModule((new \ReflectionClass(ListFormsHandler::class))->newInstanceWithoutConstructor(), (new \ReflectionClass(ReadFormHandler::class))->newInstanceWithoutConstructor(), (new \ReflectionClass(ListSubmissionsHandler::class))->newInstanceWithoutConstructor(), (new \ReflectionClass(ReadSubmissionHandler::class))->newInstanceWithoutConstructor(), new CreateFormHandler($flow), new EditQuestionHandler($edits), new EditOptionHandler($edits));
		$args = ['title' => 'New form', 'description' => 'Description', 'questions' => [['type' => 'short', 'text' => 'Email', 'validation' => 'email'], ['type' => 'dropdown', 'text' => 'Choice', 'options' => ['A', str_repeat('z', 1024)]]]];
		$plan = $module->preview('forms_create_form', $args, 'alice');
		$this->assertEveryWriteToolHasAReadablePlan($module, ['forms_create_form' => $plan,
			'forms_update_question' => $module->preview('forms_update_question', ['form_id'=>1,'question_id'=>2,'changes'=>['text'=>str_repeat('q',2048)]], 'alice'),
			'forms_update_option' => $module->preview('forms_update_option', ['form_id'=>1,'question_id'=>2,'option_id'=>3,'changes'=>['text'=>str_repeat('o',1024)]], 'alice')]);
		self::assertStringContainsString(str_repeat('z', 1024), $module->renderPlan('forms_create_form', $plan));
		self::assertSame(CreateFormRequest::of($args), $plan['after']);
	}
}
