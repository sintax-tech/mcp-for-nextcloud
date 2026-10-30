<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Write;

use RuntimeException;

/**
 * The row write scene ci/tables/seed-write.php created in a Tables cell: its users and the ids of what it created.
 */
final class WriteCellScene {
	/** Owner of "Plans", "Elsewhere" and the two views. */
	public const OWNER = 'mcp-it-write-owner';

	/** May create rows through both views, nothing else. */
	public const VIEWER = 'mcp-it-write-viewer';

	/** May read and update rows through "Write view". */
	public const EDITOR = 'mcp-it-write-editor';

	/** May read "Plans" only. */
	public const READER = 'mcp-it-write-reader';

	/** May read, create and update in "Plans", shared by the owner. */
	public const SHARED = 'mcp-it-write-shared';

	/** Every user of the scene. */
	public const USERS = [self::OWNER, self::VIEWER, self::EDITOR, self::READER, self::SHARED];

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
	 * @return array{tables:array{plans:int, elsewhere:int}, columns:array<string, int>, rows:list<int>, views:array{write:int, foreign:int, stars:int, starsBound:int}} the ids
	 * @throws RuntimeException outside a Tables cell
	 */
	public static function ids(): array {
		$file = (string)getenv('MCP_IT_TABLES_WRITE_SCENE');
		if ($file === '' || !is_file($file)) {
			throw new RuntimeException('The row write scene is written by ci/tables/cell.sh provision; this runs only in a Tables cell');
		}
		return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	}
}
