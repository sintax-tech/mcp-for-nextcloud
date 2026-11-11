<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

require_once __DIR__ . '/StructureFixture.php';

use OCA\Mcp\Modules\Forms\Adding\OptionAddition;
use OCA\Mcp\Modules\Forms\Adding\QuestionAddition;
use OCA\Mcp\Modules\Forms\Deletion\OptionDeletion;
use OCA\Mcp\Modules\Forms\Deletion\QuestionDeletion;
use OCA\Mcp\Modules\Forms\Editing\TypeChanges;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Integration\StructureTarget;
use OCA\Mcp\Modules\Forms\Reordering\OptionReorder;
use OCA\Mcp\Modules\Forms\Reordering\QuestionReorder;
use OCA\Mcp\Modules\Forms\Restructuring\StructureFlow;
use OCA\Mcp\Modules\Forms\Restructuring\StructureOperation;
use OCA\Mcp\Modules\Forms\Restructuring\StructurePlan;
use OCA\Mcp\Modules\Forms\Restructuring\StructureWriter;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

/** Builds the real structure flow over a StructureFixture, with a mocked admission factory. */
final class StructureHarness {
	/** Static test builders only: prevents instantiation. */
	private function __construct() {
	}

	/**
	 * @param TestCase $test owner of the mocks
	 * @param StructureFixture $f native fixture
	 * @param callable|null $admission called by every re-admission (pre-call and readback)
	 * @param PlanState|null $signer plan_state signer; a fresh in-memory one when null
	 * @return StructureFlow the flow
	 */
	public static function flow(TestCase $test, StructureFixture $f, ?callable $admission = null, ?PlanState $signer = null): StructureFlow {
		$factory = new class($f, $admission) extends EditNativeFactory {
			/** @param StructureFixture $f fixture @param callable|null $admission admission hook */
			public function __construct(private StructureFixture $f, private mixed $admission) {
			}

			/** {@inheritDoc} */
			public function open(string $uid, ?callable $unsupportedVersion = null): \OCA\Mcp\Modules\Forms\Editing\EditContext {
				return $this->f->context();
			}

			/** {@inheritDoc} */
			public function assertAllowed(string $uid, \OCA\Mcp\Modules\Forms\Editing\EditContext $context, ?callable $unsupportedVersion = null): void {
				if ($this->admission !== null) {
					($this->admission)();
				}
			}
		};
		$states = new StructureState(new StructureTarget());
		$plans = new StructurePlan($signer ?? new PlanState((new InMemoryConfig())->mock($test)));
		$throttle = new CreateNativeThrottle($test->getMockBuilder(\OCP\Security\Bruteforce\IThrottler::class)->getMock(),
			$test->getMockBuilder(\OCP\IRequest::class)->getMock());
		return new StructureFlow($factory, $states, $plans, new StructureWriter($factory, $states, $plans, $throttle));
	}

	/** @return array<string,StructureOperation> every structure operation, keyed by tool name */
	public static function operations(): array {
		$ops = [new QuestionAddition(), new QuestionReorder(), new OptionAddition(), new OptionReorder(),
			new QuestionDeletion(), new OptionDeletion(), new TypeChanges()];
		return array_combine(array_map(static fn (StructureOperation $op): string => $op->name(), $ops), $ops);
	}

	/** @return array<string,array<string,mixed>> valid arguments of each tool on the default fixture */
	public static function arguments(): array {
		return [
			'forms_add_question' => ['form_id' => 1, 'type' => 'grid', 'text' => 'Rate', 'position' => 2, 'grid_type' => 'radio'],
			'forms_reorder_questions' => ['form_id' => 1, 'question_ids' => [4, 2, 3]],
			'forms_add_option' => ['form_id' => 1, 'question_id' => 3, 'texts' => ['C', 'D'], 'option_type' => 'choice'],
			'forms_reorder_options' => ['form_id' => 1, 'question_id' => 3, 'option_ids' => [11, 10]],
			'forms_delete_question' => ['form_id' => 1, 'question_id' => 4],
			'forms_delete_option' => ['form_id' => 1, 'question_id' => 3, 'option_id' => 10],
			'forms_change_question_type' => ['form_id' => 1, 'question_id' => 2, 'type' => 'long', 'settings' => []],
		];
	}
}
