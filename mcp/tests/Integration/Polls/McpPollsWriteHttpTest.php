<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Polls\Integration\PollsMutationProbe;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
use OCP\App\IAppManager;
use OCP\Server;
use OCP\Util;
use PHPUnit\Framework\Attributes\Group;

/** Minimal real Bearer proof of independently granted Polls configuration, options and user sharing. */
#[Group('polls')]
final class McpPollsWriteHttpTest extends IntegrationTestCase {
	/** Selects the explicit Polls cell, skipping only an inapplicable pinned version.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_POLLS') !== '1') {
			self::markTestSkipped('Pinned Polls cell only');
		}
		$apps = Server::get(IAppManager::class);
		$bounds = PollsMutationProbe::VERSIONS[(int)Util::getVersion()[0]] ?? null;
		$version = $apps->getAppVersion('polls');
		if ($bounds === null || version_compare($version, $bounds[0], '<') || version_compare($version, $bounds[1], '>=')) {
			self::markTestSkipped('Installed Polls version is outside the proven mutation range for this Nextcloud major.');
		}
		$apps->loadApp('polls');
	}

	/** Configures, adds/reorders and shares through separate plans, then checks native persistence and grant refusals.
	 * @return void
	 */
	public function testBearerConfigurationOptionsShareAndGrantRefusals(): void {
		$uid = PollsScene::OWNER;
		$policy = Server::get(GrantPolicy::class);
		$client = new McpClient(LoginFactory::bearer($uid));
		foreach (['create', 'configure', 'options', 'share', 'read'] as $operation) {
			$policy->setGrant($uid, 'polls', $operation, true);
		}
		try {
			$created = $this->apply($client, 'polls_create_poll', ['type' => 'textPoll', 'title' => 'HTTP mutation proof', 'options' => [['text' => 'First']]], 'created');
			$id = $created['pollId'];
			$requests = [
				['configure', 'polls_update_poll', ['poll_id' => $id, 'changes' => ['title' => 'HTTP configured']]],
				['options', 'polls_add_option', ['poll_id' => $id, 'option' => ['text' => 'Second']]],
				['share', 'polls_add_share', ['poll_id' => $id, 'type' => 'user', 'recipient' => ['user_id' => PollsScene::VIEWER]]],
			];
			foreach ($requests as [$grant, $tool, $args]) {
				$policy->setGrant($uid, 'polls', $grant, false);
				$before = PollsScene::snapshot();
				self::assertFalse($client->tool($tool, $args)->ok());
				self::assertSame($before, PollsScene::snapshot());
				$policy->setGrant($uid, 'polls', $grant, true);
				$receipt = $this->apply($client, $tool, $args);
				if ($grant === 'share') {
					$shares = array_values(array_filter($receipt['observed']['shares'], static fn (array $s): bool => $s['userId'] === PollsScene::VIEWER && !$s['deleted']));
					self::assertCount(1, $shares);
					self::assertSame('user', $shares[0]['type']);
				}
			}
			$native = PollsCreateActor::run($uid, 'native', ['poll_id' => $id]);
			self::assertSame('HTTP configured', $native['title']);
			self::assertSame(['First', 'Second'], array_column($native['options'], 'text'));
			$reversed = array_reverse(array_column($native['options'], 'id'));
			$this->apply($client, 'polls_reorder_options', ['poll_id' => $id, 'option_ids' => $reversed]);
			$read = $client->tool('polls_read_poll', ['poll_id' => $id]);
			self::assertTrue($read->ok(), $read->describe());
			self::assertSame($reversed, array_column($read->json()['options'], 'id'));
			$viewer = new McpClient(LoginFactory::bearer(PollsScene::VIEWER));
			$read = $viewer->tool('polls_read_poll', ['poll_id' => $id]);
			self::assertTrue($read->ok(), $read->describe());
			self::assertSame($reversed, array_column($read->json()['options'], 'id'));
		} finally {
			foreach (['create', 'configure', 'options', 'share'] as $operation) {
				$policy->setGrant($uid, 'polls', $operation, false);
			}
		}
	}

	/** Confirms exactly one effect-free preview and checks the observed receipt.
	 * @param McpClient $client Authenticated actor.
	 * @param string $tool Published tool.
	 * @param array<string,mixed> $args Request.
	 * @param string $status Expected success classification.
	 * @return array<string,mixed> Observed receipt.
	 */
	private function apply(McpClient $client, string $tool, array $args, string $status = 'applied'): array {
		$before = PollsScene::snapshot();
		$plan = $client->tool($tool, $args);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertTrue($plan->structured()['requiresConfirmation']);
		self::assertSame($before, PollsScene::snapshot());
		$reply = $client->tool($tool, $args + ['confirm' => true, 'plan_state' => $plan->structured()['plan_state']]);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame($status, $reply->json()['status'], $reply->describe());
		return $reply->json();
	}
}
