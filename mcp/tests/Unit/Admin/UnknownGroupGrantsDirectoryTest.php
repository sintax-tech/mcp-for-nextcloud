<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Access\Grants\GroupDirectory;
use OCA\Mcp\Access\Policy\GroupSourceClassifier;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/** Unknown-only policies remain diagnosable without being counted as known grants. */
final class UnknownGroupGrantsDirectoryTest extends TestCase {
	/** @return void Filtering, counts, search and policy-only identities use only recognized grant rows. */
	public function testGrantedFilterExcludesUnknownOnlyGroupsButKeepsMixedAndEligibility(): void {
		$data = new InMemoryConfig();
		$config = $data->mock($this);
		$resolver = InMemoryConfig::resolver($config);
		$db = InMemoryConfig::groupDatabase($config)->connection();
		foreach (['unknown-only' => ['future' => ['read']], 'known' => ['notes' => ['read']],
			'mixed' => ['notes' => ['edit'], 'future' => ['read']], 'eligible' => ['_system' => ['eligible']]] as $gid => $grants) {
			InMemoryConfig::groupPolicy($config, $gid, $grants);
		}
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('search')->willReturn([]);
		$manager->method('getBackends')->willReturn([]);
		$directory = new GroupDirectory($manager, $db, new GroupGrantsMapper($db), new GroupLifeMapper($db),
			new GroupSourceClassifier($manager), $resolver, InMemoryConfig::state($config));
		$all = $directory->page();
		$this->assertSame(['all' => 4, 'granted' => 3, 'blocked' => null], $all['counts']);
		$this->assertSame(['eligible', 'known', 'mixed', 'unknown-only'], array_column($all['groups'], 'groupId'));
		$this->assertCount(1, $all['groups'][3]['unknownGrants']);
		$granted = $directory->page('', 1, 'granted');
		$this->assertSame(['eligible', 'known', 'mixed'], array_column($granted['groups'], 'groupId'));
		$this->assertSame(3, $granted['total']);
		$this->assertCount(1, $granted['groups'][2]['unknownGrants']);
		$search = $directory->page('unknown', 1, 'granted');
		$this->assertSame([], $search['groups']);
		$this->assertSame(['all' => 1, 'granted' => 0, 'blocked' => null], $search['counts']);
		$this->assertSame(['unknown-only'], array_column($directory->page('unknown')['groups'], 'groupId'));
	}
}
