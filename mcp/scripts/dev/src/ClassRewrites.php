<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Applies the class renames of a move to the text of a non-PHP test file (a golden that lists DI keys, an effect
 * contract that records the class of a refusal), so such a file may follow the move and nothing else.
 *
 * Only whole names change: the exact old name, written plainly (`Acme\A\Probe`) or JSON-escaped (`Acme\\A\\Probe`),
 * optionally after a leading separator, never preceded by a name character and never followed by one or by a deeper
 * namespace segment. Any other edit of the file still makes it differ from the rewritten text.
 */
final class ClassRewrites {
	private function __construct() {
	}

	/**
	 * Rewrites every whole old class name of `$renames` in `$text`.
	 *
	 * @param string $text non-PHP text
	 * @param array<string,string> $renames old class => new class
	 * @return string the text with the renamed classes
	 */
	public static function text(string $text, array $renames): string {
		uksort($renames, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
		foreach ($renames as $old => $new) {
			foreach (['\\', '\\\\'] as $separator) {
				$name = str_replace('\\', $separator, $old);
				$pattern = '~(?<![\w\\\\])(' . preg_quote($separator, '~') . ')?' . preg_quote($name, '~') . '(?!\w)(?!' . preg_quote($separator, '~') . '\w)~';
				$replacement = str_replace('\\', $separator, $new);
				$text = (string)preg_replace_callback($pattern, static fn (array $m): string => ($m[1] ?? '') . $replacement, $text);
			}
		}
		return $text;
	}
}
