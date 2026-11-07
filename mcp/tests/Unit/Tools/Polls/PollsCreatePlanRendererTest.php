<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use OCA\Mcp\Modules\Polls\Creation\CreatePlan;
use PHPUnit\Framework\TestCase;

/** Original global readability checks, with the actual writing module and actual preview graph. */
final class PollsCreatePlanRendererTest extends TestCase {
	use AssertsReadablePlans;
	/**
	 * A write without a readable actual plan cannot enter the catalog.
	 * @return void
	 */
	public function testEveryPollsWriteHasAnInertReadablePlan(): void {
		$rig = new PollsCreateRig($this);
		$args = ['type'=>'textPoll','title'=>'Private <3','options'=>[['text'=>'a<b'],['text'=>'&amp;']]];
		$plan = $rig->base->module->preview(CreatePlan::TOOL,$args,'alice');
		$this->assertEveryWriteToolHasAReadablePlan($rig->base->module,[CreatePlan::TOOL=>$plan] + \OCA\Mcp\Tests\Unit\Modules\Polls\PollsMutationCases::plans($this));
		self::assertSame([],NativeCreateScene::$effects);
	}
	/**
	 * A real preview with hostile submitted text remains inert, including canonical and requested values.
	 * @return void
	 */
	public function testActualHostileTextStaysInert(): void {
		$rig = new PollsCreateRig($this);
		$args = ['type'=>'textPoll','title'=>'<b> [x](javascript:alert(1))','options'=>[['text'=>'` <b> [x](javascript:alert(1))']]];
		$plan = $rig->base->module->preview(CreatePlan::TOOL,$args,'alice');
		$this->assertHostileNameStaysInert($rig->base->module,CreatePlan::TOOL,$plan,$args['title']);
		$this->assertHostileNameStaysInert($rig->base->module,CreatePlan::TOOL,$plan,$args['options'][0]['text']);
		self::assertSame([],NativeCreateScene::$effects);
	}
}
