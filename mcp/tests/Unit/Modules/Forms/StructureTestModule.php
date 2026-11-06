<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

require_once __DIR__ . '/StructureHarness.php';

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Modules\Forms\Tools;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The real FormsModule whose container serves the seven structure tools over one StructureFixture. */
final class StructureTestModule {
	/** Tool class, preview class and handler class of each structure tool, in catalog order. */
	private const TOOLS = [
		'forms_add_question' => [Tools\AddQuestion::class, 'Adding\\AddQuestion'],
		'forms_reorder_questions' => [Tools\ReorderQuestions::class, 'Reordering\\ReorderQuestions'],
		'forms_add_option' => [Tools\AddOptions::class, 'Adding\\AddOption'],
		'forms_reorder_options' => [Tools\ReorderOptions::class, 'Reordering\\ReorderOptions'],
		'forms_delete_question' => [Tools\DeleteQuestion::class, 'Deletion\\DeleteQuestion'],
		'forms_delete_option' => [Tools\DeleteOption::class, 'Deletion\\DeleteOption'],
		'forms_change_question_type' => [Tools\ChangeQuestionType::class, 'Editing\\ChangeQuestionType'],
	];

	private function __construct() {
	}

	/**
	 * @param TestCase $test owner of the mocks
	 * @param StructureFixture $f native fixture shared by every request of the test
	 * @param PlanState $signer plan_state signer of the endpoint
	 * @return FormsModule the module
	 */
	public static function build(TestCase $test, StructureFixture $f, PlanState $signer): FormsModule {
		$flow = StructureHarness::flow($test, $f, null, $signer);
		$operations = StructureHarness::operations();
		$services = [];
		foreach (self::TOOLS as $name => [$tool, $work]) {
			$preview = 'OCA\\Mcp\\Modules\\Forms\\' . $work . 'Preview';
			$handler = 'OCA\\Mcp\\Modules\\Forms\\' . $work . 'Handler';
			$services[$tool] = new $tool(new $handler($flow, $operations[$name]), new $preview($flow, $operations[$name]));
		}
		return new FormsModule(new class($services) implements ContainerInterface {
			/** @param array<string,object> $services tools by class */
			public function __construct(private array $services) {
			}

			/** {@inheritDoc} */
			public function get(string $id): mixed {
				return $this->services[$id] ?? throw new \LogicException($id);
			}

			/** {@inheritDoc} */
			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		});
	}
}
