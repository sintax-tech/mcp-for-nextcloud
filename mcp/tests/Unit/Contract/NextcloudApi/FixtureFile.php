<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

/**
 * Writes one fixture of tests/fixtures/nextcloud-api in the layout generate.php uses: the comment header, the source
 * pins, then one class per line as a short-array PHP literal. Used by generate-mail.php, which adds the surfaces of
 * the Mail app to a fixture without regenerating the rest.
 */
final class FixtureFile {
	/**
	 * Writes the fixture.
	 *
	 * @param string $path destination file
	 * @param string $header comment lines that precede `return [`, each ending in a newline
	 * @param array{sources:array<string, mixed>, recipientSources:array<string, mixed>, mailSources?:array<string, mixed>, classes:array<string, array<string, mixed>|null>} $fixture the fixture
	 * @return void
	 */
	public static function write(string $path, string $header, array $fixture): void {
		$lines = [];
		foreach ($fixture['classes'] as $class => $surface) {
			$lines[] = '        ' . var_export($class, true) . ' => ' . self::literal($surface) . ',';
		}
		$pins = "    'sources' => " . self::literal($fixture['sources']) . ",\n"
			. "    'recipientSources' => " . self::literal($fixture['recipientSources']) . ",\n";
		if (isset($fixture['mailSources'])) {
			$pins .= "    'mailSources' => " . self::literal($fixture['mailSources']) . ",\n";
		}
		file_put_contents($path, $header . "return [\n" . $pins . "    'classes' => [\n" . implode("\n", $lines) . "\n    ],\n];\n");
	}

	/**
	 * A value as a one-line PHP literal: short arrays, lists without their keys.
	 *
	 * @param mixed $value scalar or nested array
	 * @return string the reproducible literal
	 */
	public static function literal(mixed $value): string {
		if (!is_array($value)) {
			return var_export($value, true);
		}
		$items = [];
		foreach ($value as $key => $item) {
			$items[] = (array_is_list($value) ? '' : var_export($key, true) . ' => ') . self::literal($item);
		}
		return '[' . implode(', ', $items) . ']';
	}
}
