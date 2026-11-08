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
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Collectives tools over the real MCP endpoint, with an app password, against the pinned Collectives release of
 * the cell (ci/collectives/cell.sh): the native page service is reached from an MCP request, outside any Collectives
 * controller, for the same account the session holds. Each write is planned, confirmed and then read back through the
 * tools, so what is asserted is what Collectives stored.
 */
#[Group('collectives')]
final class McpCollectivesHttpTest extends IntegrationTestCase {
	/** @var array<string, Credential> app passwords by account, never printed */
	private static array $credentials = [];

	/** @return void runs only in a cell provisioned with Collectives */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_COLLECTIVES') !== '1') {
			$this->markTestSkipped('Collectives cell only (MCP_IT_WITH_COLLECTIVES=1)');
		}
		$policy = Server::get(GrantPolicy::class);
		// MCP on and "Can connect" for both accounts, as the Exclusion world sets them up: what Collectives shows them is what is tested.
		$policy->setGlobalEnabled(true);
		foreach ([$this->owner(), (string)getenv('MCP_IT_COLLECTIVES_OUTSIDER')] as $uid) {
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
		}
		foreach (['create', 'edit', 'move'] as $operation) {
			$policy->setGrant($this->owner(), 'collectives', $operation, true);
		}
	}

	/** @return string the collective's owner */
	private function owner(): string {
		return (string)getenv('MCP_IT_COLLECTIVES_OWNER');
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
	 * Plans a write, then confirms exactly that plan, as a client does after the person agrees.
	 * @param string $tool write tool
	 * @param array<string, mixed> $arguments arguments of the plan
	 * @return array<string, mixed> the receipt
	 */
	private function confirmed(string $tool, array $arguments): array {
		$plan = $this->client($this->owner())->tool($tool, $arguments);
		$this->assertFalse($plan->isError(), $plan->describe());
		$state = $plan->structured();
		$this->assertTrue($state['requiresConfirmation'] ?? false, $plan->describe());
		$confirm = $arguments + ['confirm' => true, 'plan_state' => $state['plan_state']] + (isset($state['etag']) ? ['etag' => $state['etag']] : []);
		return $this->json($this->client($this->owner())->tool($tool, $confirm));
	}

	/**
	 * Only the collective the cell provisions is this test's: others the owner has, such as those the C04 tests create,
	 * are left alone and not counted. Every page of the listing is read, following `next_cursor`, since the collectives
	 * the C04 tests leave behind sort before it and may push it off the first page.
	 * @return array<string, mixed> "MCP Wiki" as collectives_list shows it to the owner, listed exactly once
	 */
	private function wiki(): array {
		$collectives = [];
		$arguments = [];
		do {
			$page = $this->json($this->client($this->owner())->tool('collectives_list', $arguments));
			$collectives = [...$collectives, ...$page['collectives']];
			$arguments = ['cursor' => $page['next_cursor']];
		} while ($page['next_cursor'] !== null);
		$wiki = array_values(array_filter($collectives, static fn (array $collective): bool => $collective['name'] === 'MCP Wiki'));
		$this->assertCount(1, $wiki, 'the provisioned collective is listed once');
		return $wiki[0];
	}

	/** @return void discovery, reads, create, edit and move through the native services, each read back */
	public function testThePageServiceWorksFromTheMcpEndpoint(): void {
		$names = $this->published($this->owner());
		$this->assertSame(['collectives_list', 'collectives_pages', 'collectives_read', 'collectives_search', 'collectives_create',
			'collectives_edit', 'collectives_move'], $names, 'the probe accepts the pinned release');
		$wiki = $this->wiki();
		$this->assertTrue($wiki['can_edit']);
		$cid = $wiki['id'];
		$pages = $this->json($this->client($this->owner())->tool('collectives_pages', ['collective_id' => $cid]))['pages'];
		$landing = $pages[0]['id'];
		$this->assertSame(0, $pages[0]['parent_id']);

		$created = $this->confirmed('collectives_create', ['collective_id' => $cid, 'parent_id' => 0, 'title' => 'Runbook']);
		$this->assertSame('applied', $created['status'], json_encode($created));
		$runbook = $created['page']['id'];
		$this->assertSame($landing, $created['page']['parent_id']);

		$edited = $this->confirmed('collectives_edit', ['collective_id' => $cid, 'page_id' => $runbook, 'append' => 'Step one']);
		$this->assertSame('applied', $edited['status'], json_encode($edited));
		$read = $this->json($this->client($this->owner())->tool('collectives_read', ['collective_id' => $cid, 'page_id' => $runbook]));
		$this->assertStringContainsString('Step one', $read['content']);
		$this->assertSame($this->owner(), $read['page']['last_user'], 'the native listener recorded the editor of the session');

		$child = $this->confirmed('collectives_create', ['collective_id' => $cid, 'parent_id' => 0, 'title' => 'Checklist'])['page']['id'];
		$moved = $this->confirmed('collectives_move', ['collective_id' => $cid, 'page_id' => $child, 'parent_id' => $runbook, 'index' => 0]);
		$this->assertSame('applied', $moved['status'], json_encode($moved));
		$this->assertSame(['parent_id' => $runbook, 'title' => 'Checklist', 'position' => 0, 'in_old_order' => false],
			array_intersect_key($moved['observed'], array_flip(['parent_id', 'title', 'position', 'in_old_order'])));
		$this->assertSame('checklist', strtolower((string)$moved['page']['slug']));
	}

	/**
	 * An outsider admitted by MCP (grant, app, session, Collectives folder all set) is refused by Collectives itself:
	 * the collective is not listed, and its id answers the not-found every unknown id gets, before any page is read.
	 * @return void
	 */
	public function testAnOutsiderIsRefusedByCollectives(): void {
		$cid = $this->wiki()['id'];
		$outsider = (string)getenv('MCP_IT_COLLECTIVES_OUTSIDER');
		$this->assertSame([], $this->json($this->client($outsider)->tool('collectives_list'))['collectives'], 'admitted, and Collectives lists nothing');
		foreach ([['collectives_pages', ['collective_id' => $cid]], ['collectives_pages', ['collective_id' => $cid + 1000]]] as [$tool, $arguments]) {
			$reply = $this->client($outsider)->tool($tool, $arguments);
			$this->assertTrue($reply->isError(), $reply->describe());
			$this->assertSame('Resource not found in Nextcloud.', $reply->text(), 'a known collective answers like an unknown one');
		}
	}
}
