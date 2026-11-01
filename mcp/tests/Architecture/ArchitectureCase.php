<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Inventory\{AppTree, TicketState};
use OCA\Mcp\Tests\Architecture\Dependencies\{BoundaryRules, BoundaryLedger};
use PHPUnit\Framework\TestCase;

/** Common access to the same live inventory and checkers used by fixtures. */
abstract class ArchitectureCase extends TestCase {
	protected static function tree(): AppTree {
		static $tree;
		return $tree ??= new AppTree(dirname(__DIR__, 2));
	}
	protected static function tickets(): TicketState { return TicketState::load(__DIR__); }
	protected static function json(string $name): array { return AppTree::json(__DIR__ . '/' . $name . '.json'); }
	protected function assertBoundary(string $rule): void {
		$filter = static fn ($e) => $e['rule'] === $rule;
		$violations = array_values(array_filter(BoundaryRules::violations(self::tree()), $filter));
		$ledger = array_values(array_filter(self::json('boundary-exceptions')['exceptions'], $filter));
		self::assertSame([], BoundaryLedger::problems($violations, $ledger, self::tickets()));
	}
	protected static function fixture(string $path, string $code): array { return BoundaryRules::violations(self::tree(), [$path => '<?php ' . $code]); }
}
