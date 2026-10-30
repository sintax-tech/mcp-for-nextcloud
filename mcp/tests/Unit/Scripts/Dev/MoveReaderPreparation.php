<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

/** Models explicit pre-move test preparation in fixtures only; never edits production tests on disk. */
final class MoveReaderPreparation {
	/**
	 * Replaces inline sorted path expectations with explicitly sorted expected variables.
	 *
	 * @param string $code test source
	 * @param string $path original test path, for explicit root preparation
	 * @return string prepared source
	 */
	public static function code(string $code, string $path): string {
		$depth = substr_count($path, '/');
		$code = preg_replace_callback('/__DIR__\s*\.\s*(\'[^\']*\')/', static function (array $m) use ($depth): string {
			$value = \OCA\Mcp\Scripts\Dev\PhpStringLiteral::value($m[1]);
			$prefix = '/' . str_repeat('../', $depth);
			return str_starts_with($value, $prefix . 'lib/') ? "dirname(__DIR__, $depth) . '/" . substr($value, strlen($prefix)) . "'" : $m[0];
		}, $code);
		if (!str_contains($code, 'sort(')) {
			return $code;
		}
		$tokens = [];
		$offset = 0;
		foreach (token_get_all($code) as $t) {
			$text = is_array($t) ? $t[1] : $t;
			if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				$tokens[] = [$text, $offset];
			}
			$offset += strlen($text);
		}
		$edits = [];
		foreach ($tokens as $i => [$text, $start]) {
			if ($text !== 'assertSame' || ($tokens[$i + 2][0] ?? '') !== '[') {
				continue;
			}
			$depth = 1;
			for ($j = $i + 3; isset($tokens[$j]); $j++) {
				$depth += $tokens[$j][0] === '[' ? 1 : ($tokens[$j][0] === ']' ? -1 : 0);
				if ($depth === 0) {
					break;
				}
			}
			$begin = $tokens[$i + 2][1];
			$end = $tokens[$j][1] + 1;
			$array = substr($code, $begin, $end - $begin);
			if (!str_contains($array, '.php')) {
				continue;
			}
			$call = $tokens[$i - 2][1];
			$prefix = '$expectedMovePaths = ' . $array . ";\n"
				. 'foreach ($expectedMovePaths as &$paths) { if (is_array($paths)) { sort($paths); } } unset($paths);' . "\n"
				. 'if (array_is_list($expectedMovePaths)) { sort($expectedMovePaths); } else { ksort($expectedMovePaths); }' . "\n";
			$edits[] = [$call, $end - $call, $prefix . substr($code, $call, $begin - $call) . '$expectedMovePaths'];
		}
		foreach (array_reverse($edits) as [$start, $length, $replacement]) {
			$code = substr_replace($code, $replacement, $start, $length);
		}
		// The counting test's composed basename loop needs three complete paths before a move can be proved.
		$name = implode('', ['Override', 'Preview']) . '.php';
		$pattern = '~foreach \(\[\'PolicyResolver.php\', \'PolicyOperationContext.php\', \'../' . preg_quote($name, '~') . '\'\] as \$file\) \{.*?\n        \}~s';
		$replacement = '';
		foreach (['Service/Policy/PolicyResolver.php', 'Service/Policy/PolicyOperationContext.php', 'Service/' . $name] as $file) {
			$replacement .= "\$this->assertStringContainsString('admin counting only, not an access decision', file_get_contents(\$lib . '/$file'));\n";
		}
		return preg_replace_callback($pattern, static fn (): string => $replacement, $code);
	}
}
