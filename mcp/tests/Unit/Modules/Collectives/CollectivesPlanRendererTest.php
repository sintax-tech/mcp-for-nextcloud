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
			'collectives_create_collective' => $module->preview('collectives_create_collective', ['name' => 'Projetos', 'emoji' => '📘'], 'alice'),
			'collectives_add_member' => $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina'], 'alice'),
			'collectives_set_member_level' => $module->preview('collectives_set_member_level', ['collective_id' => 7, 'member_id' => 'm-carol', 'level' => 'moderator'], 'alice'),
			'collectives_remove_member' => $module->preview('collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-carol'], 'alice'),
		]);
	}

	/** @return void a hostile collective name stays inert text, and every notice the approval covers is in the rendered plan */
	public function testCollectiveCreationPlanKeepsHostileNamesInertAndShowsEveryNotice(): void {
		$rig = new CollectivesRig($this);
		$module = $rig->module();
		foreach (['**bold** [x](https://evil.example) <img src=x>', "\u{202E}Projetos", str_repeat('ação', 21)] as $name) {
			$plan = $module->preview('collectives_create_collective', ['name' => $name], 'alice');
			$text = (string)$module->renderPlan('collectives_create_collective', $plan);
			$this->assertStringNotContainsString('<img', $text);
			$this->assertStringNotContainsString('](https://evil.example)', $text);
			$this->assertSame(count($plan['notices']), substr_count($text, "\n- "), 'one rendered line per notice');
			foreach ($plan['notices'] as $notice) {
				if (!str_contains($notice, $plan['name'])) {
					$this->assertStringContainsString($notice, $text);
				}
			}
			$this->assertCount($plan['name'] !== $name ? 8 : 7, $plan['notices'],
				'stored-as when it changed, team, folder, nobody invited, suffix, team folder, adoption, adoption after failure');
		}
	}
}
