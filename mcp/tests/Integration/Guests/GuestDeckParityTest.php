<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tests\Integration\Deck\DeckFixture;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\IConfig;
use OCP\Server;
use OCP\Share\IShare;

/**
 * Native WebDAV and MCP parity for the same guest, URI and file bytes.
 */
final class GuestDeckParityTest extends IntegrationTestCase {
	use GuestTestSupport;

	private const GUEST = 'mcp-it-parity-guest';
	private const MOUNT = 'mcp-it-parity-ext';
	private GuestScenario $scenario;

	/**
	 * Configure this class's positive grant controls.
	 *
	 * @param GrantPolicy $policy Policy of the disposable container.
	 * @return void
	 */
	protected static function grantGuestControls(GrantPolicy $policy): void {
		$policy->setGrant(self::GUEST, 'deck', 'read', true);
	}

	/**
	 * Read the exact stored override, preserving inheritance rather than materializing its resolved boolean.
	 *
	 * @param string $module Module of the temporary grant.
	 * @param string $operation Operation being exercised.
	 * @return string|null Previous raw override; null means inheritance.
	 */
	private function grantState(string $module, string $operation): ?string {
		$values = Server::get(IConfig::class)->getUserValueForUsers('mcp', 'grant_' . $module . '_' . $operation, [self::GUEST]);
		return array_key_exists(self::GUEST, $values) ? (string)$values[self::GUEST] : null;
	}

	/**
	 * Restore exactly the policy cell read before a temporary revocation.
	 *
	 * @param string $module Module of the temporary grant.
	 * @param string $operation Operation being restored.
	 * @param string|null $previous Exact previous override or inherited state.
	 * @return void
	 */
	private function restoreGrant(string $module, string $operation, ?string $previous): void {
		$this->scenario->cleanup(function () use ($module, $operation, $previous): void {
			if ($previous === null) {
				Server::get(PolicyWriter::class)->resetOverride(self::GUEST, $module, $operation);
			} else {
				Server::get(GrantPolicy::class)->setGrant(self::GUEST, $module, $operation, $previous === '1');
			}
			$observed = $this->grantState($module, $operation);
			$this->scenario->check(fn () => $this->assertSame($previous, $observed, 'Restore the previous override, including inheritance'));
		});
	}

	/**
	 * Native received shares remain available when Guests removes the guest's direct external mounts.
	 *
	 * @return void
	 */
	public function testReceivedExternalSharesMatchNativeWebDavOnBothLogins(): void {
		$config = Server::get(IConfig::class);
		$hiddenTags = $config->getAppValue('mcp', 'hidden_system_tags', '[]');
		$policy = Server::get(GrantPolicy::class);
		$previousDeckEdit = $this->grantState('deck', 'edit');
		$f = ['mount' => self::MOUNT, 'suffix' => bin2hex(random_bytes(self::PARITY_SUFFIX_BYTES))];
		$this->scenario = new GuestScenario();
		$this->scenario->run(function () use ($config, $policy, &$f): void {
			$config->setAppValue('mcp', 'hidden_system_tags', '[]');
			$policy->setGrant(self::GUEST, 'deck', 'edit', true);
			$this->guests(['usewhitelist' => 'yes', 'whitelist' => 'mcp,deck',
				'allow_external_storage' => 'yes', 'hide_users' => 'no']);
			$out = self::occ('files_external:create', '--output=json', '/' . self::MOUNT, 'local', 'null::null', '-c', 'datadir=/mcp-it-ext');
			$this->mountId = (int)trim((string)end($out));
			$this->assertGreaterThan(0, $this->mountId);
			self::occ('files_external:option', (string)$this->mountId, 'enable_sharing', 'true');
			foreach ([self::GUEST, DeckFixture::OWNER] as $actor) {
				self::occ('files_external:applicable', (string)$this->mountId, '--add-user=' . $actor);
			}
			self::occ('files:scan', DeckFixture::OWNER);
			$f += DeckFixture::run(DeckFixture::OWNER, 'guest-external-prepare', $f + ['guest' => self::GUEST]);
			self::occ('files:scan', DeckFixture::OWNER);
			$f += DeckFixture::run(DeckFixture::OWNER, 'guest-external-associate', $f + ['mount' => self::MOUNT]);
			$this->shares = array_merge($f['shareIds'], [$f['independent']['id']]);
			$f['guestShare'] = GuestDeckParityProbe::editableShare(LoginFactory::basic(DeckFixture::OWNER),
				'/' . self::MOUNT . '/' . $f['editableName'], self::GUEST);
			$this->shares[] = $f['guestShare']['id'];
			$this->assertSame($f['editableFileId'], $f['guestShare']['fileId']);
			self::occ('files:scan', self::GUEST);
			$probe = DeckFixture::run(DeckFixture::OWNER, 'guest-origin-probe', $f + ['guest' => self::GUEST]);
			$this->observe('Native parity origins: ' . json_encode($probe, JSON_THROW_ON_ERROR));
			$aliases = [];
			foreach ([IShare::TYPE_DECK => false, IShare::TYPE_USER => true] as $type => $updateable) {
				$nodes = array_values(array_filter($probe['guest'], static fn (array $node): bool => $node['shareType'] === $type));
				$this->assertCount(1, $nodes);
				$this->assertSame('shared', $nodes[0]['mountType']);
				$this->assertSame($type === IShare::TYPE_DECK ? $f['externalFileId'] : $f['editableFileId'], $nodes[0]['id']);
				$this->assertSame($updateable, $nodes[0]['updateable']);
				$aliases[$type] = $nodes[0]['relativePath'];
			}
			$this->mcp('basic');
			$native = self::$logins['basic'];
			foreach (['yes', 'no'] as $externalAllowed) {
				$this->guests(['allow_external_storage' => $externalAllowed]);
				foreach (['basic', 'bearer'] as $kind) {
					$client = $this->mcp($kind);
					// This comparison must fail if a stricter guard rejects a natively readable received alias.
					$sharedRead = GuestDeckParityProbe::read($native, $client, $aliases[IShare::TYPE_DECK]);
					$this->assertSame($sharedRead['get'] === 200, $sharedRead['mcpRead'],
						'Received-share DAV/MCP parity: ' . json_encode($sharedRead, JSON_THROW_ON_ERROR));
					$deck = $client->tool('deck_list_card_files', ['cardId' => $f['cardId']]);
					$this->assertTrue($deck->ok(), $deck->raw);
					$items = $deck->json()['items'];
					$this->assertCount(2, $items, 'Received external Deck association and internal control both remain visible');
					$this->assertSame([$f['externalShare'], $f['homeShare']], array_column($items, 'attachmentId'));
					$this->assertSame([$f['externalFileId'], $f['homeFileId']], array_column($items, 'fileId'));
					$paths = ['direct' => '/' . self::MOUNT . '/' . $f['externalName'],
						'deck' => $aliases[IShare::TYPE_DECK], 'user' => $aliases[IShare::TYPE_USER], 'internal' => $items[1]['path']];
					foreach ($paths as $role => $path) {
						$row = GuestDeckParityProbe::read($native, $client, $path);
						$row += ['externalAllowed' => $externalAllowed, 'login' => $kind, 'role' => $role,
							'deckFileIds' => array_column($items, 'fileId')];
						$this->observe('PARITY ' . json_encode($row, JSON_THROW_ON_ERROR));
						$visible = $role !== 'direct' || $externalAllowed === 'yes';
						$this->assertSame($visible, $row['mcpRead'], json_encode($row));
						if ($visible) {
							$this->assertSame(207, $row['propfind']);
							$this->assertSame(200, $row['get']);
							$this->assertSame($role === 'internal' ? $f['homeFileId'] : ($role === 'user' ? $f['editableFileId'] : $f['externalFileId']), $row['fileId']);
							$this->assertSame($role === 'internal' ? $f['homeHash'] : ($role === 'user' ? $f['editableHash'] : $f['externalHash']), $row['davHash']);
							// Files read/list/tree expose no fileid: MCP identity here is exact URI + bytes.
							$this->assertSame($row['davHash'], $row['mcpHash']);
						} else {
							// Direct ExternalMountPoint is hidden; a received SharedMount is a separate native capability.
							$this->assertSame(404, $row['propfind']);
							$this->assertSame(404, $row['get']);
						}
					}
					$this->assertFalse($client->write('files_checkout', ['path' => $paths['deck']])->ok(), 'Native Deck share is read-only');
					$bytes = GuestDeckParityProbe::dav($native, 'GET', $paths['user'])['body'];
					$deckBytes = GuestDeckParityProbe::dav($native, 'GET', $paths['deck'])['body'];
					$this->assertSame(403, GuestDeckParityProbe::dav($native, 'PUT', $paths['deck'], $deckBytes)['status']);
					$this->assertSame(204, GuestDeckParityProbe::dav($native, 'PUT', $paths['user'], $bytes)['status']);
					[$download, $upload] = $this->checkout($client, $paths['user']);
					$previous = $this->grantState('files', 'edit');
					$policy->setGrant(self::GUEST, 'files', 'edit', false);
					try {
						$this->assertFalse($client->write('files_checkout', ['path' => $paths['user']])->ok());
						$this->assertSame(403, Http::request('PUT', $upload, ['Content-Type: application/octet-stream'], $bytes)['status']);
					} finally {
						$this->restoreGrant('files', 'edit', $previous);
					}
					$get = Http::request('GET', $download);
					$this->assertSame(200, $get['status']);
					$this->assertSame($f['editableHash'], hash('sha256', $get['body']));
					$this->assertSame(200, Http::request('PUT', $upload, ['Content-Type: application/octet-stream'], $bytes)['status']);
					$args = ['cardId' => $f['cardId'], 'attachmentId' => $f['externalShare']];
					$plan = $client->tool('deck_unlink_card_file', $args);
					$this->assertTrue($plan->ok(), $plan->raw);
					$this->assertTrue($plan->structured()['requiresConfirmation']);
					$previous = $this->grantState('deck', 'edit');
					$policy->setGrant(self::GUEST, 'deck', 'edit', false);
					try {
						$confirmed = $args + array_intersect_key($plan->structured(), array_flip(['plan_state', 'file_state']))
							+ ['confirm' => true, 'confirm_shared' => true];
						$this->assertFalse($client->tool('deck_unlink_card_file', $confirmed)->ok());
					} finally {
						$this->restoreGrant('deck', 'edit', $previous);
					}
					$previous = $this->grantState('files', 'read');
					$policy->setGrant(self::GUEST, 'files', 'read', false);
					try {
						$this->assertFalse($client->tool('files_read', ['path' => $paths['user']])->ok());
						$this->assertFalse($client->tool('deck_list_card_files', ['cardId' => $f['cardId']])->ok());
					} finally {
						$this->restoreGrant('files', 'read', $previous);
					}
					$this->observe("PARITY checkout/unlink $externalAllowed/$kind: native readonly refused, editable DAV+checkout GET/PUT passed; grants revalidated");
				}
			}
			$observed = DeckFixture::run(DeckFixture::OWNER, 'guest-external-inspect', $f + ['mount' => self::MOUNT]);
			$this->assertSame($f['externalFileId'], $observed['fileId']);
			$this->assertSame($f['externalHash'], $observed['hash']);
			$this->assertSame($f['editableFileId'], $observed['editableFileId']);
			$this->assertSame($f['editableHash'], $observed['editableHash']);
			$this->assertSame([$f['externalShare'], $f['homeShare']], $observed['attachments']);
			$this->assertSame($f['independent'], $observed['independent']);
			$this->assertSame($f['guestShare'], $observed['guestShare']);
		}, function () use ($config, $hiddenTags, $previousDeckEdit, &$f): void {
			$this->scenario->cleanup(function () use (&$f): void {
				$clean = DeckFixture::run(DeckFixture::OWNER, 'guest-external-cleanup', $f + ['shares' => $this->shares]);
				$this->observe('PARITY cleanup ' . json_encode($clean, JSON_THROW_ON_ERROR));
				$this->scenario->check(fn () => $this->assertSame(['shares' => [], 'files' => [], 'boards' => []], $clean));
				$this->shares = [];
			});
			$this->scenario->cleanup(fn () => $config->setAppValue('mcp', 'hidden_system_tags', $hiddenTags));
			$this->restoreGrant('deck', 'edit', $previousDeckEdit);
		});
	}
}
