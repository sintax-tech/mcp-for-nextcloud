<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient, ToolReply};
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Issue #26 with the recipient holding a real push device: Deck's own assignUser() (the 1.2.5 path), the Deck web
 * route and deck_edit_card through Bearer, on equivalent cards, for each delivery outcome.
 */
#[Group('deck')]
final class AssignmentPushTest extends IntegrationTestCase {
	/** @param ToolReply $reply Successful HTTP MCP result. @return array<string,mixed> Tool payload. */
	private function data(ToolReply $reply): array {
		self::assertTrue($reply->ok(), $reply->describe());
		return $reply->structured() ?: $reply->json();
	}

	/**
	 * Runs Deck's assignUser() in a fresh process bound to the caller, without MCP code.
	 *
	 * @param string $uid Caller.
	 * @param int $card Card to assign.
	 * @param string $assignee Recipient.
	 * @return array<string,mixed> Error chain and trace, if any, and the stored assignees.
	 */
	private static function native(string $uid, int $card, string $assignee): array {
		$process = proc_open([PHP_BINARY, __DIR__ . '/push-actor.php', $uid],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start the push actor');
		}
		fwrite($pipes[0], json_encode(['card' => $card, 'assignee' => $assignee], JSON_THROW_ON_ERROR));
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($process) !== 0) {
			throw new RuntimeException('Push actor failed: ' . substr($error, 0, 1200));
		}
		return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return iterable<string, array{0: string, 1: bool}> Device kind and delivery outcome, and whether a notifier
	 *     fails while the push is prepared.
	 */
	public static function outcomes(): iterable {
		yield 'proxy delivers' => ['proxy-ok', false];
		yield 'proxy answers 500' => ['proxy-500', false];
		yield 'proxy unreachable' => ['proxy-down', false];
		yield 'web push unreachable' => ['webpush-down', false];
		yield 'push preparation fails' => ['proxy-ok', true];
		yield 'push preparation fails, no device' => ['none', true];
	}

	/**
	 * Registers the recipient's device for the outcome and returns the inventory the Notifications app holds.
	 *
	 * @param PushDeviceFixture $devices Device fixture of this test.
	 * @param string $outcome Device kind and delivery outcome.
	 * @param string $uid Recipient.
	 * @return array<string, mixed> Device counts per Notifications table.
	 */
	private static function device(PushDeviceFixture $devices, string $outcome, string $uid): array {
		if ($outcome === 'webpush-down') {
			if (!is_int(FunctionalDeckScene::run($uid, 'devices', [])['notifications_webpush'])) {
				self::markTestSkipped('This Notifications version has no web push.');
			}
			$registration = $devices->registerWebPush($uid);
			self::assertContains($registration['register']['status'], [200, 201], $registration['register']['body']);
			self::assertNotNull($registration['activate']);
			self::assertContains($registration['activate']['status'], [200, 202], $registration['activate']['body']);
		} elseif ($outcome !== 'none') {
			$devices->startProxy($outcome === 'proxy-500' ? 500 : 200);
			$port = $outcome === 'proxy-down' ? PushDeviceFixture::CLOSED_PORT : PushDeviceFixture::PROXY_PORT;
			$registered = $devices->registerProxyDevice($uid, $port);
			self::assertContains($registered['status'], [200, 201], $registered['body']);
		}
		$inventory = FunctionalDeckScene::run($uid, 'devices', []);
		$expected = ['none' => 0, 'webpush-down' => 1][$outcome] ?? 1;
		$table = $outcome === 'webpush-down' ? 'notifications_webpush' : 'notifications_pushhash';
		self::assertSame($expected, $inventory[$table], json_encode($inventory));
		return $inventory;
	}

	/**
	 * Assign a board member through Deck's own service (the 1.2.5 path), the Deck web route and deck_edit_card.
	 *
	 * Delivery errors the Notifications app handles itself never reached Deck. An error raised while the push is
	 * prepared aborts the native and web assignments, only when the recipient has a device, and deck_edit_card
	 * stores the assignment and reports the error after the write.
	 *
	 * @param string $outcome Device kind and delivery outcome.
	 * @param bool $prepareFails Whether a notifier fails while the push is prepared.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
	public function testAssigningARecipientWithAPushDeviceStoresTheAssignment(string $outcome, bool $prepareFails): void {
		$scene = new FunctionalDeckScene();
		$devices = new PushDeviceFixture();
		$probe = null; $browser = null;
		try {
			$a = $scene->uid(0); $b = $scene->uid(1);
			$f = $scene->seed($a, $b);
			$inventory = self::device($devices, $outcome, $a);
			if ($prepareFails) {
				$probe = new AssignmentProbeApp();
				$probe->prepareFault(true);
			}
			$aborts = $prepareFails && $outcome !== 'none';

			$native = self::native($b, $f['faultCard'], $a);
			$browser = new DeckBrowser($b, $scene->accounts[$b]);
			$web = $browser->assign($f['nativeCard'], $a);
			$webSaved = FunctionalDeckScene::run($b, 'read', ['card' => $f['nativeCard']])['assignees'];
			$requestsBeforeMcp = count($devices->proxyRequests());
			$client = new McpClient(LoginFactory::bearer($b));
			$out = $this->data($client->write('deck_edit_card', ['cardId' => $f['card'], 'assign' => [$a], 'confirm_shared' => true]));
			$mcpSaved = FunctionalDeckScene::run($b, 'read', ['card' => $f['card']])['assignees'];
			$byMcp = count($devices->proxyRequests()) - $requestsBeforeMcp;
			file_put_contents('/tmp/mcp-it-observations.txt', json_encode(['issue26' => 'push device: ' . $outcome
				. ($prepareFails ? ', preparation fails' : ''), 'devices' => $inventory, 'native' => $native,
				'webHTTP' => $web['status'], 'webSaved' => $webSaved, 'mcp' => $out, 'mcpSaved' => $mcpSaved,
				'proxyRequestsByMcp' => $byMcp], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

			// Deck's own service and its web route, untouched by MCP: they abort only when preparation fails for a device.
			self::assertSame($aborts ? [] : [$a], $native['assignees'], json_encode($native));
			self::assertSame($aborts ? 'Synthetic notifier failure' : null, $native['error']['chain'][0]['message'] ?? null, json_encode($native));
			self::assertSame($aborts ? 500 : 200, $web['status']);
			self::assertSame($aborts ? [] : [$a], $webSaved);
			// deck_edit_card always stores the assignment; a preparation error comes after the write, as a warning.
			self::assertSame([$a], $out['assigned'], json_encode($out));
			self::assertSame([], $out['failed'], json_encode($out));
			self::assertSame([$a], $mcpSaved);
			self::assertSame($aborts, ($out['warnings'] ?? []) !== [], json_encode($out));
			if ($outcome === 'proxy-ok' && !$prepareFails) {
				self::assertGreaterThan(0, $byMcp, 'The push was not delivered after the assignment');
			}
		} finally {
			$browser?->close(); $probe?->close(); $devices->close(); $scene->close();
		}
	}

	/**
	 * One call assigns a recipient whose push fails to prepare, then a healthy one, both with a device: both are
	 * assigned, the healthy one still receives its push, and the failing one keeps a single notification.
	 *
	 * @return void
	 */
	public function testAFailingRecipientDoesNotCostTheNextOneItsPush(): void {
		$scene = new FunctionalDeckScene();
		$devices = new PushDeviceFixture();
		$probe = null;
		try {
			$bad = $scene->uid(0); $actor = $scene->uid(1); $healthy = $scene->uid(2);
			$f = $scene->seed($bad, $actor);
			$devices->startProxy(200);
			foreach ([[$bad, 'bad'], [$healthy, 'healthy']] as [$uid, $path]) {
				$registered = $devices->registerProxyDevice($uid, PushDeviceFixture::PROXY_PORT, $path);
				self::assertContains($registered['status'], [200, 201], $registered['body']);
			}
			$probe = new AssignmentProbeApp();
			$probe->prepareFault(true, $bad);
			$before = count($devices->proxyRequests());

			$client = new McpClient(LoginFactory::bearer($actor));
			$out = $this->data($client->write('deck_edit_card', ['cardId' => $f['card'], 'assign' => [$bad, $healthy], 'confirm_shared' => true]));
			$saved = FunctionalDeckScene::run($actor, 'read', ['card' => $f['card']])['assignees'];
			$byMcp = array_slice($devices->proxyRequests(), $before);
			$toHealthy = array_values(array_filter($byMcp, static fn (array $r): bool => str_starts_with($r['path'], '/healthy/')));
			$manager = \OCP\Server::get(\OCP\Notification\IManager::class);
			$notice = $manager->createNotification()->setApp('deck')->setUser($bad)->setObject('card', (string)$f['card']);
			$notices = $manager->getCount($notice);
			file_put_contents('/tmp/mcp-it-observations.txt', json_encode(['issue26' => 'batch: failing recipient then healthy',
				'mcp' => $out, 'saved' => $saved, 'proxyPaths' => array_column($byMcp, 'path'), 'badNotices' => $notices],
				JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

			self::assertEqualsCanonicalizing([$bad, $healthy], $out['assigned'], json_encode($out));
			self::assertSame([], $out['failed'], json_encode($out));
			self::assertNotSame([], $out['warnings'] ?? [], json_encode($out));
			self::assertEqualsCanonicalizing([$bad, $healthy], $saved);
			self::assertNotSame([], $toHealthy, 'The healthy recipient did not receive its push: ' . json_encode($byMcp));
			self::assertSame(1, $notices);
		} finally {
			$probe?->close(); $devices->close(); $scene->close();
		}
	}
}
