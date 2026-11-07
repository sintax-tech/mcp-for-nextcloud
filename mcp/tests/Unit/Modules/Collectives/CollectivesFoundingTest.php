<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\CollectivesModule;
use OCA\Mcp\Modules\Collectives\Integration\CollectiveFounding;
use OCA\Mcp\Modules\Collectives\Integration\FoundingReadback;
use PHPUnit\Framework\TestCase;

/**
 * `collectives_create_collective` over the native doubles of {@see TeamsScene}: the plan only reads, every refusal
 * happens before Collectives runs, the confirmed call runs once, and the receipt says what a fresh read found,
 * including an adopted team and a failure halfway, without repairing anything. The Teams session is always closed.
 */
final class CollectivesFoundingTest extends TestCase {
	/** Tool under test. */
	private const TOOL = 'collectives_create_collective';

	/**
	 * @param CollectivesModule $module the module
	 * @param array<string, mixed> $arguments arguments without confirmation
	 * @return array<string, mixed> the receipt of the confirmed call
	 */
	private function confirm(CollectivesModule $module, array $arguments): array {
		$plan = $module->preview(self::TOOL, $arguments, 'alice');
		$result = $module->call(self::TOOL, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/** @param \Closure(): mixed $call a call that must be refused @return string the refusal */
	private function refused(\Closure $call): string {
		try {
			$call();
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('the call was not refused');
	}

	/** @return void the plan lists what is created and changes nothing; the session is closed and MCP opened no privileged one */
	public function testThePlanOnlyReads(): void {
		$module = (new CollectivesRig($this))->module();
		$plan = $module->preview(self::TOOL, ['name' => 'Projetos', 'emoji' => '📘'], 'alice');
		$this->assertSame('Projetos', $plan['name']);
		$this->assertSame(['team', 'collective', 'folder', 'landing_page'], $plan['creates']);
		$this->assertSame([], $plan['other_members']);
		$this->assertArrayHasKey(PlanState::ARGUMENT, $plan);
		$this->assertNotContains('createCollective:pt_BR', TeamsScene::$calls);
		$this->assertNotContains('mcp-super', TeamsScene::$calls);
		$this->assertNull(TeamsScene::$currentUser);
		$this->assertFalse(TeamsScene::$bypass);
		$this->assertCount(1, CollectivesScene::$collectives);
	}

	/** @return void without the grant, as a guest, or before Teams knows the actor, nothing native runs */
	public function testRefusalsBeforeAnythingNative(): void {
		$rig = new CollectivesRig($this);
		$rig->grants = ['read', 'create', 'edit', 'move'];
		$this->refused(fn () => $rig->module()->preview(self::TOOL, ['name' => 'Projetos'], 'alice'));
		foreach ([true, null] as $guest) {
			$rig = new CollectivesRig($this);
			$rig->guestAccount = $guest;
			$this->assertStringContainsString('Guest accounts', $this->refused(fn () => $rig->module()->preview(self::TOOL, ['name' => 'Projetos'], 'alice')));
		}
		$rig = new CollectivesRig($this);
		$rig->teamsIdentity = '';
		$this->assertStringContainsString('Teams is not set up', $this->refused(fn () => $rig->module()->preview(self::TOOL, ['name' => 'Projetos'], 'alice')));
		$this->assertSame([], TeamsScene::$calls, 'no Teams identity is resolved, so none is created');
	}

	/** @return void a name the actor sees, as a collective or a team, is refused in the plan */
	public function testATakenNameIsRefused(): void {
		$module = (new CollectivesRig($this))->module();
		foreach (['wiki', 'DESIGN TEAM'] as $name) {
			$this->assertStringContainsString('already called', $this->refused(fn () => $module->preview(self::TOOL, ['name' => $name], 'alice')));
		}
		$this->assertNull(TeamsScene::$currentUser);
	}

	/** @return void names are refused rather than changed silently, and what is stored is what the plan shows */
	public function testNamesAndEmoji(): void {
		$rig = new CollectivesRig($this);
		$module = $rig->module();
		foreach (["ABC.\t", "a\nb", '  .. ', 'ab', str_repeat('x', 128), '📘📘📘'] as $name) {
			if ($name === '📘📘📘') {
				$rig->fourBytes = false;
			}
			$this->refused(fn () => $module->preview(self::TOOL, ['name' => $name], 'alice'));
		}
		$rig->fourBytes = true;
		$plan = $module->preview(self::TOOL, ['name' => ' a/b:c  d. '], 'alice');
		$this->assertSame('a-bc d', $plan['name']);
		$this->assertStringContainsString('a-bc d', $plan['notices'][0]);
		foreach (['🇧🇷', '📘📗', 'x'] as $emoji) {
			$this->refused(fn () => $module->preview(self::TOOL, ['name' => 'Projetos', 'emoji' => $emoji], 'alice'));
		}
	}

	/** @return void the confirmed call creates once, in the actor's language, and the receipt reads every piece back */
	public function testConfirmedCreationIsApplied(): void {
		$receipt = $this->confirm((new CollectivesRig($this))->module(), ['name' => 'Projetos', 'emoji' => '📘']);
		$this->assertSame('applied', $receipt['status']);
		$this->assertFalse($receipt['adopted_existing_team']);
		$this->assertSame(['root' => true, 'folder' => true, 'landing' => true, 'registered' => true], $receipt['pieces']);
		$this->assertSame('Projetos', $receipt['collective']['name']);
		$this->assertSame(1, count(array_filter(TeamsScene::$calls, static fn (string $call): bool => $call === 'createCollective:pt_BR')));
		$this->assertNull(TeamsScene::$currentUser);
		$this->assertFalse(TeamsScene::$bypass);
		$this->assertNotContains('mcp-super', TeamsScene::$calls);
	}

	/** @return void another emoji than the approved one changes the plan: nothing is created */
	public function testAnotherArgumentThanThePlanChangesNothing(): void {
		$module = (new CollectivesRig($this))->module();
		$plan = $module->preview(self::TOOL, ['name' => 'Projetos', 'emoji' => '📘'], 'alice');
		$this->expectException(PlanChanged::class);
		try {
			$module->call(self::TOOL, ['name' => 'Projetos', 'emoji' => '📗', 'confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
		} finally {
			$this->assertNotContains('createCollective:pt_BR', TeamsScene::$calls);
		}
	}

	/**
	 * @return void a team of that name the actor administers appears after the last check: the adoption is reported,
	 *     also when the actor is its owner and only member, the case only the native notice reveals
	 */
	public function testAnAdoptionInTheRaceIsReported(): void {
		foreach ([['alice', 'bob'], ['alice']] as $members) {
			$module = (new CollectivesRig($this))->module();
			$plan = $module->preview(self::TOOL, ['name' => 'Projetos'], 'alice');
			TeamsScene::$beforeCreate = static function () use ($members): void {
				TeamsScene::$teams['team-race'] = ['name' => 'Projetos', 'owner' => 'alice', 'members' => $members];
			};
			$result = $module->call(self::TOOL, ['name' => 'Projetos', 'confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
			$receipt = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
			$this->assertSame('partial', $receipt['status']);
			$this->assertTrue($receipt['adopted_existing_team']);
		}
	}

	/** @return void a failure halfway is uncertain, never retried, and the adoption cannot be told */
	public function testAFailureHalfwayIsUncertain(): void {
		foreach (['collective', 'folder', 'page', 'flag', 'team-session'] as $step) {
			$rig = new CollectivesRig($this);
			TeamsScene::$failAt = $step;
			$receipt = $this->confirm($rig->module(), ['name' => 'Projetos']);
			$this->assertSame('uncertain', $receipt['status'], $step);
			$this->assertSame('unknown', $receipt['adopted_existing_team'], $step);
			$this->assertCount(1, array_filter(TeamsScene::$calls, static fn (string $call): bool => str_starts_with($call, 'createCollective')), $step);
			$this->assertNull(TeamsScene::$currentUser, "$step: the session the helper left open is closed");
			$this->assertFalse(TeamsScene::$bypass, $step);
		}
	}

	/** @return void a team of that name the actor cannot see makes Collectives refuse before it writes */
	public function testAHiddenTeamOfThatNameIsNotApplied(): void {
		$receipt = $this->confirm((new CollectivesRig($this))->module(), ['name' => 'Secret']);
		$this->assertSame('not_applied', $receipt['status']);
		$this->assertFalse($receipt['adopted_existing_team']);
	}

	/** @return void a landing page without its record is not applied, and the readback creates no missing root */
	public function testTheReadbackRepairsNothing(): void {
		TeamsScene::reset();
		$rig = new CollectivesRig($this);
		TeamsScene::$failAt = 'no-record';
		$receipt = $this->confirm($rig->module(), ['name' => 'Projetos']);
		$this->assertSame('partial', $receipt['status']);
		$this->assertFalse($receipt['pieces']['registered']);
		$rig = new CollectivesRig($this);
		TeamsScene::$root = false;
		TeamsScene::$failAt = 'folder';
		$receipt = $this->confirm($rig->module(), ['name' => 'Projetos']);
		$this->assertSame('uncertain', $receipt['status']);
		$this->assertFalse(TeamsScene::$root, 'the root the creation never made is not made by the readback');
		$this->assertNotContains('createRoot', TeamsScene::$calls);
	}

	/** @return void the session a native failure leaves open is closed right after the call, before any readback */
	public function testTheFoundingClosesTheSessionTheHelperLeftOpen(): void {
		$rig = new CollectivesRig($this);
		TeamsScene::$failAt = 'team-session';
		$answer = $rig->get(CollectiveFounding::class)->create('alice', 'Projetos', null);
		$this->assertNull($answer['collective_id']);
		$this->assertInstanceOf(\OCA\Collectives\Service\NotPermittedException::class, $answer['error']);
		$this->assertNull(TeamsScene::$currentUser, 'the helper left alice as the current Teams user');
		$this->assertFalse(TeamsScene::$bypass);
	}

	/** @return void with the collectives root missing, the readback reports nothing and creates nothing */
	public function testTheReadbackDoesNotCreateAMissingRoot(): void {
		$rig = new CollectivesRig($this);
		TeamsScene::$root = false;
		TeamsScene::$folders[8] = ['landing' => 99, 'registered' => true];
		$this->assertSame(['root' => false, 'folder' => false, 'landing' => false, 'registered' => null],
			$rig->get(FoundingReadback::class)->pieces(8, 'alice'));
		$this->assertFalse(TeamsScene::$root);
		$this->assertNotContains('createRoot', TeamsScene::$calls);
	}

	/**
	 * @return void a native read that fails inside the Teams session it opened leaves no current user and no bypass:
	 *     the listing of the preflight, the listing of the receipt and the landing page of the receipt
	 */
	public function testNativeReadsThatFailInsideTheirSessionAreClosed(): void {
		$rig = new CollectivesRig($this);
		TeamsScene::$failReads = ['collectives'];
		$this->refused(fn () => $rig->module()->preview(self::TOOL, ['name' => 'Projetos'], 'alice'));
		$this->assertNull(TeamsScene::$currentUser, 'preflight');
		$this->assertFalse(TeamsScene::$bypass, 'preflight');

		$module = (new CollectivesRig($this))->module();
		TeamsScene::$beforeCreate = static function (): void {
			TeamsScene::$failReads = ['collectives'];
		};
		$receipt = $this->confirm($module, ['name' => 'Projetos']);
		$this->assertSame('uncertain', $receipt['status']);
		$this->assertNull(TeamsScene::$currentUser, 'receipt listing');
		$this->assertFalse(TeamsScene::$bypass, 'receipt listing');

		$module = (new CollectivesRig($this))->module();
		TeamsScene::$failReads = ['find'];
		$receipt = $this->confirm($module, ['name' => 'Projetos']);
		$this->assertSame('partial', $receipt['status']);
		$this->assertNull($receipt['pieces']['registered']);
		$this->assertNull(TeamsScene::$currentUser, 'receipt landing page');
		$this->assertFalse(TeamsScene::$bypass, 'receipt landing page');
	}

	/** @return void when the actor's identity cannot be resolved, the session is still closed */
	public function testTheSessionIsClosedWhenResolvingFails(): void {
		$module = (new CollectivesRig($this))->module();
		TeamsScene::$failResolve = true;
		$this->refused(fn () => $module->preview(self::TOOL, ['name' => 'Projetos'], 'alice'));
		$this->assertContains('stop', TeamsScene::$calls);
		$this->assertNull(TeamsScene::$currentUser);
	}
}
