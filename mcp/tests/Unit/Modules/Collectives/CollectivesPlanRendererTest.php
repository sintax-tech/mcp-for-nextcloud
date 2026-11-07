<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use PHPUnit\Framework\TestCase;

/** The readability contract of the Collectives write plans, with plans made by the real module over the native doubles. */
final class CollectivesPlanRendererTest extends TestCase {
	use AssertsReadablePlans;

	/** @return void every Collectives write renders its own plan text, and text typed by somebody else stays inert */
	public function testEveryWriteHasAReadablePlan(): void {
		$rig = new CollectivesRig($this);
		$module = $rig->module();
		$guide = CollectivesScene::$ids['Guide.md'];
		$this->assertEveryWriteToolHasAReadablePlan($module, [
			'collectives_create' => $module->preview('collectives_create', ['collective_id' => 7, 'parent_id' => $guide, 'title' => 'Setup'], 'alice'),
			'collectives_edit' => $module->preview('collectives_edit', ['collective_id' => 7, 'page_id' => $guide, 'append' => 'More'], 'alice'),
			'collectives_move' => $module->preview('collectives_move', ['collective_id' => 7, 'page_id' => $guide,
				'parent_id' => CollectivesScene::$ids['Team/Readme.md'], 'title' => 'Manual'], 'alice'),
		]);
	}
}
