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
use OCA\Mcp\Modules\Collectives\Integration\TeamsGateway;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use OCA\Mcp\Modules\Collectives\Removal\RemovalReadback;
use PHPUnit\Framework\TestCase;

/**
 * The member tools of C04b over the Teams doubles of {@see TeamsScene}: collective 7 has alice (owner), bob (admin),
 * carol (member), the group staff, dave (invited), erin (requesting) and frank (blocked). Plans only read, managing
 * needs an admin of the collective, the owner and the actor's own membership never change, pending members have no
 * level to change, and every receipt is read back from the team, with Teams' own records reported apart.
 */
final class CollectivesMembersTest extends TestCase {
	/**
	 * @param CollectivesModule $module the module
	 * @param string $tool member tool
	 * @param array<string, mixed> $arguments arguments without confirmation
	 * @return array<string, mixed> the receipt of the confirmed call
	 */
	private function confirm(CollectivesModule $module, string $tool, array $arguments): array {
		$plan = $module->preview($tool, $arguments, 'alice');
		$result = $module->call($tool, $arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
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

	/** @return void the listing shows levels and statuses, a pending member at level none, and what the actor may do */
	public function testTheListing(): void {
		$module = (new CollectivesRig($this))->module();
		$answer = json_decode($module->call('collectives_list_members', ['collective_id' => 7], 'alice')['content'][0]['text'], true);
		$this->assertTrue($answer['you_can_manage']);
		$this->assertFalse($answer['invite_required']);
		$this->assertSame(['owner', 'admin', 'member', 'member', 'none', 'none', 'none'], array_column($answer['members'], 'level'));
		$byId = array_column($answer['members'], null, 'member_id');
		$this->assertSame(['invited', 'requesting', 'blocked'], [$byId['m-dave']['status'], $byId['m-erin']['status'], $byId['m-frank']['status']]);
		$this->assertSame('group', $byId['m-staff']['type']);
		$this->assertNull(TeamsScene::$currentUser);
	}

	/** @return void without the members grant, or below admin, nothing is changed; listing needs the grant but not admin */
	public function testGrantAndAdminLevel(): void {
		$rig = new CollectivesRig($this);
		$rig->grants = ['read'];
		$this->refused(fn () => $rig->module()->call('collectives_list_members', ['collective_id' => 7], 'alice'));
		$rig = new CollectivesRig($this);
		CollectivesScene::$collectives[7]['level'] = 4;
		$module = $rig->module();
		$this->assertStringContainsString('Only admins', $this->refused(fn () => $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina'], 'alice')));
		$answer = json_decode($module->call('collectives_list_members', ['collective_id' => 7], 'alice')['content'][0]['text'], true);
		$this->assertFalse($answer['you_can_manage']);
	}

	/** @return void adding an account: plan without any Teams resolution, confirmed once, read back, identity recorded on the way */
	public function testAddingAnAccount(): void {
		$module = (new CollectivesRig($this))->module();
		$plan = $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina'], 'alice');
		$this->assertSame('member', $plan['outcome']);
		$this->assertNotContains('resolve:gina', TeamsScene::$calls, 'the plan never resolves the person in Teams');
		$this->assertStringContainsString('internal identity', implode(' ', $plan['notices']));
		$receipt = $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina']);
		$this->assertSame('applied', $receipt['status']);
		$this->assertSame('identity_recorded', $receipt['auxiliary_effects']);
		$this->assertSame(['status' => 'member', 'level' => 'member'], $receipt['observed']);
		$this->assertCount(1, array_filter(TeamsScene::$calls, static fn (string $c): bool => $c === 'addMember:gina'));
		$this->assertNull(TeamsScene::$currentUser);
	}

	/** @return void a team that requires invitations: an account becomes an invitation at level none, a group is refused */
	public function testInvitations(): void {
		$module = (new CollectivesRig($this))->module();
		TeamsScene::$teams['circle-secret']['config'] = 32;
		$plan = $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina'], 'alice');
		$this->assertSame('invited', $plan['outcome']);
		$receipt = $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina']);
		$this->assertSame(['status' => 'invited', 'level' => 'none'], $receipt['observed']);
		$this->assertSame('applied', $receipt['status']);
		$this->assertStringContainsString('nobody can accept', $this->refused(fn () => $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'design'], 'alice')));
	}

	/** @return void the team setting is read again on confirmation: an invitation required since the plan changes it */
	public function testTheInvitationSettingIsReadAgain(): void {
		$module = (new CollectivesRig($this))->module();
		$plan = $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'design'], 'alice');
		TeamsScene::$teams['circle-secret']['config'] = 32;
		$this->expectException(ToolFailure::class);
		$module->call('collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'design', 'confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
	}

	/** @return void a request is accepted; existing members, invitations, blocked accounts, unknown ids and oneself are refused */
	public function testAdditionCases(): void {
		$module = (new CollectivesRig($this))->module();
		$this->assertSame('member', $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'erin'], 'alice')['outcome']);
		foreach (['carol' => 'already a member', 'dave' => 'pending invitation', 'frank' => 'blocked', 'nobody' => 'No account', 'alice' => 'own membership'] as $id => $refusal) {
			$this->assertStringContainsString($refusal, $this->refused(fn () => $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => $id], 'alice')));
		}
	}

	/** @return void adding a group seals the Teams session before resolving it; the readback still works, nothing else does */
	public function testAGroupSealsTheSession(): void {
		$module = (new CollectivesRig($this))->module();
		$receipt = $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'design']);
		$this->assertSame('applied', $receipt['status']);
		$this->assertSame('unknown', $receipt['auxiliary_effects']);
		$this->assertContains('group-app', TeamsScene::$calls);
		$this->refused(fn () => $module->call('collectives_list_members', ['collective_id' => 7], 'alice'));
		$module = (new CollectivesRig($this))->module();
		TeamsScene::$failResolveGroup = true;
		$receipt = $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'design']);
		$this->assertSame('not_applied', $receipt['status']);
		$this->refused(fn () => $module->call('collectives_list_members', ['collective_id' => 7], 'alice'));
	}

	/**
	 * @return void Teams stores a group as a membership of the group's system team, named after its display name: it is
	 *     listed as the group by its id, an existing one is seen by the plan, and the readback of an addition finds it
	 *     (H4 cell: the receipt was partial with nothing observed); another team as member stays `other`
	 */
	public function testAGroupIsReadAsTeamsStoresIt(): void {
		$module = (new CollectivesRig($this))->module();
		$receipt = $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'design']);
		$this->assertSame('applied', $receipt['status'], json_encode($receipt));
		$this->assertSame(['status' => 'member', 'level' => 'member'], $receipt['observed']);
		$stored = array_column(TeamsScene::$entries['circle-secret'], null, 'id')['m-design'];
		$this->assertSame([16, 'Design'], [$stored['type'], $stored['userId']], 'stored as Teams 33 to 35 store a group');

		$module = (new CollectivesRig($this))->module();
		TeamsScene::$entries['circle-secret'][] = TeamsScene::group('m-design', 'design', 1, 'Member');
		foreach (['m-ops' => null, 'm-team' => ['name' => 'group:ops', 'source' => 16], 'm-odd' => ['name' => 'ops', 'source' => 2]] as $id => $basedOn) {
			TeamsScene::$entries['circle-secret'][] = ['id' => $id, 'userId' => 'Ops', 'type' => 16, 'level' => 1, 'status' => 'Member', 'basedOn' => $basedOn];
		}
		TeamsScene::$entries['circle-secret'][] = ['id' => 'm-user', 'userId' => 'ops', 'type' => 1, 'level' => 1, 'status' => 'Member',
			'basedOn' => ['name' => 'group:ops', 'source' => 2]];
		$byId = array_column(json_decode($module->call('collectives_list_members', ['collective_id' => 7], 'alice')['content'][0]['text'], true)['members'], null, 'member_id');
		$this->assertSame(['group', 'design'], [$byId['m-design']['type'], $byId['m-design']['id']]);
		$this->assertSame(['group', 'staff'], [$byId['m-staff']['type'], $byId['m-staff']['id']]);
		$this->assertSame(['user', 'ops'], [$byId['m-user']['type'], $byId['m-user']['id']], 'only a team membership can be a group');
		foreach (['m-ops', 'm-team', 'm-odd'] as $id) {
			$this->assertSame(['other', 'Ops'], [$byId[$id]['type'], $byId[$id]['id']], "$id: a team that is not a group's system team");
		}
		$this->refused(fn () => $module->preview('collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'staff'], 'alice'));
	}

	/** @return void reproduction B-R3 of the review: a team that requires invitations refuses a group in any state, a request included */
	public function testAnInvitationTeamRefusesARequestingGroup(): void {
		$module = (new CollectivesRig($this))->module();
		TeamsScene::$teams['circle-secret']['config'] = 32;
		TeamsScene::$entries['circle-secret'][] = TeamsScene::group('m-design', 'design', 0, 'Requesting');
		$this->assertStringContainsString('nobody can accept', $this->refused(fn () => $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'design'])));
		$this->assertNotContains('addMember:design', TeamsScene::$calls);
	}

	/** @return void reproduction B-R2 of the review: after a group was resolved, no page write of C01-C03 crosses the seal */
	public function testTheSealRefusesAnExistingPageWrite(): void {
		$module = (new CollectivesRig($this))->module();
		$this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'group', 'id' => 'design']);
		$this->assertTrue(TeamsScene::$currentApp);
		$this->refused(fn () => $this->confirm($module, 'collectives_create', ['collective_id' => 7, 'parent_id' => CollectivesScene::$ids['Guide.md'], 'title' => 'After sealed group']));
		$this->assertNotContains('create', CollectivesScene::$calls, 'no page was created');
		$this->refused(fn () => $module->call('collectives_list', [], 'alice'));
	}

	/** @return void a refusal of Teams leaves the membership as it was but may have recorded the identity; a failure after storing is partial */
	public function testRefusalsAndFailuresAfterTheAttempt(): void {
		$module = (new CollectivesRig($this))->module();
		TeamsScene::$failMember = 'before';
		$receipt = $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina']);
		$this->assertSame('not_applied', $receipt['status']);
		$this->assertFalse($receipt['membership_changed']);
		$this->assertSame('identity_recorded', $receipt['auxiliary_effects'], 'never "nothing was written"');
		$module = (new CollectivesRig($this))->module();
		TeamsScene::$failMember = 'after';
		$receipt = $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina']);
		$this->assertSame('partial', $receipt['status'], 'stored, then Teams failed: what follows the store is not proven');
		$this->assertTrue($receipt['membership_changed']);
		$this->assertArrayHasKey('native_error', $receipt);
	}

	/** @return void reproduction B-R1 of the review: an invitation stored before memberPreparing failed is not a completed invitation */
	public function testFailureAfterInsertIsNotACompletedInvitation(): void {
		$module = (new CollectivesRig($this))->module();
		TeamsScene::$teams['circle-secret']['config'] = 32;
		TeamsScene::$failMember = 'after';
		$receipt = $this->confirm($module, 'collectives_add_member', ['collective_id' => 7, 'type' => 'user', 'id' => 'gina']);
		$this->assertSame('partial', $receipt['status'], json_encode($receipt));
		$this->assertSame(['status' => 'invited', 'level' => 'none'], $receipt['observed']);
	}

	/** @return void levels: member, moderator and admin only; never the owner, oneself or a pending member; the same level is not attempted */
	public function testLevels(): void {
		$module = (new CollectivesRig($this))->module();
		$receipt = $this->confirm($module, 'collectives_set_member_level', ['collective_id' => 7, 'member_id' => 'm-carol', 'level' => 'admin']);
		$this->assertSame('applied', $receipt['status']);
		$this->assertSame(['status' => 'member', 'level' => 'admin'], $receipt['observed']);
		$this->assertSame('not_attempted', $this->confirm($module, 'collectives_set_member_level', ['collective_id' => 7, 'member_id' => 'm-carol', 'level' => 'admin'])['status']);
		foreach (['m-alice' => 'own membership', 'm-dave' => 'still pending', 'm-nobody' => 'no member'] as $member => $refusal) {
			$this->assertStringContainsString($refusal, $this->refused(fn () => $module->preview('collectives_set_member_level', ['collective_id' => 7, 'member_id' => $member, 'level' => 'member'], 'alice')));
		}
		$rig = new CollectivesRig($this);
		$module = $rig->module();
		TeamsScene::$entries['circle-secret'][1]['level'] = 9;
		TeamsScene::$entries['circle-secret'][0]['level'] = 8;
		$this->assertStringContainsString('owner', $this->refused(fn () => $module->preview('collectives_set_member_level', ['collective_id' => 7, 'member_id' => 'm-bob', 'level' => 'member'], 'alice')));
	}

	/** @return void removing: the owner and oneself never; a pending member cancels; the receipt reads the team again */
	public function testRemoval(): void {
		$module = (new CollectivesRig($this))->module();
		$plan = $module->preview('collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-dave'], 'alice');
		$this->assertStringContainsString('cancelled', implode(' ', $plan['notices']));
		$receipt = $this->confirm($module, 'collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-staff']);
		$this->assertSame('applied', $receipt['status']);
		$this->assertNull($receipt['observed']);
		$this->assertStringContainsString('own membership', $this->refused(fn () => $module->preview('collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-alice'], 'alice')));
		$rig = new CollectivesRig($this);
		$module = $rig->module();
		TeamsScene::$entries['circle-secret'][1]['level'] = 9;
		TeamsScene::$entries['circle-secret'][0]['level'] = 8;
		$this->assertStringContainsString('owner', $this->refused(fn () => $module->preview('collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-bob'], 'alice')));
	}

	/**
	 * @param CollectivesRig $rig the rig
	 * @return PacedRemovalReadback the readback the module gets, on its own clock
	 */
	private function pacedReadback(CollectivesRig $rig): PacedRemovalReadback {
		$readback = new PacedRemovalReadback($rig->get(TeamsGateway::class));
		$rig->bind(RemovalReadback::class, $readback);
		return $readback;
	}

	/** @return void Teams deletes the member in the background (CI run 42424755894): the receipt waits for it and is applied, removing once */
	public function testARemovalAppliedInTheBackgroundIsApplied(): void {
		$rig = new CollectivesRig($this);
		$readback = $this->pacedReadback($rig);
		TeamsScene::$removalReads = 3;
		$receipt = $this->confirm($rig->module(), 'collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-carol']);
		$this->assertSame('applied', $receipt['status'], json_encode($receipt));
		$this->assertNull($receipt['observed']);
		$this->assertSame(3, $readback->pauses, 'read again until the member is gone');
		$this->assertSame(['removeMember:m-carol'], array_values(array_filter(TeamsScene::$calls, static fn (string $call): bool => str_starts_with($call, 'removeMember'))));
	}

	/** @return void a removal still not visible when the wait runs out is uncertain, never partial; it is not sent again */
	public function testARemovalNotVisibleWithinTheWaitIsUncertain(): void {
		$rig = new CollectivesRig($this);
		$readback = $this->pacedReadback($rig);
		TeamsScene::$removalReads = 1000;
		$receipt = $this->confirm($rig->module(), 'collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-carol']);
		$this->assertSame('uncertain', $receipt['status'], json_encode($receipt));
		$this->assertFalse($receipt['membership_changed']);
		$this->assertSame(['status' => 'member', 'level' => 'member'], $receipt['observed']);
		$this->assertSame(MembershipMessages::notVisibleYet(), $receipt['message']);
		$this->assertArrayNotHasKey('native_error', $receipt);
		$this->assertSame(RemovalReadback::SECONDS * 1_000_000 / RemovalReadback::PAUSE, $readback->pauses, 'the wait is bounded');
		$this->assertSame(['removeMember:m-carol'], array_values(array_filter(TeamsScene::$calls, static fn (string $call): bool => str_starts_with($call, 'removeMember'))));
	}

	/** @return void a removal Teams refused is read once, without waiting */
	public function testARefusedRemovalIsNotWaitedFor(): void {
		$rig = new CollectivesRig($this);
		$readback = $this->pacedReadback($rig);
		TeamsScene::$removalReads = 1000;
		TeamsScene::$failMember = 'before';
		$receipt = $this->confirm($rig->module(), 'collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-carol']);
		$this->assertSame('not_applied', $receipt['status'], json_encode($receipt));
		$this->assertArrayHasKey('native_error', $receipt);
		$this->assertSame(0, $readback->pauses);
	}

	/** @return void a member that changed since the plan changes the plan: nothing is attempted */
	public function testAChangeSinceThePlanAttemptsNothing(): void {
		$module = (new CollectivesRig($this))->module();
		$plan = $module->preview('collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-carol'], 'alice');
		TeamsScene::$entries['circle-secret'][2]['level'] = 4;
		$this->expectException(PlanChanged::class);
		try {
			$module->call('collectives_remove_member', ['collective_id' => 7, 'member_id' => 'm-carol', 'confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
		} finally {
			$this->assertNotContains('removeMember:m-carol', TeamsScene::$calls);
		}
	}

	/** @return void reading the collective fails inside its Teams session: the session is closed for the member tools too */
	public function testTheCollectiveReadIsClosed(): void {
		$module = (new CollectivesRig($this))->module();
		TeamsScene::$failReads = ['collective'];
		$this->refused(fn () => $module->call('collectives_list_members', ['collective_id' => 7], 'alice'));
		$this->assertContains('failed:collective', TeamsScene::$calls);
		$this->assertNull(TeamsScene::$currentUser);
		$this->assertFalse(TeamsScene::$bypass);
	}
}
