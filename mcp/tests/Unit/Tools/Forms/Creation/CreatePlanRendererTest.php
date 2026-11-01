<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use OCA\Mcp\Tools\Forms\Creation\{CreateFormContext,CreateFormFlow,CreateFormPlan,CreateFormRequest,CreateFormWriter,FormsCreateNativeFactory};
use OCA\Mcp\Tools\Forms\FormsModule;
use OCA\Mcp\Tools\Forms\Handler\{CreateFormHandler,ListFormsHandler,ReadFormHandler,ListSubmissionsHandler,ReadSubmissionHandler};
use OCA\Mcp\Mcp\Confirmation\PlanState;
use PHPUnit\Framework\TestCase;

/** Exercises the actual module's preview and renderer through the common readability contract. */
final class CreatePlanRendererTest extends TestCase {
	use AssertsReadablePlans;
	/** Covers every write, full option text, hostile fields and the constant native-effects warning.
	 * @return void
	 */
	public function testCreationHasACompleteReadableInertPlan(): void {
		(new \OCA\Mcp\Tests\Unit\Tools\Forms\Editing\AllFormsPlanContract('check'))->check();
	}
}
