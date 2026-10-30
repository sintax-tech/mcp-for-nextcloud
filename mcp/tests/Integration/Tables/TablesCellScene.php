<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use RuntimeException;

/**
 * The scene ci/tables/seed.php created in a Tables cell: its users and the ids of what it created.
 */
final class TablesCellScene {
	/** Owner of the two tables, the two views and the application. */
	public const ALICE = 'mcp-it-alice';

	/** Has the view "Approved" shared with them, nothing else. */
	public const BOB = 'mcp-it-bob';

	/** Has the table "Budget" shared with them, to read only. */
	public const CAROL = 'mcp-it-tables-carol';

	/** Has nothing. */
	public const DAVE = 'mcp-it-tables-dave';

	/** Belongs to the application "Team app", which holds the view "Summary". */
	public const ERIN = 'mcp-it-tables-erin';

	/** Has the table "Budget" shared with them, with manage. */
	public const FRANK = 'mcp-it-tables-frank';

	/** Every user of the scene. */
	public const USERS = [self::ALICE, self::BOB, self::CAROL, self::DAVE, self::ERIN, self::FRANK];

	/** An id no table, view or column has. */
	public const MISSING = 987654321;

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The ids of the scene.
	 *
	 * @return array{tables:array{budget:int, notes:int}, columns:array{item:int, amount:int, status:int, secret:int}, views:array{approved:int, summary:int}, context:int} the ids
	 * @throws RuntimeException outside a Tables cell
	 */
	public static function ids(): array {
		$file = (string)getenv('MCP_IT_TABLES_SCENE');
		if ($file === '' || !is_file($file)) {
			throw new RuntimeException('The Tables scene is written by ci/tables/cell.sh provision; this runs only in a Tables cell');
		}
		return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	}
}
