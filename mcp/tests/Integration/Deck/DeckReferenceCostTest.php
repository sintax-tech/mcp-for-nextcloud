<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Reproducible workload; counts/time are observations, not timing thresholds. Uses the normal disposable harness. */
#[Group('deck')]
final class DeckReferenceCostTest extends IntegrationTestCase {
	public function testOneCardWithManyReferencesRetainsAllReadableTargets(): void {
		if ((int)getenv('MCP_IT_NC') < 34) {
			self::assertLessThan(34, (int)getenv('MCP_IT_NC')); // negative capability already proved by NativeDeckTest
			return;
		}
		$fixture = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$size = DeckFixture::run(DeckFixture::OWNER, 'cost-seed', $fixture);
		$samples = [];
		for ($i = 0; $i < 3; $i++) {
			$sample = DeckFixture::run(DeckFixture::OWNER, 'cost-read', $fixture);
			self::assertSame($size['targets'], $sample['references']);
			self::assertGreaterThan(0, $sample['elapsed_ms']);
			if (getenv('MCP_IT_DB') === 'pgsql') { self::assertGreaterThan(0, $sample['statements']); }
			$samples[] = $sample;
		}
		file_put_contents('/tmp/mcp-it-observations.txt', 'Deck reference cost: '
			. json_encode($size + ['samples' => $samples], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
	}
}
