<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Inventory;

/** The existing RE-12 asset assertions as a shared checker, including missing required templates. */
final class AssetRules {
	/** @param list<string> $scripts Recursive paths in js/. @return list<string> Unexpected/missing bundle errors. */
	public static function scripts(array $scripts): array {
		sort($scripts);
		return $scripts === ['js/mcp-admin.js', 'js/mcp-personal.js'] ? [] : ['Expected exactly the two generated bundles'];
	}
	/** @param array<string,string> $templates Basename to source. @return list<string> Missing/duplicate/wrong script registrations. */
	public static function templates(array $templates): array {
		$required = ['admin.php' => 'mcp-admin', 'personal.php' => 'mcp-personal'];
		$errors = [];
		foreach ($required as $name => $bundle) { if (!isset($templates[$name])) { $errors[] = 'Missing required template: ' . $name; } }
		foreach ($templates as $name => $source) {
			preg_match_all("/Util::addScript\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", $source, $matches);
			if ($matches[1] !== (isset($required[$name]) ? [$required[$name]] : [])) { $errors[] = 'Wrong script registration: ' . $name; }
		}
		return $errors;
	}
}
