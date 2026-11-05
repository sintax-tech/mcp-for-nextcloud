<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use OCA\Mcp\Modules\Forms\Creation\CreateFormContext;
use OCA\Mcp\Modules\Forms\Creation\CreateFormFlow;
use OCA\Mcp\Modules\Forms\Creation\CreateFormPlan;
use OCA\Mcp\Modules\Forms\Creation\CreateFormRequest;
use OCA\Mcp\Modules\Forms\Creation\CreateFormWriter;
use OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory;
use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Modules\Forms\Creation\CreateFormHandler;
use OCA\Mcp\Modules\Forms\Reading\ListFormsHandler;
use OCA\Mcp\Modules\Forms\Reading\ReadFormHandler;
use OCA\Mcp\Modules\Forms\Reading\ListSubmissionsHandler;
use OCA\Mcp\Modules\Forms\Reading\ReadSubmissionHandler;
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
