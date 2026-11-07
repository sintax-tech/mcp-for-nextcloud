<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient, Http, ToolReply};
use PHPUnit\Framework\Attributes\Group;

/** Issue26: real requests and a separately labelled induced native delivery control. */
#[Group('deck')]
final class AssignmentLifecycleTest extends IntegrationTestCase {
	/** @param ToolReply $reply Successful HTTP MCP result. @return array<string,mixed> Tool payload. */
	private function data(ToolReply $reply): array {
		self::assertTrue($reply->ok(), $reply->describe());
		return $reply->structured() ?: $reply->json();
	}

	/** Prove other-user, own-user and Bearer-only automation-account assignment through Bearer and the real cookie CardController. */
	public function testRealAssignmentsMatchCardControllerWithBothActors(): void {
		$scene = new FunctionalDeckScene();
		try {
			$automation = \OCP\Server::get(\OCP\IUserManager::class)->get($scene->uid(2));
			self::assertNotNull($automation);
			self::assertSame(0, $automation->getLastLogin());
			self::assertArrayNotHasKey($automation->getUID(), $scene->accounts);
			$automationClient = new McpClient(LoginFactory::bearer($automation->getUID()));
			foreach ([[0, 1], [1, 0]] as [$owner, $editor]) {
				$a = $scene->uid($owner); $b = $scene->uid($editor);
				$f = $scene->seed($a, $b);
				$client = new McpClient(LoginFactory::bearer($b));
				$before = FunctionalDeckScene::run($b, 'read', ['card' => $f['card']]);
				$args = ['cardId' => $f['card'], 'assign' => [$a], 'lastModified' => $before['lastModified']];
				$preview = $this->data($client->tool('deck_edit_card', $args));
				self::assertTrue($preview['requiresConfirmation']);
				self::assertSame([], FunctionalDeckScene::run($b, 'read', ['card' => $f['card']])['assignees']);
				$out = $this->data($client->write('deck_edit_card', $args + ['confirm_shared' => true]));
				self::assertSame([$a], $out['assigned']); self::assertSame([], $out['failed']);
				self::assertSame([$a], FunctionalDeckScene::run($b, 'read', ['card' => $f['card']])['assignees']);
				$browser = new DeckBrowser($b, $scene->accounts[$b]);
				try {
					$native = $browser->assign($f['nativeCard'], $a);
					self::assertSame(200, $native['status'], $native['body']);
					self::assertSame([$a], FunctionalDeckScene::run($b, 'read', ['card' => $f['nativeCard']])['assignees']);
				} finally { $browser->close(); }
				foreach ([[$f['selfCard'], $b], [$f['serviceCard'], $scene->uid(2)]] as [$card, $recipient]) {
					$assigned = $this->data($client->write('deck_edit_card', ['cardId' => $card, 'assign' => [$recipient], 'confirm_shared' => true]));
					self::assertSame([$recipient], $assigned['assigned']);
					$removed = $this->data($client->write('deck_edit_card', ['cardId' => $card, 'unassign' => [$recipient], 'confirm_shared' => true]));
					self::assertSame([$recipient], $removed['unassigned']);
					self::assertSame([], FunctionalDeckScene::run($b, 'read', ['card' => $card])['assignees']);
				}
				// The automation account also acts through Bearer only, never a browser/password login.
				$serviceWrite = $this->data($automationClient->write('deck_edit_card', ['cardId' => $f['serviceCard'],
					'assign' => [$a], 'confirm_shared' => true]));
				self::assertSame([$a], $serviceWrite['assigned']);
				self::assertSame([$a], FunctionalDeckScene::run($b, 'read', ['card' => $f['serviceCard']])['assignees']);
				$refused = $client->write('deck_edit_card', ['cardId' => $f['card'], 'assign' => [$scene->uid(3)], 'confirm_shared' => true]);
				self::assertFalse($refused->ok());
				file_put_contents('/tmp/mcp-it-observations.txt', json_encode(['issue26' => 'real Bearer and cookie CardController assignment passed',
					'actor' => $editor, 'devices' => FunctionalDeckScene::run($a, 'devices', []),
					'automationAccount' => ['backend' => $automation->getBackendClassName(), 'authentication' => 'Bearer only',
						'interactiveLogin' => false, 'creationPasswordRetained' => false, 'specializedServiceBackend' => 'not covered'],
					'serviceDevices' => FunctionalDeckScene::run($scene->uid(2), 'devices', []),
					'validPushClient' => 'not covered'], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
			}
		} finally { $scene->close(); }
	}

	/** Under an induced delivery failure the MCP assignment is stored and deferred; the Deck web route still fails inline. */
	public function testInducedDeliveryFailureNoLongerAbortsTheMcpAssignment(): void {
		$scene = new FunctionalDeckScene(); $probe = null; $browser = null;
		try {
			$a = $scene->uid(0); $b = $scene->uid(1); $f = $scene->seed($a, $b);
			$probe = new AssignmentProbeApp();
			$credential = LoginFactory::bearer($b);
			$headers = ['X-MCP-Deck-Probe: ' . $probe->nonce];
			$response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [...$headers,
				'Authorization: ' . $credential->authorization(), 'Content-Type: application/json',
				'Accept: application/json, text/event-stream'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
				'params' => ['name' => 'deck_edit_card', 'arguments' => ['cardId' => $f['faultCard'], 'assign' => [$a], 'confirm' => true, 'confirm_shared' => true]]], JSON_THROW_ON_ERROR));
			$browser = new DeckBrowser($b, $scene->accounts[$b]);
			$native = $browser->assign($f['nativeFaultCard'], $a, $headers);
			$events = $probe->events();
			$notify = array_values(array_filter($events, static fn ($e): bool => $e['phase'] === 'notify'));
			$mcpSaved = FunctionalDeckScene::run($b, 'read', ['card' => $f['faultCard']])['assignees'];
			$nativeSaved = FunctionalDeckScene::run($b, 'read', ['card' => $f['nativeFaultCard']])['assignees'];
			// Issue #26 fix: the MCP route first tries the direct 1.2.5 call, which fails inline like Deck's own route; the
			// retry defers delivery, stores the assignment, and the delivery error comes after it.
			self::assertSame([['mcp', false], ['mcp', true], ['deck', false]],
				array_map(static fn ($e): array => [$e['route'], $e['deferred']], $notify), json_encode($events));
			$mcpPhases = array_column(array_values(array_filter($events, static fn ($e): bool => $e['route'] === 'mcp')), 'phase');
			$retryAt = array_keys($mcpPhases, 'notify', true)[1];
			self::assertContains('defer', array_slice($mcpPhases, 1, $retryAt - 1), json_encode($events));
			self::assertContains('flush', array_slice($mcpPhases, $retryAt + 1), json_encode($events));
			self::assertSame([$a], $mcpSaved);
			self::assertSame([], $nativeSaved);
			self::assertSame(200, $response['status']);
			self::assertStringContainsString($a, $response['body']);
			self::assertSame(500, $native['status']);
			file_put_contents('/tmp/mcp-it-observations.txt', json_encode(['issue26' => 'induced delivery failure: MCP retried with deferral and stored',
				'events' => $events, 'mcpSaved' => $mcpSaved !== [], 'nativeSaved' => $nativeSaved !== [],
				'mcpHTTP' => $response['status'], 'nativeHTTP' => $native['status']], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
		} finally {
			$browser?->close(); $probe?->close(); $scene->close();
		}
	}
}
