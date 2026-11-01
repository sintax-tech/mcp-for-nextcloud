<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tools\Polls\PollsModule;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\TestCase;

/** Exact catalog update keeps the original read/schema/no-write checks and adds independent protected grants. */
final class PollsReadCatalogAssertions {
	/**
	 * Checks all authorized tools, without turning the original exact catalog into an unchecked contains assertion.
	 * @param TestCase $test assertions
	 * @param PollsModule $module actual module
	 * @return void
	 */
	public static function check(TestCase $test, PollsModule $module): void {
		$tools = PollsCreateCatalogAssertions::separate($test, $module->definitions());
		$test->assertSame(['polls_list_polls', 'polls_read_poll', 'polls_list_votes', 'polls_read_results', 'polls_list_links'], array_column($tools, 'name'));
		$test->assertSame(['read', 'results', 'links', 'create'], GrantPolicy::CATALOG['polls']);
		foreach ($tools as $tool) {
			$test->assertSame(['polls', 'read', 'polls'], [$tool['module'], $tool['operation'], $tool['app']]);
			$test->assertFalse($tool['inputSchema']['additionalProperties']);
			$test->assertFalse(WriteGate::isWrite($tool));
			$expected = match ($tool['name']) {'polls_list_votes', 'polls_read_results' => ['results'], 'polls_list_links' => ['links'], default => null};
			$test->assertSame($expected, $tool['grantAnyOf'] ?? null);
		}
		$test->assertTrue(GrantPolicy::defaultGranted('polls', 'read'));
		foreach (['results', 'links', 'create', 'edit'] as $operation) {
			$test->assertFalse(GrantPolicy::defaultGranted('polls', $operation));
		}
	}
}
