<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Inventory;

/** D6 applies to authored app source only; class identities survive mapped renames. */
final class SizeRules {
	/** Frozen source-path maxima, all removed by RE-12b; never a permanent exemption. */
	private const SOURCE_MAXIMA = ['src/admin/admin-file-write-policy.js' => 319, 'src/admin/admin-grants.js' => 2295, 'src/admin/admin-groups.js' => 580, 'src/admin/admin-inspector.js' => 515, 'src/admin/admin-level-popover.js' => 513, 'src/admin/admin-overrides.js' => 753, 'src/admin/admin-picker.js' => 409, 'src/admin/admin-scope.js' => 693, 'templates/admin.php' => 387];

	/** @param AppTree $tree Live app. @param array<mixed> $inventory Live ceilings. @param array<mixed> $baseline Frozen maximum membership/ceilings. @param array<mixed> $paths Source path exceptions. @param TicketState $tickets Completion state. @return list<string> Violations. */
	public static function problems(AppTree $tree, array $inventory, array $baseline, array $paths, TicketState $tickets): array {
		$errors = [];
		$limits = [];
		$seen = [];
		if (($inventory['limit'] ?? null) !== 300) { $errors[] = 'D6 limit must remain 300'; }
		foreach (['final', 'transitional'] as $kind) {
			$original = array_column($baseline[$kind], null, 'fqcn');
			foreach ($inventory[$kind] as $row) {
				$name = $row['fqcn'];
				if (isset($seen[$name]) || !isset($original[$name]) || $row['ceiling'] > $original[$name]['ceiling'] || ($row['expires'] ?? null) !== ($original[$name]['expires'] ?? null) || ($row['steps'] ?? []) !== ($original[$name]['steps'] ?? [])) { $errors[] = 'Raised/changed ceiling or membership: ' . $name; }
				$seen[$name] = true;
				if (isset($row['expires'])) { $tickets->pending($row['expires']); }
				$ceiling = $row['ceiling'];
				foreach ($row['steps'] ?? [] as $step) {
					if (!in_array($step['ticket'], $tickets->known, true)) { $errors[] = 'Unknown size step: ' . $step['ticket']; }
					if (in_array($step['ticket'], $tickets->completed, true)) { $ceiling = min($ceiling, $step['ceiling']); }
				}
				$path = $tree->present($name);
				if (substr_count($tree->files[$path], "\n") <= 300) { $errors[] = 'Remove obsolete size exception: ' . $name; }
				$limits[$path] = $ceiling;
			}
		}
		foreach ($paths as $path => $row) {
			$tickets->pending($row['expires']);
			if (!isset(self::SOURCE_MAXIMA[$path]) || $row['ceiling'] > self::SOURCE_MAXIMA[$path] || $row['expires'] !== 'RE-12b') { $errors[] = 'Raised/changed source ceiling: ' . $path; }
			if (!(str_starts_with($path, 'src/') || $path === 'templates/admin.php') || !isset($tree->files[$path]) || $row['ceiling'] <= 300) { $errors[] = 'Invalid app source size exception: ' . $path; }
			if (isset($tree->files[$path]) && substr_count($tree->files[$path], "\n") <= 300) { $errors[] = 'Remove obsolete source ceiling: ' . $path; }
			$limits[$path] = $row['ceiling'];
		}
		foreach ($tree->files as $path => $source) {
			$lines = substr_count($source, "\n") + (str_ends_with($source, "\n") ? 0 : 1);
			if (!str_ends_with($source, "\n")) { $errors[] = 'Missing final newline: ' . $path; }
			if ($lines > ($limits[$path] ?? 300)) { $errors[] = $path . ': ' . $lines . ' > ' . ($limits[$path] ?? 300); }
		}
		return $errors;
	}
}
