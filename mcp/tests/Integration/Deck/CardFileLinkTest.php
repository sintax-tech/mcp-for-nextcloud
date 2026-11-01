<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Integration\Guests\GuestScenario;
use OCA\Mcp\Tests\Integration\Guests\GuestTestSupport;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\Constants;
use OCP\IConfig;
use OCP\Server;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real MCP Bearer/Basic native links and strict policy controls, including the native type-12 bypass.
 */
#[Group('deck')]
final class CardFileLinkTest extends IntegrationTestCase {
	use GuestTestSupport;

	/**
	 * Unique disposable Guests identity belonging only to this class.
	 */
	private const GUEST = 'mcp-it-link-guest';

	/**
	 * Hexadecimal characters in a SHA-256 plan HMAC, used only for a deliberately invalid Guest confirmation.
	 */
	private const PLAN_HASH_HEX_LENGTH = 64;

	/**
	 * Configure explicit positive grants before exercising the independent strict Guest denial.
	 *
	 * @param GrantPolicy $policy Disposable container policy writer facade.
	 * @return void
	 */
	protected static function grantGuestControls(GrantPolicy $policy): void {
		foreach ([self::GUEST, DeckFixture::OWNER, DeckFixture::EDITOR] as $uid) {
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
			foreach ([['deck', 'read'], ['deck', 'edit'], ['files', 'read'], ['files', 'share']] as [$module, $operation]) {
				$policy->setGrant($uid, $module, $operation, true);
			}
		}
	}

	/**
	 * Run a scenario with isolated core/Guests settings and exact restoration of every raw previous value.
	 *
	 * @param callable(GuestScenario):void $body Scenario whose own resource cleanup is registered independently.
	 * @return void
	 */
	private function scenario(callable $body): void {
		$config = Server::get(IConfig::class);
		$changes = ['core' => ['shareapi_enabled' => 'yes', 'shareapi_exclude_groups' => 'no',
			'shareapi_exclude_groups_list' => '[]', 'shareapi_only_share_with_group_members' => 'no', 'shareapi_allow_group_sharing' => 'yes',
			'shareapi_allow_resharing' => 'yes', 'shareapi_default_internal_expire_date' => 'no',
			'shareapi_enforce_internal_expire_date' => 'no'],
			'guests' => ['usewhitelist' => 'yes', 'whitelist' => 'mcp,deck,files_sharing', 'hide_users' => 'no']];
		$saved = [];
		foreach ($changes as $app => $values) {
			$keys = $config->getAppKeys($app);
			foreach ($values as $key => $value) {
				$saved[$app][$key] = in_array($key, $keys, true) ? $config->getAppValue($app, $key) : null;
				$config->setAppValue($app, $key, $value);
			}
		}
		$scenario = new GuestScenario();
		$scenario->run(fn () => $body($scenario), function () use ($config, $saved, $scenario): void {
			foreach ($saved as $app => $values) {
				foreach ($values as $key => $value) {
					$scenario->cleanup(static function () use ($config, $app, $key, $value): void {
						if ($value === null) {
							$config->deleteAppValue($app, $key);
						} else {
							$config->setAppValue($app, $key, $value);
						}
					});
				}
			}
		});
	}

	/**
	 * Decode only successful structured MCP replies.
	 *
	 * @param ToolReply $reply Actual HTTP MCP response.
	 * @return array<string, mixed> Structured plan or result.
	 */
	private function data(ToolReply $reply): array {
		self::assertTrue($reply->ok(), $reply->describe());
		$out = $reply->structured();
		if ($out === []) {
			$out = $reply->json();
		}
		self::assertIsArray($out, $reply->describe());
		self::assertNotSame([], $out, $reply->describe());
		return $out;
	}

	/**
	 * Create a native fixture, run assertions and always clean its exact resource ledger afterwards.
	 *
	 * @param array<string, mixed> $input Native audience and optional Guest actor.
	 * @param callable(array):void $body Assertions for this fixture.
	 * @return void
	 */
	private function fixture(array $input, callable $body): void {
		$fixture = DeckFixture::run(DeckFixture::OWNER, 'link-seed', $input);
		$scenario = new GuestScenario();
		$scenario->run(fn () => $body($fixture), function () use ($fixture, $scenario): void {
			$result = DeckFixture::run(DeckFixture::OWNER, 'link-cleanup', $fixture);
			$scenario->check(fn () => self::assertTrue($result['cleaned']));
		});
	}

	/**
	 * Read original identity/hash and prove independent user share preservation.
	 *
	 * @param array<string, mixed> $fixture Native owned ledger.
	 * @return array<string, mixed> Current native file/association observations.
	 */
	private function original(array $fixture): array {
		$state = DeckFixture::run(DeckFixture::OWNER, 'link-inspect', $fixture);
		self::assertSame($fixture['fileId'], $state['fileId']);
		self::assertSame($fixture['hash'], $state['hash']);
		self::assertSame(['id' => $fixture['independent'], 'fileId' => $fixture['fileId'],
			'recipient' => DeckFixture::OUTSIDER, 'permissions' => 1], $state['independent']);
		return $state;
	}

	/**
	 * Prove the real Bearer controller grants READ=1 only after a matching preview/confirm and preserves unlink.
	 *
	 * @return void
	 */
	public function testBearerPreviewConfirmBytesDuplicateAndUnlink(): void {
		$this->scenario(function (): void {
			$client = new McpClient(LoginFactory::bearer(DeckFixture::OWNER));
			$this->fixture([], function (array $f) use ($client): void {
				$args = ['cardId' => $f['cardId'], 'fileId' => $f['fileId']];
				self::assertFalse(DeckFixture::run(DeckFixture::READER, 'link-inspect', $f)['readable']);
				$plan = $this->data($client->tool('deck_link_card_file', $args));
				self::assertTrue($plan['requiresConfirmation']);
				self::assertSame(3, $plan['recipients']['total']);
				self::assertStringContainsString('READ=1', $plan['message']);
				self::assertSame([], $this->original($f)['links']);
				$result = $this->data($client->tool('deck_link_card_file', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]));
				self::assertTrue($result['confirmed']);
				self::assertSame(1, $result['permissions']);
				$owner = $this->original($f);
				self::assertCount(1, $owner['links']);
				self::assertSame(IShare::TYPE_DECK, $owner['links'][0]['type']);
				self::assertSame(1, $owner['links'][0]['permissions']);
				$reader = DeckFixture::run(DeckFixture::READER, 'link-inspect', $f);
				self::assertSame($f['hash'], $reader['hash']);
				self::assertSame($f['fileId'], $reader['fileId']);
				self::assertSame(1, $reader['permissions']);
				self::assertTrue($client->tool('deck_link_card_file', $args)->isError());
				$unlink = ['cardId' => $f['cardId'], 'attachmentId' => $result['attachmentId']];
				$unlinkPlan = $this->data($client->tool('deck_unlink_card_file', $unlink));
				$unlinked = $this->data($client->tool('deck_unlink_card_file', $unlink + ['confirm' => true,
					'plan_state' => $unlinkPlan['plan_state'], 'file_state' => $unlinkPlan['file_state']]));
				self::assertTrue($unlinked['unlinked']);
				self::assertSame([], $this->original($f)['links']);
				self::assertFalse(DeckFixture::run(DeckFixture::READER, 'link-inspect', $f)['readable']);
				$this->observe('Bearer normal actor: preview no share; confirm READ1 same fileid/hash; duplicate refused; unlink preserves original/independent share.');
			});
		});
	}

	/**
	 * Prove A2 group/circle and M1 effective expiry refuse MCP while native type12 can still grant real bytes.
	 *
	 * @return void
	 */
	public function testGroupCircleAndEffectiveExpiryPolicies(): void {
		$this->scenario(function (): void {
			$client = new McpClient(LoginFactory::bearer(DeckFixture::OWNER));
			$config = Server::get(IConfig::class);
			foreach (['group', 'circle'] as $kind) {
				$config->setAppValue('core', 'shareapi_allow_group_sharing', 'yes');
				$this->fixture(['kind' => $kind], function (array $f) use ($client, $config, $kind): void {
					$args = ['cardId' => $f['cardId'], 'fileId' => $f['fileId']];
					$plan = $this->data($client->tool('deck_link_card_file', $args));
					self::assertTrue($plan['recipients'][$kind === 'group' ? 'hasGroupAcl' : 'hasCircleAcl']);
					$config->setAppValue('core', 'shareapi_allow_group_sharing', 'no');
					self::assertTrue($client->tool('deck_link_card_file', $args)->isError());
					self::assertTrue($client->tool('deck_link_card_file', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']])->isError());
					self::assertSame([], $this->original($f)['links']);
					self::assertTrue(DeckFixture::run(DeckFixture::OWNER, 'link-native', $f)['accepted']);
					self::assertSame($f['hash'], DeckFixture::run(DeckFixture::READER, 'link-inspect', $f)['hash']);
					self::assertSame(1, $this->original($f)['links'][0]['permissions']);
					$this->observe("A2 $kind: allow on previews; off refuses preview+confirm; native12 bypass grants READ1 bytes.");
				});
			}
			$config->setAppValue('core', 'shareapi_allow_group_sharing', 'no');
			$this->fixture([], function (array $f) use ($client, $config): void {
				$args = ['cardId' => $f['cardId'], 'fileId' => $f['fileId']];
				$plan = $this->data($client->tool('deck_link_card_file', $args));
				self::assertFalse($plan['recipients']['hasGroupAcl']);
				self::assertFalse($plan['recipients']['hasCircleAcl']);
				foreach ([['no', 'yes'], ['yes', 'no']] as [$default, $enforce]) {
					$config->setAppValue('core', 'shareapi_default_internal_expire_date', $default);
					$config->setAppValue('core', 'shareapi_enforce_internal_expire_date', $enforce);
					self::assertTrue($this->data($client->tool('deck_link_card_file', $args))['requiresConfirmation']);
				}
				$config->setAppValue('core', 'shareapi_default_internal_expire_date', 'yes');
				$config->setAppValue('core', 'shareapi_enforce_internal_expire_date', 'yes');
				self::assertTrue($client->tool('deck_link_card_file', $args)->isError());
				self::assertTrue($client->tool('deck_link_card_file', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']])->isError());
				self::assertSame([], $this->original($f)['links']);
				self::assertTrue(DeckFixture::run(DeckFixture::OWNER, 'link-native', $f)['accepted']);
				self::assertSame($f['hash'], DeckFixture::run(DeckFixture::READER, 'link-inspect', $f)['hash']);
				$this->observe('M1 expiry: inactive combinations allow preview; both active refuse MCP; native12 still grants bytes. User-only ACL allowed with group sharing off.');
			});
		});
	}

	/**
	 * Prove real authenticated Guest Basic/Bearer denial with hide_users on and off, without lifecycle injection.
	 *
	 * @return void
	 */
	public function testGuestBearerAndBasicNeverCreateLinksRegardlessOfHideUsers(): void {
		$this->scenario(function (): void {
			foreach (['no', 'yes'] as $hide) {
				Server::get(IConfig::class)->setAppValue('guests', 'hide_users', $hide);
				$this->fixture(['guest' => self::GUEST], function (array $f) use ($hide): void {
					self::assertFalse(DeckFixture::run(DeckFixture::READER, 'link-inspect', $f)['readable'], 'Board reader has no access to the private file before the Guest attempt');
					foreach (['basic', 'bearer'] as $kind) {
						$client = $this->mcp($kind);
						$read = $client->tool('files_read', ['path' => '/' . $f['fileName']]);
						self::assertTrue($read->ok(), $read->describe());
						$args = ['cardId' => $f['cardId'], 'fileId' => $f['fileId']];
						self::assertTrue($client->tool('deck_link_card_file', $args)->isError());
						self::assertTrue($client->tool('deck_link_card_file', $args + ['confirm' => true, 'confirm_shared' => true,
							'plan_state' => str_repeat('0', self::PLAN_HASH_HEX_LENGTH)])->isError());
						self::assertSame([], $this->original($f)['links']);
						self::assertFalse(DeckFixture::run(DeckFixture::READER, 'link-inspect', $f)['readable'], 'The previously excluded board reader still has no file access');
						$this->observe("A1 $kind Guest hide_users=$hide: legitimate actor file read; preview and forged confirm refused; no type12 association and board reader remains without file access.");
					}
				});
			}
		});
	}

	/**
	 * Every real confirm is a new authenticated HTTP request with fresh native state and policy snapshot.
	 *
	 * @return void
	 */
	public function testNativeChangesBetweenPreviewAndConfirmPreventCreation(): void {
		$this->scenario(function (): void {
			$client = new McpClient(LoginFactory::bearer(DeckFixture::EDITOR));
			foreach (['etag', 'member', 'edit', 'share', 'archive'] as $change) {
				$this->fixture([], function (array $f) use ($client, $change): void {
					$args = ['cardId' => $f['cardId'], 'fileId' => $f['fileId']];
					$plan = $this->data($client->tool('deck_link_card_file', $args));
					$beforeVersion = DeckFixture::run(DeckFixture::OWNER, 'link-version', $f);
					$mutation = DeckFixture::run(DeckFixture::OWNER, 'link-change', $f + ['change' => $change]);
					$afterVersion = DeckFixture::run(DeckFixture::OWNER, 'link-version', $f);
					if ($change === 'etag') {
						self::assertNotSame($beforeVersion['etag'], $afterVersion['etag'], 'Native file version actually changed in a fresh process');
					}
					$reply = $client->tool('deck_link_card_file', $args + ['confirm' => true, 'confirm_shared' => true,
						'plan_state' => $plan['plan_state']]);
					self::assertFalse(($reply->structured() ?: (array)$reply->json())['confirmed'] ?? false, $reply->describe());
					self::assertTrue($reply->isError() || (($reply->structured() ?: (array)$reply->json())['requiresConfirmation'] ?? false), $reply->describe());
					$observed = DeckFixture::run(DeckFixture::OWNER, 'link-inspect', $f);
					self::assertSame([], $observed['links']);
					self::assertSame($f['fileId'], $observed['fileId']);
					self::assertSame($afterVersion['hash'], $observed['hash'], 'The tool preserves the current original bytes, including a deliberate prior mutation');
					if ($change !== 'etag') {
						self::assertSame($f['hash'], $observed['hash']);
					}
					self::assertSame($f['independent'], $observed['independent']['id']);
					$this->observe("Fresh Bearer confirm after $change: no new link and independent share preserved.");
				});
			}
		});
	}

	/**
	 * New requests re-read grants, Files scope and exclusion tags; no stale plan can bypass any gate.
	 *
	 * @return void
	 */
	public function testFreshGrantsScopeAndExclusionRejectStalePlans(): void {
		$this->scenario(function (): void {
			$client = new McpClient(LoginFactory::bearer(DeckFixture::EDITOR));
			$config = Server::get(IConfig::class);
			$policy = Server::get(GrantPolicy::class);
			foreach (['deck.edit', 'files.read', 'files.share', 'scope', 'tag'] as $change) {
				$this->fixture([], function (array $f) use ($client, $config, $policy, $change): void {
					$args = ['cardId' => $f['cardId'], 'fileId' => $f['fileId']];
					$plan = $this->data($client->tool('deck_link_card_file', $args));
					$scenario = new GuestScenario();
					$restore = static function (): void {};
					$scenario->run(function () use ($args, $plan, $change, $f, $client, $config, $policy, &$restore): void {
						if ($change === 'scope') {
							$before = $policy->within(DeckFixture::EDITOR, fn () => $policy->scopeItems(DeckFixture::EDITOR, 'files'));
							$restore = static fn () => DeckFixture::run(DeckFixture::OWNER, 'files-scope', ['actor' => DeckFixture::EDITOR, 'items' => $before]);
							DeckFixture::run(DeckFixture::OWNER, 'files-scope', ['actor' => DeckFixture::EDITOR, 'items' => []]);
						} elseif ($change === 'tag') {
							$tag = Server::get(ISystemTagManager::class)->createTag('link-hidden-' . bin2hex(random_bytes(self::PARITY_SUFFIX_BYTES)), true, true);
							$exists = in_array(VisibilityGuard::CONFIG_KEY, $config->getAppKeys('mcp'), true);
							$before = $config->getAppValue('mcp', VisibilityGuard::CONFIG_KEY, '[]');
							$restore = static function () use ($config, $before, $exists, $tag, $f): void {
								$cleanup = new GuestScenario();
								$cleanup->run(static function (): void {}, static function () use ($cleanup, $config, $before, $exists, $tag, $f): void {
									$cleanup->cleanup(static fn () => Server::get(ISystemTagObjectMapper::class)->unassignTags((string)$f['fileId'], 'files', [$tag->getId()]));
									$cleanup->cleanup(static fn () => Server::get(ISystemTagManager::class)->deleteTags([$tag->getId()]));
									$cleanup->cleanup(static function () use ($config, $before, $exists): void {
										if ($exists) {
											$config->setAppValue('mcp', VisibilityGuard::CONFIG_KEY, $before);
										} else {
											$config->deleteAppValue('mcp', VisibilityGuard::CONFIG_KEY);
										}
									});
								});
							};
							Server::get(ISystemTagObjectMapper::class)->assignTags((string)$f['fileId'], 'files', [$tag->getId()]);
							$config->setAppValue('mcp', VisibilityGuard::CONFIG_KEY, json_encode([$tag->getId()], JSON_THROW_ON_ERROR));
						} else {
							[$module, $operation] = explode('.', $change);
							$key = 'grant_' . $module . '_' . $operation;
							$values = $config->getUserValueForUsers('mcp', $key, [DeckFixture::EDITOR]);
							$before = $values[DeckFixture::EDITOR] ?? null;
							$restore = static function () use ($before, $module, $operation, $policy): void {
								if ($before === null) {
									Server::get(PolicyWriter::class)->resetOverride(DeckFixture::EDITOR, $module, $operation);
								} else {
									$policy->setGrant(DeckFixture::EDITOR, $module, $operation, $before === '1');
								}
							};
							$policy->setGrant(DeckFixture::EDITOR, $module, $operation, false);
						}
						$reply = $client->tool('deck_link_card_file', $args + ['confirm' => true, 'confirm_shared' => true,
							'plan_state' => $plan['plan_state']]);
						self::assertFalse($reply->ok(), $reply->describe());
						self::assertStringNotContainsString($f['fileName'], $reply->raw);
						self::assertSame([], $this->original($f)['links']);
						if ($change === 'tag' || $change === 'scope') {
							$missing = $client->tool('deck_link_card_file', ['cardId' => $f['cardId'], 'fileId' => PHP_INT_MAX]);
							self::assertSame($missing->result(), $client->tool('deck_link_card_file', $args)->result());
						}
						$this->observe("New Bearer confirm after $change: denied, no hidden metadata and no share created.");
					}, static function () use (&$restore): void { $restore(); });
				});
			}
		});
	}

	/**
	 * The MCP guard preserves native global/exclusion/reshare refusals and closes group-only's type12 bypass.
	 *
	 * @return void
	 */
	public function testCoreSharingPoliciesAndSameGroupGroupOnlyControl(): void {
		$this->scenario(function (): void {
			$config = Server::get(IConfig::class);
			$owner = new McpClient(LoginFactory::bearer(DeckFixture::OWNER));
			$editor = new McpClient(LoginFactory::bearer(DeckFixture::EDITOR));
			$group = Server::get(\OCP\IGroupManager::class)->get(DeckFixture::GROUP);
			$account = Server::get(\OCP\IUserManager::class)->get(DeckFixture::OWNER);
			$wasMember = $group->inGroup($account);
			$group->addUser($account);
			$scenario = new GuestScenario();
			$scenario->run(function () use ($owner, $editor, $config): void {
				foreach (['global', 'excluded', 'group-only', 'reshare'] as $kind) {
					$config->setAppValue('core', 'shareapi_enabled', 'yes');
					$config->setAppValue('core', 'shareapi_exclude_groups', 'no');
					$config->setAppValue('core', 'shareapi_only_share_with_group_members', 'no');
					$config->setAppValue('core', 'shareapi_allow_resharing', 'yes');
					$this->fixture([], function (array $f) use ($kind, $owner, $editor, $config): void {
						$client = $kind === 'reshare' ? $editor : $owner;
						$args = ['cardId' => $f['cardId'], 'fileId' => $f['fileId']];
						$plan = $this->data($client->tool('deck_link_card_file', $args));
						if ($kind === 'global') {
							$config->setAppValue('core', 'shareapi_enabled', 'no');
						} elseif ($kind === 'excluded') {
							$config->setAppValue('core', 'shareapi_exclude_groups_list', json_encode([DeckFixture::GROUP], JSON_THROW_ON_ERROR));
							$config->setAppValue('core', 'shareapi_exclude_groups', 'yes');
						} elseif ($kind === 'group-only') {
							$config->setAppValue('core', 'shareapi_only_share_with_group_members', 'yes');
						} else {
							$config->setAppValue('core', 'shareapi_allow_resharing', 'no');
						}
						self::assertTrue($client->tool('deck_link_card_file', $args)->isError());
						self::assertTrue($client->tool('deck_link_card_file', $args + ['confirm' => true, 'confirm_shared' => true,
							'plan_state' => $plan['plan_state']])->isError());
						self::assertSame([], $this->original($f)['links']);
						$native = DeckFixture::run($kind === 'reshare' ? DeckFixture::EDITOR : DeckFixture::OWNER, 'link-native', $f);
						self::assertSame($kind === 'group-only', $native['accepted'], json_encode($native));
						if ($native['accepted']) {
							self::assertSame($f['hash'], DeckFixture::run(DeckFixture::READER, 'link-inspect', $f)['hash']);
						}
						$this->observe('Core policy ' . $kind . ': MCP refused both phases, native ' . json_encode($native));
					});
				}
			}, static function () use ($group, $account, $wasMember): void {
				if (!$wasMember) {
					$group->removeUser($account);
				}
			});
		});
	}

	/**
	 * A received READ+SHARE file needs shared confirmation but never UPDATE on the original.
	 *
	 * @return void
	 */
	public function testPermittedReshareNeedsSharedConfirmationWithoutFileUpdate(): void {
		$this->scenario(function (): void {
			$client = new McpClient(LoginFactory::bearer(DeckFixture::EDITOR));
			$this->fixture([], function (array $f) use ($client): void {
				$args = ['cardId' => $f['cardId'], 'fileId' => $f['fileId']];
				$plan = $this->data($client->tool('deck_link_card_file', $args));
				self::assertTrue($plan['reshare']);
				$confirmed = $args + ['confirm' => true, 'plan_state' => $plan['plan_state']];
				self::assertSame('shared', $this->data($client->tool('deck_link_card_file', $confirmed))['scope']);
				self::assertSame([], $this->original($f)['links']);
				$result = $this->data($client->tool('deck_link_card_file', $confirmed + ['confirm_shared' => true]));
				self::assertTrue($result['confirmed']);
				self::assertSame(1, $result['permissions']);
				self::assertSame($f['hash'], DeckFixture::run(DeckFixture::READER, 'link-inspect', $f)['hash']);
				$this->original($f);
			});
		});
	}

}
