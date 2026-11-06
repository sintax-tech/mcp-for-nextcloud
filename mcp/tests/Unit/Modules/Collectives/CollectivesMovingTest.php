<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * collectives_move (C03) through the real module over the native doubles: inside one collective only, never the
 * landing page or into its own subtree, index only with a new parent, one attempt, and a receipt of parent, title,
 * slug and position read back from the tree.
 */
final class CollectivesMovingTest extends TestCase {
	private CollectivesRig $rig;

	/** @return void a fresh scene and module */
	protected function setUp(): void {
		$this->rig = new CollectivesRig($this);
	}

	/**
	 * @param array<string, mixed> $arguments arguments
	 * @return array<string, mixed> the plan
	 */
	private function plan(array $arguments): array {
		return $this->rig->module()->preview('collectives_move', ['collective_id' => 7] + $arguments, 'alice');
	}

	/**
	 * @param array<string, mixed> $arguments arguments
	 * @return array<string, mixed> the receipt of the confirmed plan
	 */
	private function move(array $arguments): array {
		$plan = $this->plan($arguments);
		$result = $this->rig->module()->call('collectives_move', ['collective_id' => 7] + $arguments + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param array<string, mixed> $arguments arguments
	 * @return string the refusal of the plan
	 */
	private function refusal(array $arguments): string {
		try {
			$this->plan($arguments);
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('not refused');
	}

	/** @return void a leaf moves under another parent at the asked position; both orders and the slug are read back */
	public function testMovesUnderANewParentAtAPosition(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$team = CollectivesScene::$ids['Team/Readme.md'];
		$plan = $this->plan(['page_id' => $guide, 'parent_id' => $team, 'index' => 0]);
		$this->assertSame(['parent_id' => $team, 'parent_title' => 'Team', 'title' => 'Guide', 'position' => 0], $plan['to']);
		$this->assertSame(CollectivesScene::$ids['Readme.md'], $plan['from']['parent_id']);
		$this->assertNotContains('move', CollectivesScene::$calls);
		$receipt = $this->move(['page_id' => $guide, 'parent_id' => $team, 'index' => 0]);
		$this->assertSame('applied', $receipt['status']);
		$this->assertSame(['parent_id' => $team, 'title' => 'Guide', 'position' => 0], $receipt['observed']);
		$this->assertSame('guide', $receipt['page']['slug']);
		$this->assertArrayHasKey(CollectivesScene::ROOT . '/Team/Guide.md', CollectivesScene::$tree->nodes);
		$this->assertNotContains($guide, CollectivesScene::$orders[CollectivesScene::$ids['Readme.md']]);
		$this->assertSame(1, count(array_keys(CollectivesScene::$calls, 'move', true)));
	}

	/** @return void a rename keeps the parent; moving under a leaf says it becomes a folder */
	public function testRenamesAndMovesUnderALeaf(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$receipt = $this->move(['page_id' => $guide, 'title' => 'Manual']);
		$this->assertSame('applied', $receipt['status']);
		$this->assertSame('manual', $receipt['page']['slug']);
		$onboarding = CollectivesScene::$ids['Team/Onboarding.md'];
		$plan = $this->plan(['page_id' => $onboarding, 'parent_id' => $guide]);
		$this->assertTrue($plan['parent_becomes_folder']);
		$this->assertSame('applied', $this->move(['page_id' => $onboarding, 'parent_id' => $guide])['status']);
		$this->assertArrayHasKey(CollectivesScene::ROOT . '/Manual/Readme.md', CollectivesScene::$tree->nodes);
	}

	/** @return void landing page, cycles, unknown or template parents and reordering under the same parent are refused before anything moves */
	public function testRefusesWhatCollectivesWouldBreakOrNotDo(): void {
		$team = CollectivesScene::$ids['Team/Readme.md'];
		$this->assertStringContainsString('landing page', $this->refusal(['page_id' => CollectivesScene::$ids['Readme.md'], 'parent_id' => $team]));
		$this->assertStringContainsString('under itself', $this->refusal(['page_id' => $team, 'parent_id' => $team]));
		$this->assertStringContainsString('under itself', $this->refusal(['page_id' => $team, 'parent_id' => CollectivesScene::$ids['Team/Onboarding.md']]));
		$notFound = $this->refusal(['page_id' => $team, 'parent_id' => 4242]);
		$this->assertSame($notFound, $this->refusal(['page_id' => $team, 'parent_id' => CollectivesScene::$ids['.templates/Template.md']]));
		$this->assertSame($notFound, $this->refusal(['page_id' => CollectivesScene::$ids['.templates/Template.md'], 'parent_id' => $team]));
		$this->assertSame($notFound, $this->refusalIn(8, $team), 'another collective');
		$this->assertStringContainsString('reordering', $this->refusal(['page_id' => CollectivesScene::$ids['Guide.md'], 'index' => 1]));
		$this->assertNotContains('move', CollectivesScene::$calls);
	}

	/**
	 * @param int $cid another collective
	 * @param int $pageId a page of this one
	 * @return string the refusal of moving it there
	 */
	private function refusalIn(int $cid, int $pageId): string {
		try {
			$this->rig->module()->preview('collectives_move', ['collective_id' => $cid, 'page_id' => $pageId], 'alice');
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('another collective was accepted');
	}

	/** @return void moving an index page carries its folder: a hidden or out-of-scope node below it refuses the move */
	public function testEverythingTheMoveCarriesMustBeChangeable(): void {
		$team = CollectivesScene::$ids['Team/Readme.md'];
		$guide = CollectivesScene::$ids['Guide.md'];
		$args = ['page_id' => $team, 'parent_id' => $guide];
		foreach (['hidden' => 'hidden', 'out of scope' => 'outOfScope'] as $case => $switch) {
			$this->rig->{$switch} = [CollectivesScene::$ids['Team/Onboarding.md']];
			$message = $this->refusal($args);
			$this->assertStringContainsString('cannot change through MCP', $message, $case);
			$this->assertStringNotContainsString('Onboarding', $message, $case . ': the refusal names nothing');
			$this->rig->{$switch} = [];
		}
		$attachment = CollectivesScene::$tree->addFile(CollectivesScene::ROOT . '/.attachments.' . $guide . '/secret.png', 'x', 'image/png');
		$this->rig->hidden = [$attachment];
		$this->assertStringContainsString('cannot change through MCP', $this->refusal(['page_id' => $guide, 'parent_id' => $team]), 'a hidden attachment of a leaf');
		$this->assertNotContains('move', CollectivesScene::$calls);
	}

	/** @return void a node added below the page between plan and confirmation is part of the approved state: nothing moves */
	public function testANodeAddedBelowThePageChangesThePlan(): void {
		$args = ['collective_id' => 7, 'page_id' => CollectivesScene::$ids['Team/Readme.md'], 'parent_id' => CollectivesScene::$ids['Guide.md']];
		$plan = $this->plan($args);
		CollectivesScene::$tree->addFile(CollectivesScene::ROOT . '/Team/.attachments.' . CollectivesScene::$ids['Team/Onboarding.md'] . '/a.png', 'x', 'image/png');
		$this->expectException(PlanChanged::class);
		try {
			$this->rig->module()->call('collectives_move', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		} finally {
			$this->assertNotContains('move', CollectivesScene::$calls);
		}
	}

	/** @return void the same parent and title is a plan that changes nothing and a confirmation that attempts nothing */
	public function testNoChangeIsNotAttempted(): void {
		$plan = $this->plan(['page_id' => CollectivesScene::$ids['Guide.md']]);
		$this->assertFalse($plan['changes']);
		$this->assertSame('not_attempted', $this->move(['page_id' => CollectivesScene::$ids['Guide.md']])['status']);
		$this->assertNotContains('move', CollectivesScene::$calls);
	}

	/** @return void a revoked grant or a changed tree between plan and confirmation moves nothing */
	public function testRevocationAndChangedTreeMoveNothing(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$team = CollectivesScene::$ids['Team/Readme.md'];
		$args = ['collective_id' => 7, 'page_id' => $guide, 'parent_id' => $team];
		$plan = $this->plan($args);
		CollectivesScene::$tree->addFile(CollectivesScene::ROOT . '/Team/Late.md', '', 'text/markdown');
		try {
			$this->rig->module()->call('collectives_move', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
			$this->fail('moved under a parent whose subpages changed');
		} catch (PlanChanged $e) {
			$plan = $e->plan;
		}
		$this->rig->grants = ['read'];
		$this->expectException(ToolFailure::class);
		try {
			$this->rig->module()->call('collectives_move', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		} finally {
			$this->assertNotContains('move', CollectivesScene::$calls);
		}
	}

	/** @return void a native failure after the file moved is reported from what is read back, and not retried */
	public function testPartialFailureIsReportedNotRetried(): void {
		CollectivesScene::$failMove = true;
		$receipt = $this->move(['page_id' => CollectivesScene::$ids['Guide.md'], 'parent_id' => CollectivesScene::$ids['Team/Readme.md'], 'index' => 0]);
		$this->assertSame('partial', $receipt['status']);
		$this->assertArrayHasKey('native_error', $receipt);
		$this->assertSame(CollectivesScene::$ids['Team/Readme.md'], $receipt['observed']['parent_id']);
		$this->assertNull($receipt['observed']['position']);
		$this->assertSame(1, count(array_keys(CollectivesScene::$calls, 'move', true)));
	}
}
