<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Grants;

use OCA\Mcp\Access\Grants\GrantCatalog;
use OCA\Mcp\Access\Grants\GrantMatrix;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\ArrayGroupBackend;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;

/** Routine grants are explicit opt-ins, including when an existing installation reads its saved policy. */
final class RoutineGrantCatalogTest extends TestCase {
	/** @var array<string,list<string>> Exact approved pairs; Mail saving belongs to MA-2. */
	private const NEW_GRANTS = [
		'mail' => ['save'], 'deck' => ['labels'],
		'files' => ['tags', 'comments'], 'calendar' => ['availability'],
		'activity' => ['read'], 'notifications' => ['read', 'dismiss'],
		'availability' => ['read', 'absence'], 'status' => ['read', 'edit'],
		'routines' => ['prepare', 'meeting', 'mailtask'],
	];

	/** @return void A clean installation denies every new pair, including Mail saving, without adding new tool modules. */
	public function testNewDefaultsAndExactPairs(): void {
		$data = new InMemoryConfig();
		$policy = InMemoryConfig::policy($data->mock($this), new InMemoryOAuthStore());
		foreach (self::NEW_GRANTS as $module => $operations) {
			foreach ($operations as $operation) {
				self::assertTrue(GrantPolicy::inCatalog($module, $operation));
				self::assertFalse(GrantPolicy::defaultGranted($module, $operation));
				self::assertFalse($policy->granted('alice', $module, $operation));
			}
		}
		self::assertTrue(GrantPolicy::inCatalog('mail', 'save'));
		self::assertFalse(GrantPolicy::inCatalog('status', 'write'));
		self::assertFalse(GrantPolicy::inCatalog('absence', 'write'));
		self::assertSame([], $data->user);
	}

	/** @return void Reading old overrides and group rows preserves every existing permission and all persisted bytes. */
	public function testUpgradeDoesNotGrantAccessOrRewriteSavedPolicies(): void {
		$data = new InMemoryConfig();
		$data->groupBackends = [new ArrayGroupBackend(static fn (): array => ['team' => ['bob']])];
		$config = $data->mock($this);
		$policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$legacy = [];
		foreach (GrantCatalog::CATALOG as $module => $operations) {
			$kept = array_values(array_diff($operations, self::NEW_GRANTS[$module] ?? []));
			if ($kept === []) continue;
			$legacy[$module] = $kept;
			foreach ($kept as $operation) $policy->setGrant('alice', $module, $operation, true);
		}
		$policy->setGrant('alice', 'files', 'edit', false);
		InMemoryConfig::groupPolicy($config, 'team', $legacy);
		$db = InMemoryConfig::groupDatabase($config)->dbal;
		$rows = $db->fetchAllAssociative('SELECT * FROM mcp_group_grants ORDER BY module, operation');
		$saved = [$data->user, $data->app, $data->revision, $data->authEpoch];
		$state = $policy->forUsers(['alice', 'bob']);
		foreach (['alice', 'bob'] as $uid) {
			foreach ($legacy as $module => $operations) {
				foreach ($operations as $operation) {
					$expected = !($uid === 'alice' && $module === 'files' && $operation === 'edit');
					self::assertSame($expected, $state[$uid]['grants'][$module][$operation]);
					self::assertSame($expected, $policy->granted($uid, $module, $operation));
				}
			}
			foreach (self::NEW_GRANTS as $module => $operations) {
				foreach ($operations as $operation) {
					self::assertFalse($state[$uid]['grants'][$module][$operation]);
					self::assertFalse($policy->granted($uid, $module, $operation));
				}
			}
		}
		self::assertSame($saved, [$data->user, $data->app, $data->revision, $data->authEpoch]);
		self::assertSame($rows, $db->fetchAllAssociative('SELECT * FROM mcp_group_grants ORDER BY module, operation'));
	}

	/** @return void An explicit grant takes effect per user, while every other new operation stays denied. */
	public function testExplicitOptInIsIndependent(): void {
		$data = new InMemoryConfig();
		$policy = InMemoryConfig::policy($data->mock($this), new InMemoryOAuthStore());
		foreach (self::NEW_GRANTS as $module => $operations) {
			foreach ($operations as $operation) {
				$policy->setGrant('alice', $module, $operation, true);
				self::assertTrue($policy->granted('alice', $module, $operation));
				self::assertFalse($policy->granted('bob', $module, $operation));
				$policy->setGrant('alice', $module, $operation, false);
				self::assertFalse($policy->granted('alice', $module, $operation));
			}
		}
	}

	/** @return void All added operations are represented in the highest tier of the actual manifest sources. */
	public function testFutureFullLevelsAndNativeAppMapping(): void {
		$root = dirname(__DIR__, 4);
		$manifest = file($root . '/src/admin.manifest', FILE_IGNORE_NEW_LINES);
		foreach (self::NEW_GRANTS as $module => $operations) {
			$path = 'admin/access/levels/' . $module . '.js';
			self::assertContains($path, $manifest);
			$source = file_get_contents($root . '/src/' . $path);
			preg_match('/catalog\.tiers\.' . $module . ' = (\[.*\])/', $source, $match);
			$tiers = json_decode(str_replace("'", '"', $match[1]), true, flags: JSON_THROW_ON_ERROR);
			self::assertEqualsCanonicalizing(GrantCatalog::CATALOG[$module], array_merge(...$tiers));
			if (in_array($module, ['files', 'calendar'], true)) {
				self::assertSame(['read'], $tiers[0]);
				foreach ($operations as $operation) self::assertContains($operation, $tiers[array_key_last($tiers)]);
			}
		}
		self::assertSame(['activity', 'notifications', 'dav', 'user_status', ''], array_map(
			static fn (string $module): string => GrantMatrix::MODULE_APPS[$module],
			['activity', 'notifications', 'availability', 'status', 'routines']
		));
	}

	/** @return void The sole frontend denied-read list stays equal to the backend and Routines names its narrow tier. */
	public function testFrontendDefaultsAndPreparationLabelCannotDrift(): void {
		$root = dirname(__DIR__, 4);
		$source = file_get_contents($root . '/src/admin/access/grant-defaults.js');
		preg_match('/const deniedReads = (\[.*\])/', $source, $match);
		self::assertSame(GrantPolicy::DENIED_READS, json_decode(str_replace("'", '"', $match[1]), true, flags: JSON_THROW_ON_ERROR));
		self::assertStringContainsString('grantDefaults.granted(module, operation)', file_get_contents($root . '/src/admin/groups/drafts.js'));
		self::assertStringContainsString("catalog.tierNames.routines = ['prepare', 'full']", file_get_contents($root . '/src/admin/access/levels/routines.js'));
	}

}
