<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesMarkdownPolicy;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

/**
 * collectives_create and collectives_edit (C02) through the real module over the native doubles: plans that change
 * nothing, confirmations bound to the state they showed, the native edit permission read fresh, one attempt only,
 * and receipts made from what a new read shows.
 */
final class CollectivesWritingTest extends TestCase {
	private CollectivesRig $rig;

	/** @return void a fresh scene and module */
	protected function setUp(): void {
		$this->rig = new CollectivesRig($this);
	}

	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments arguments
	 * @return array<string, mixed> the plan
	 */
	private function plan(string $tool, array $arguments): array {
		return $this->rig->module()->preview($tool, $arguments, 'alice');
	}

	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments arguments, plan_state and etag included
	 * @return array<string, mixed> the decoded receipt
	 */
	private function confirm(string $tool, array $arguments): array {
		$result = $this->rig->module()->call($tool, $arguments + ['confirm' => true], 'alice');
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param callable(): mixed $call the call that must be refused
	 * @return string the refusal
	 */
	private function refused(callable $call): string {
		try {
			$call();
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('not refused');
	}

	/** @return array<string, string> page contents by relative path, to prove what was (not) written */
	private function contents(): array {
		$out = [];
		foreach (CollectivesScene::$tree->nodes as $path => $node) {
			if ($node['type'] === 'file' && str_starts_with($path, CollectivesScene::ROOT . '/')) {
				$out[substr($path, strlen(CollectivesScene::ROOT) + 1)] = $node['content'];
			}
		}
		return $out;
	}

	/** @return void a leaf parent: the plan says it becomes a folder; the confirmation creates once and reads it back */
	public function testCreatesUnderALeafParent(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$args = ['collective_id' => 7, 'parent_id' => $guide, 'title' => 'Setup'];
		$before = $this->contents();
		$plan = $this->plan('collectives_create', $args);
		$this->assertTrue($plan['parent_becomes_folder']);
		$this->assertSame('Guide', $plan['parent']['title']);
		$this->assertSame($before, $this->contents(), 'the plan wrote');
		$this->assertNotContains('create', CollectivesScene::$calls);
		$receipt = $this->confirm('collectives_create', $args + ['plan_state' => $plan['plan_state']]);
		$this->assertSame('applied', $receipt['status']);
		$this->assertSame('Setup', $receipt['page']['title']);
		$this->assertSame($guide, $receipt['page']['parent_id']);
		$this->assertArrayHasKey('Guide/Readme.md', $this->contents(), 'the leaf became a folder, id kept');
		$this->assertSame(1, count(array_keys(CollectivesScene::$calls, 'create', true)));
		$this->assertNotContains('getPagesFromFolder:forceIndex', CollectivesScene::$calls);
	}

	/** @return void a taken title is announced; 0 is the landing page; a template or a foreign id is not a parent */
	public function testCreatePlansTitlesAndParents(): void {
		$this->assertTrue($this->plan('collectives_create', ['collective_id' => 7, 'parent_id' => 0, 'title' => 'guide'])['title_taken']);
		$this->assertSame('Landing page', $this->plan('collectives_create', ['collective_id' => 7, 'parent_id' => 0, 'title' => 'New'])['parent']['title']);
		$notFound = $this->refused(fn () => $this->plan('collectives_create', ['collective_id' => 7, 'parent_id' => 4242, 'title' => 'X']));
		$this->assertSame($notFound, $this->refused(fn () => $this->plan('collectives_create',
			['collective_id' => 7, 'parent_id' => CollectivesScene::$ids['.templates/Template.md'], 'title' => 'X'])));
	}

	/** @return void a state other than the approved one writes nothing and answers the new plan */
	public function testCreateWithAStalePlanWritesNothing(): void {
		$args = ['collective_id' => 7, 'parent_id' => 0, 'title' => 'Fresh'];
		$plan = $this->plan('collectives_create', $args);
		try {
			$this->confirm('collectives_create', ['collective_id' => 7, 'parent_id' => 0, 'title' => 'Other'] + ['plan_state' => $plan['plan_state']]);
			$this->fail('another title was confirmed with this plan');
		} catch (PlanChanged $e) {
			$this->assertSame('Other', $e->plan['page_title']);
		}
		$this->assertNotContains('create', CollectivesScene::$calls);
	}

	/** @return void a member who may only read is refused even with the MCP grant; a revoked grant refuses the confirmation */
	public function testNativeAndMcpPermissionsAreReadAgain(): void {
		$args = ['collective_id' => 7, 'parent_id' => 0, 'title' => 'Fresh'];
		$plan = $this->plan('collectives_create', $args);
		CollectivesScene::$collectives[7]['canEdit'] = false;
		$this->assertSame('No access to this resource in Nextcloud.', $this->refused(fn () => $this->confirm('collectives_create', $args + ['plan_state' => $plan['plan_state']])));
		CollectivesScene::$collectives[7]['canEdit'] = true;
		$this->rig->grants = ['read'];
		$this->assertStringContainsString('not available', $this->refused(fn () => $this->confirm('collectives_create', $args + ['plan_state' => $plan['plan_state']])));
		$this->assertNotContains('create', CollectivesScene::$calls);
	}

	/** @return void an append is planned with its diff and etag, confirmed once and read back */
	public function testEditsByAppend(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$args = ['collective_id' => 7, 'page_id' => $guide, 'append' => 'More'];
		$plan = $this->plan('collectives_edit', $args);
		$this->assertSame("Guide text\n", $this->contents()['Guide.md']);
		$this->assertStringContainsString('+More', $plan['diff']);
		$receipt = $this->confirm('collectives_edit', $args + ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
		$this->assertSame('applied', $receipt['status']);
		$this->assertSame("Guide text\nMore\n", $this->contents()['Guide.md']);
		$this->assertSame(['write ' . CollectivesScene::ROOT . '/Guide.md'], CollectivesScene::$tree->ops);
	}

	/** @return void etag missing or stale, content changed under the same etag, and a non-unique passage write nothing */
	public function testEditRefusesWhatWasNotApproved(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$args = ['collective_id' => 7, 'page_id' => $guide, 'old' => 'Guide', 'new' => 'Manual'];
		$plan = $this->plan('collectives_edit', $args);
		$this->assertStringContainsString('etag', $this->refused(fn () => $this->confirm('collectives_edit', $args + ['plan_state' => $plan['plan_state']])));
		$this->assertStringContainsString('changed since', $this->refused(fn () => $this->confirm('collectives_edit', $args + ['etag' => 'old', 'plan_state' => $plan['plan_state']])));
		CollectivesScene::$tree->nodes[CollectivesScene::ROOT . '/Guide.md']['content'] = "Guide text\nGuide again\n";
		try {
			$this->confirm('collectives_edit', $args + ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
			$this->fail('a changed page was written');
		} catch (ToolFailure $e) {
			$this->assertStringNotContainsString('Manual', $this->contents()['Guide.md'], 'not unique: refused');
		} catch (PlanChanged) {
			$this->fail('the passage is no longer unique, which refuses before any plan');
		}
		$this->assertSame([], CollectivesScene::$tree->ops);
	}

	/** @return void the same etag with other bytes answers the new plan and writes nothing */
	public function testEditUnderAnUnchangedEtagButOtherContentAnswersTheNewPlan(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$args = ['collective_id' => 7, 'page_id' => $guide, 'append' => 'More'];
		$plan = $this->plan('collectives_edit', $args);
		CollectivesScene::$tree->nodes[CollectivesScene::ROOT . '/Guide.md']['content'] = "Other text\n";
		$this->expectException(PlanChanged::class);
		try {
			$this->confirm('collectives_edit', $args + ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
		} finally {
			$this->assertSame([], CollectivesScene::$tree->ops);
		}
	}

	/** @return void a failing write is reported as uncertain after one attempt, never retried */
	public function testFailedWriteIsUncertainAndNotRetried(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$args = ['collective_id' => 7, 'page_id' => $guide, 'content' => "New\n"];
		$plan = $this->plan('collectives_edit', $args);
		CollectivesScene::$tree->failWrite = true;
		$receipt = $this->confirm('collectives_edit', $args + ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
		$this->assertSame('uncertain', $receipt['status']);
		$this->assertTrue($receipt['nativeCallAttempted']);
		$this->assertArrayHasKey('native_error', $receipt);
		$this->assertCount(1, CollectivesScene::$tree->ops);
	}

	/** @return void membership revoked during the attempt: every receipt is uncertain and shows nothing read after it */
	public function testRevocationDuringTheAttemptGivesAnUncertainReceiptWithoutSnapshot(): void {
		$revoke = static function (): void {
			CollectivesScene::$collectives[7]['members'] = [];
		};
		$guide = CollectivesScene::$ids['Guide.md'];
		$cases = [
			['collectives_create', ['collective_id' => 7, 'parent_id' => $guide, 'title' => 'Late']],
			['collectives_move', ['collective_id' => 7, 'page_id' => $guide, 'parent_id' => CollectivesScene::$ids['Team/Readme.md']]],
			['collectives_edit', ['collective_id' => 7, 'page_id' => CollectivesScene::$ids['Team/Onboarding.md'], 'append' => 'Late']],
		];
		foreach ($cases as [$tool, $args]) {
			CollectivesScene::$collectives[7]['members'] = ['alice'];
			$plan = $this->plan($tool, $args);
			CollectivesScene::$afterNative = $revoke;
			CollectivesScene::$tree->afterWrite = static fn () => $revoke();
			$receipt = $this->confirm($tool, $args + ['plan_state' => $plan['plan_state']] + (isset($plan['etag']) ? ['etag' => $plan['etag']] : []));
			$this->assertSame('uncertain', $receipt['status'], $tool);
			$this->assertArrayNotHasKey('observed', $receipt, $tool);
			$this->assertArrayNotHasKey('page', $receipt, $tool);
			CollectivesScene::$afterNative = null;
			CollectivesScene::$tree->afterWrite = null;
		}
	}

	/** @return void the same content is no change: nothing is attempted */
	public function testNoChangeIsNotAttempted(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$args = ['collective_id' => 7, 'page_id' => $guide, 'content' => "Guide text\n"];
		$plan = $this->plan('collectives_edit', $args);
		$this->assertFalse($plan['changed']);
		$this->assertSame('not_attempted', $this->confirm('collectives_edit', $args + ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']])['status']);
		$this->assertSame([], CollectivesScene::$tree->ops);
	}

	/** @return void C4: only .md, only UTF-8, only within the limit; the Files allowlist is not what decides */
	public function testMarkdownPolicy(): void {
		$policy = new CollectivesMarkdownPolicy();
		$md = $this->createMock(File::class);
		$md->method('getName')->willReturn('Page.md');
		$txt = $this->createMock(File::class);
		$txt->method('getName')->willReturn('Page.txt');
		$policy->assertWritable($md, "ok\n");
		$this->assertStringContainsString('.md', $this->refused(fn () => $policy->assertWritable($txt, "ok\n")));
		$this->assertStringContainsString('UTF-8', $this->refused(fn () => $policy->assertWritable($md, "\xff\xfe")));
		$this->assertStringContainsString('bytes', $this->refused(fn () => $policy->assertWritable($md, str_repeat('a', CollectivesMarkdownPolicy::MAX_BYTES + 1))));
	}
}
