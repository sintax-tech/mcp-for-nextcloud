<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;

/** Keeps real repository move controls active after the planned source has already moved. */
final class CurrentMoveSource {
	/** Prevents instantiation of fixture support. */
	private function __construct() {
	}

	/**
	 * Uses a present chain step as the source and gives the fixture a later, isolated destination.
	 * GrantCatalog becomes the catalog reader's source after extraction, preserving frontend refusal controls.
	 *
	 * @param array<string,string> $tree live repository sources
	 * @param array<string,mixed> $data decoded production map, changed only in memory
	 * @param string $old requested historical path
	 * @return string actual source path
	 */
	public static function path(array $tree, array &$data, string $old): string {
		if (isset($tree[$old])) {
			return $old;
		}
		$map = FqcnMap::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
		foreach ($data['entries'] as &$entry) {
			if ($entry['path'] !== $old) {
				continue;
			}
			$current = $entry['target'];
			$catalog = implode('\\', ['OCA', 'Mcp', 'Access', 'Grants', 'GrantCatalog']);
			if (basename($old) === implode('', ['Grant', 'Policy', '.php']) && isset($tree[$map->pathOf($catalog)])) {
				$current = $catalog;
			}
			$path = $map->pathOf($current);
			$entry = ['path' => $path, 'fqcn' => $current, 'target' => substr($current, 0, strrpos($current, '\\'))
				. '\\MoveProof\\' . basename($path, '.php'), 'owner' => 'Proof', 'ticket' => 'RE-X'];
			return $path;
		}
		throw new \RuntimeException("No mapped source for $old");
	}

	/**
	 * Injects the historical unprepared reader shape for the negative control, even after real readers are prepared.
	 *
	 * @param array<string,string> $tree live fixture tree
	 * @param string $old requested source path
	 * @return array<string,string> tree with the explicitly unprepared negative reader
	 */
	public static function unprepared(array $tree, string $old): array {
		$reader = 'tests/Unit/Admin/AdminGroupAssetsTest.php';
		$relative = substr($old, 4);
		$code = "<?php class UnpreparedMoveReader { function check() { \$lib = dirname(__DIR__, 3) . '/lib'; sort(\$paths); "
			. "\$this->assertSame(['$relative'], \$paths); \$file = '" . basename($relative) . "'; file_get_contents(\$lib . '/" . dirname($relative)
			. "/' . \$file); } }";
		$tree[$reader] = $code;
		return $tree;
	}
}
