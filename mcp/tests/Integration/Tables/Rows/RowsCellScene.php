<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Rows;

use RuntimeException;

/**
 * The rows scene ci/tables/seed-rows.php created in a Tables cell: its users and the ids of what it created.
 */
final class RowsCellScene {
	/** Owner of "Events", "Other" and the two views. */
	public const OWNER = 'mcp-it-rows-owner';

	/** Has the views "Open events" and "Broken" shared with them. */
	public const VIEWER = 'mcp-it-rows-viewer';

	/** Has "Events" shared with them, to read. */
	public const READER = 'mcp-it-rows-reader';

	/** Has "Events" shared with them, to create rows only. */
	public const CREATOR = 'mcp-it-rows-creator';

	/** Belongs to the application "Rows app", which holds "Open events". */
	public const MEMBER = 'mcp-it-rows-member';

	/** Has nothing of the rows scene. */
	public const STRANGER = 'mcp-it-tables-dave';

	/** Every user of the scene. */
	public const USERS = [self::OWNER, self::VIEWER, self::READER, self::CREATOR, self::MEMBER, self::STRANGER];

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
	 * @return array{tables:array{events:int, other:int}, columns:array<string, int>, rows:list<int>, views:array{open:int, broken:int}, context:int} the ids
	 * @throws RuntimeException outside a Tables cell
	 */
	public static function ids(): array {
		$file = (string)getenv('MCP_IT_TABLES_ROWS_SCENE');
		if ($file === '' || !is_file($file)) {
			throw new RuntimeException('The rows scene is written by ci/tables/cell.sh provision; this runs only in a Tables cell');
		}
		return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	}
}
