<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Collectives;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ServiceSwitchFixture;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * Creating a collective and managing its members (C04, #22) over the real MCP endpoint, with an app password, against
 * the pinned Collectives and Teams of the cell. Every write is refused without its grant, its plan is shown to change
 * nothing by reading Collectives and Teams themselves in this process ({@see NativeTeamsProbe}), and the confirmed call
 * is read back the same way. Runs only on Nextcloud 33 to 35, where the C04 tools exist (decision D7). The members the
 * test adds are removed again, also when it fails, so the outsider of {@see McpCollectivesHttpTest} stays an outsider.
 */
#[Group('collectives')]
final class McpCollectivesAdminHttpTest extends IntegrationTestCase {
	/** Group the member tests add and remove; created here when the cell has none. */
	private const GROUP = 'mcp-it-c04';

	/** @var array<string, Credential> app passwords by account, never printed */
	private static array $credentials = [];
	/** @var ServiceSwitchFixture|null per-test service switch, absent when the opt-in skips setup */
	private ?ServiceSwitchFixture $serviceSwitch = null;

	/** @return void runs only in a cell provisioned with Collectives on Nextcloud 33 to 35 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_COLLECTIVES') !== '1' || (int)\OCP\Util::getVersion()[0] < 33) {
			$this->markTestSkipped('Collectives cell on Nextcloud 33 to 35 only (MCP_IT_WITH_COLLECTIVES=1)');
		}
		$this->serviceSwitch = ServiceSwitchFixture::open();
		$policy = Server::get(GrantPolicy::class);
		foreach ([$this->owner(), $this->outsider()] as $uid) {
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
		}
		$this->grant('collective.create', true);
		$this->grant('members', true);
		$groups = Server::get(IGroupManager::class);
		$group = $groups->get(self::GROUP) ?? $groups->createGroup(self::GROUP);
		$group->addUser(Server::get(IUserManager::class)->get($this->outsider()));
	}

	/** @return void the grants and the service switch as they were, and no membership left behind */
	protected function tearDown(): void {
		try {
			if ($this->serviceSwitch !== null) {
				$team = NativeTeamsProbe::team($this->wiki(), $this->owner());
				NativeTeamsProbe::removeIfPresent($this->owner(), $team, '1:' . $this->outsider());
				NativeTeamsProbe::removeIfPresent($this->owner(), $team, '2:' . self::GROUP);
				$this->grant('collective.create', false);
				$this->grant('members', false);
			}
		} finally {
			try {
				$this->serviceSwitch?->restore();
			} finally {
				parent::tearDown();
			}
		}
	}

	/** @return string the collectives' owner of the cell */
	private function owner(): string {
		return (string)getenv('MCP_IT_COLLECTIVES_OWNER');
	}

	/** @return string the account outside the collective of the cell */
	private function outsider(): string {
		return (string)getenv('MCP_IT_COLLECTIVES_OUTSIDER');
	}

	/** @return int the id of the cell's collective, read from Collectives itself */
	private function wiki(): int {
		return NativeTeamsProbe::collectives($this->owner())['MCP Wiki'];
	}

	/**
	 * @param string $operation operation of the collectives module
	 * @param bool $allowed whether the owner has it
	 * @return void
	 */
	private function grant(string $operation, bool $allowed): void {
		Server::get(GrantPolicy::class)->setGrant($this->owner(), 'collectives', $operation, $allowed);
	}

	/**
	 * @param string $uid account
	 * @return McpClient its client over the production endpoint
	 */
	private function client(string $uid): McpClient {
		return new McpClient(self::$credentials[$uid] ??= LoginFactory::basic($uid));
	}

	/**
	 * @param string $uid account
	 * @return list<string> the Collectives tools tools/list publishes for it
	 */
	private function published(string $uid): array {
		$this->client($uid);
		$response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', ['Authorization: ' . self::$credentials[$uid]->authorization(),
			'Content-Type: application/json', 'Accept: application/json, text/event-stream'],
			json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
		$this->assertSame(200, $response['status']);
		$names = array_column(json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['result']['tools'], 'name');
		return array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'collectives_')));
	}

	/**
	 * @param ToolReply $reply an answer that must be a success
	 * @return array<string, mixed> its JSON
	 */
	private function json(ToolReply $reply): array {
		$this->assertTrue($reply->ok(), $reply->describe());
		return $reply->json();
	}

	/**
	 * @param string $tool write tool
	 * @param array<string, mixed> $arguments arguments of the plan
	 * @return array<string, mixed> the plan, checked to be one
	 */
	private function plan(string $tool, array $arguments): array {
		$plan = $this->client($this->owner())->tool($tool, $arguments);
		$this->assertFalse($plan->isError(), $plan->describe());
		$state = $plan->structured();
		$this->assertTrue($state['requiresConfirmation'] ?? false, $plan->describe());
		return $state;
	}

	/**
	 * @param string $tool write tool
	 * @param array<string, mixed> $arguments arguments of the plan
	 * @param array<string, mixed> $plan the plan the person agreed to
	 * @return array<string, mixed> the receipt
	 */
	private function confirm(string $tool, array $arguments, array $plan): array {
		return $this->json($this->client($this->owner())->tool($tool, $arguments + ['confirm' => true, 'plan_state' => $plan['plan_state']]));
	}

	/**
	 * @param string $tool a tool the owner is not granted
	 * @param array<string, mixed> $arguments its arguments
	 * @return void it is not published and its call is refused
	 */
	private function assertRefusedWithoutGrant(string $tool, array $arguments): void {
		$this->assertNotContains($tool, $this->published($this->owner()));
		$reply = $this->client($this->owner())->tool($tool, $arguments);
		$this->assertTrue($reply->isError(), $reply->describe());
	}

	/** @return void the C04 tools are published at the end of the catalog on a proven release */
	public function testTheCatalogPublishesTheC04Tools(): void {
		$this->assertSame(['collectives_list', 'collectives_pages', 'collectives_read', 'collectives_search', 'collectives_create',
			'collectives_edit', 'collectives_move', 'collectives_create_collective', 'collectives_list_members', 'collectives_add_member',
			'collectives_set_member_level', 'collectives_remove_member'], $this->published($this->owner()));
	}

	/** @return void without the grant nothing; the plan changes nothing in Collectives or Teams; the creation is read back natively */
	public function testCreatingACollective(): void {
		$name = 'MCP C04 ' . bin2hex(random_bytes(3));
		$this->grant('collective.create', false);
		$this->assertRefusedWithoutGrant('collectives_create_collective', ['name' => $name]);
		$this->grant('collective.create', true);

		$collectives = NativeTeamsProbe::collectives($this->owner());
		$teams = NativeTeamsProbe::teams($this->owner());
		$plan = $this->plan('collectives_create_collective', ['name' => $name]);
		$this->assertSame($name, $plan['name']);
		$this->assertSame($collectives, NativeTeamsProbe::collectives($this->owner()), 'the plan created no collective');
		$this->assertSame($teams, NativeTeamsProbe::teams($this->owner()), 'the plan created no team');

		$receipt = $this->confirm('collectives_create_collective', ['name' => $name], $plan);
		$this->assertSame('applied', $receipt['status'], json_encode($receipt));
		$this->assertFalse($receipt['adopted_existing_team']);
		$created = NativeTeamsProbe::collectives($this->owner());
		$this->assertSame($receipt['collective']['id'], $created[$name] ?? null, 'Collectives lists the new collective');
		$members = NativeTeamsProbe::members($this->owner(), NativeTeamsProbe::team($created[$name], $this->owner()));
		$this->assertSame(['1:' . $this->owner()], array_keys($members), 'its new team has the owner only');
		$this->assertSame(9, $members['1:' . $this->owner()]['level']);

		$taken = $this->client($this->owner())->tool('collectives_create_collective', ['name' => $name]);
		$this->assertTrue($taken->isError(), 'the same name is refused before anything is written');
	}

	/** @return void list, add, level and remove: refused without the grant, plans with no native effect, each change read back natively */
	public function testManagingMembers(): void {
		$cid = $this->wiki();
		$team = NativeTeamsProbe::team($cid, $this->owner());
		$outsider = '1:' . $this->outsider();
		$this->grant('members', false);
		$this->assertRefusedWithoutGrant('collectives_list_members', ['collective_id' => $cid]);
		$this->assertRefusedWithoutGrant('collectives_add_member', ['collective_id' => $cid, 'type' => 'user', 'id' => $this->outsider()]);
		$this->assertRefusedWithoutGrant('collectives_set_member_level', ['collective_id' => $cid, 'member_id' => 'unknown', 'level' => 'member']);
		$this->assertRefusedWithoutGrant('collectives_remove_member', ['collective_id' => $cid, 'member_id' => 'unknown']);
		$this->grant('members', true);

		$listed = $this->json($this->client($this->owner())->tool('collectives_list_members', ['collective_id' => $cid]));
		$this->assertTrue($listed['you_can_manage']);
		$this->assertSame('owner', array_column($listed['members'], 'level', 'id')[$this->owner()]);

		$before = NativeTeamsProbe::members($this->owner(), $team);
		$this->assertArrayNotHasKey($outsider, $before);
		$add = ['collective_id' => $cid, 'type' => 'user', 'id' => $this->outsider()];
		$plan = $this->plan('collectives_add_member', $add);
		$this->assertSame($before, NativeTeamsProbe::members($this->owner(), $team), 'the plan added nobody');
		$receipt = $this->confirm('collectives_add_member', $add, $plan);
		$this->assertSame('applied', $receipt['status'], json_encode($receipt));
		$member = NativeTeamsProbe::members($this->owner(), $team)[$outsider] ?? null;
		$this->assertNotNull($member, 'Teams has the new member');
		$this->assertSame([1, 'Member'], [$member['level'], $member['status']]);

		$level = ['collective_id' => $cid, 'member_id' => $member['id'], 'level' => 'moderator'];
		$plan = $this->plan('collectives_set_member_level', $level);
		$this->assertSame(1, NativeTeamsProbe::members($this->owner(), $team)[$outsider]['level'], 'the plan changed no level');
		$this->assertSame('applied', $this->confirm('collectives_set_member_level', $level, $plan)['status']);
		$this->assertSame(4, NativeTeamsProbe::members($this->owner(), $team)[$outsider]['level']);

		$remove = ['collective_id' => $cid, 'member_id' => $member['id']];
		$plan = $this->plan('collectives_remove_member', $remove);
		$this->assertArrayHasKey($outsider, NativeTeamsProbe::members($this->owner(), $team), 'the plan removed nobody');
		$this->assertSame('applied', $this->confirm('collectives_remove_member', $remove, $plan)['status']);
		$this->assertSame($before, NativeTeamsProbe::members($this->owner(), $team), 'the team is as before');
	}

	/** @return void a group is added and removed through its own request; the owner can never be removed */
	public function testAGroupAndTheOwner(): void {
		$cid = $this->wiki();
		$team = NativeTeamsProbe::team($cid, $this->owner());
		$group = '2:' . self::GROUP;
		$add = ['collective_id' => $cid, 'type' => 'group', 'id' => self::GROUP];
		$plan = $this->plan('collectives_add_member', $add);
		$this->assertArrayNotHasKey($group, NativeTeamsProbe::members($this->owner(), $team), 'the plan added no group');
		$receipt = $this->confirm('collectives_add_member', $add, $plan);
		$this->assertSame('applied', $receipt['status'], json_encode($receipt));
		$this->assertSame('unknown', $receipt['auxiliary_effects']);
		$id = NativeTeamsProbe::members($this->owner(), $team)[$group]['id'] ?? null;
		$this->assertNotNull($id, 'Teams has the group');
		$this->assertSame('applied', $this->confirm('collectives_remove_member', ['collective_id' => $cid, 'member_id' => $id],
			$this->plan('collectives_remove_member', ['collective_id' => $cid, 'member_id' => $id]))['status']);

		$owner = NativeTeamsProbe::members($this->owner(), $team)['1:' . $this->owner()]['id'];
		$refused = $this->client($this->owner())->tool('collectives_remove_member', ['collective_id' => $cid, 'member_id' => $owner]);
		$this->assertTrue($refused->isError(), 'the owner is never removed');
		$this->assertArrayHasKey('1:' . $this->owner(), NativeTeamsProbe::members($this->owner(), $team));
	}
}
