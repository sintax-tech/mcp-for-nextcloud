<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;

/** Persisted pre-feature Full is only its old operation cells, never an implicit permission for a new operation. */
final class DeleteGrantUpgradeTest extends TestCase {
	/** @return void Reopening old persisted Full preserves denial until an explicit admin command grants delete. */
	public function testOldFullAndUnconfiguredUsersStayDeniedAcrossInstances(): void {
		$store = new InMemoryConfig();
		$old = InMemoryConfig::policy($store->mock($this), new InMemoryOAuthStore());
		foreach (['read', 'edit', 'create', 'move', 'restore', 'share', 'link'] as $operation) {
			$old->setGrant('alice', 'files', $operation, true);
		}
		$fresh = InMemoryConfig::policy($store->mock($this), new InMemoryOAuthStore());
		self::assertFalse($fresh->granted('alice', 'files', 'delete'));
		self::assertFalse($fresh->granted('bob', 'files', 'delete'));
		self::assertFalse(GrantPolicy::defaultGranted('files', 'delete'));
		$fresh->setGrant('alice', 'files', 'delete', true);
		self::assertTrue($fresh->granted('alice', 'files', 'delete'));
		self::assertFalse($fresh->granted('bob', 'files', 'delete'));
		$fresh->setGrant('alice', 'files', 'delete', false);
		self::assertFalse($fresh->granted('alice', 'files', 'delete'));
	}
	/** @return void An old Full group grants only its stored operations; explicit group opt-in and user deny remain authoritative. */
	public function testOldFullGroupDoesNotAcquireDeleteAcrossInstances(): void {
		$store = new InMemoryConfig();
		$store->groupBackends = [new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(static fn (): array => ['team' => ['alice']])];
		$config = $store->mock($this);
		$oldOperations = ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'];
		InMemoryConfig::groupPolicy($config, 'team', ['_system' => ['eligible'], 'files' => $oldOperations]);
		$fresh = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		self::assertTrue($fresh->eligible('alice'));
		self::assertTrue($fresh->granted('alice', 'files', 'edit'));
		self::assertFalse($fresh->granted('alice', 'files', 'delete'));
		self::assertFalse($fresh->granted('bob', 'files', 'delete'));
		InMemoryConfig::groupPolicy($config, 'team', ['_system' => ['eligible'], 'files' => [...$oldOperations, 'delete']]);
		$explicit = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		self::assertTrue($explicit->granted('alice', 'files', 'delete'));
		$explicit->setGrant('alice', 'files', 'delete', false);
		self::assertFalse($explicit->granted('alice', 'files', 'delete'));
	}

}
