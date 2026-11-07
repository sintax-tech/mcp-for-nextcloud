<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Grants;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;

/** Keeps the dotted removal operation independent from editing options. */
final class PriorityGrantCatalogTest extends TestCase {
	/** @return void exact pairs are known, while near spellings never alias an operation */
	public function testExactCatalogPairs(): void {
		foreach (['polls' => ['configure', 'options', 'options.remove', 'share', 'invite'], 'tables' => ['schema', 'share']] as $module => $operations) {
			foreach ($operations as $operation) {
				$this->assertTrue(GrantPolicy::inCatalog($module, $operation), "$module.$operation");
			}
		}
		foreach (['options_remove', 'options.Remove', 'remove'] as $operation) {
			$this->assertFalse(GrantPolicy::inCatalog('polls', $operation));
		}
	}

	/** @return void granting and denying either operation leaves the other unchanged */
	public function testOptionsAndRemovalAreIndependent(): void {
		$store = new InMemoryConfig();
		$policy = InMemoryConfig::policy($store->mock($this), new InMemoryOAuthStore());
		foreach ([['options', 'options.remove'], ['options.remove', 'options']] as [$first, $second]) {
			$policy->setGrant('alice', 'polls', $first, true);
			$policy->setGrant('alice', 'polls', $second, false);
			$this->assertTrue($policy->granted('alice', 'polls', $first));
			$this->assertFalse($policy->granted('alice', 'polls', $second));
			$policy->setGrant('alice', 'polls', $second, true);
			$policy->setGrant('alice', 'polls', $first, false);
			$this->assertFalse($policy->granted('alice', 'polls', $first));
			$this->assertTrue($policy->granted('alice', 'polls', $second));
		}
	}
}
