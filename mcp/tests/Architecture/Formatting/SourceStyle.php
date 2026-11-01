<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Formatting;

/** Token-aware app style checks: content in strings, heredocs and HTML is not indentation. */
final class SourceStyle {
	/** @param string $path App-relative path. @param string $source Source bytes. @return array<string,int> Issue fingerprints and occurrence counts. */
	public static function issues(string $path, string $source): array {
		$issues = [];
		if (!str_ends_with($source, "\n")) { $issues['final-newline'] = 1; }
		foreach (['SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>', 'SPDX-License-Identifier: AGPL-3.0-or-later'] as $header) {
			if (!str_contains(substr($source, 0, 500), $header)) { $issues['header:' . $header] = 1; }
		}
		$php = str_ends_with($path, '.php');
		$tokens = token_get_all($php ? $source : '<?php ' . $source);
		if (!$php) { array_shift($tokens); }
		$significant = array_values(array_filter($tokens, static fn ($t) => !is_array($t) || !in_array($t[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$prefix = implode('', array_map(static fn ($t) => is_array($t) ? $t[1] : $t, array_slice($significant, 0, 7)));
		if ($php && !str_starts_with($prefix, 'declare(strict_types=1);')) { $issues['strict_types'] = 1; }
		$offset = 0;
		$heredoc = false;
		$badLines = [];
		foreach ($tokens as $token) {
			$id = is_array($token) ? $token[0] : null;
			$text = is_array($token) ? $token[1] : $token;
			if ($id === T_START_HEREDOC) { $heredoc = true; }
			$start = strrpos(substr($source, 0, $offset), "\n");
			$start = $start === false ? 0 : $start + 1;
			$indent = substr($source, $start, $offset - $start);
			if (!$heredoc && !in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE], true) && preg_match('/^[\t ]* /D', $indent) && trim($indent) === '') {
				$end = strpos($source, "\n", $offset);
				$badLines[$start] = 'tabs:' . hash('sha256', trim(substr($source, $start, ($end === false ? strlen($source) : $end) - $start)));
			}
			if ($id === T_END_HEREDOC) { $heredoc = false; }
			$offset += strlen($text);
		}
		foreach ($badLines as $key) { $issues[$key] = ($issues[$key] ?? 0) + 1; }
		ksort($issues);
		return $issues;
	}

	/** @param array<string,string> $files Live sources. @param array<string,array<string,mixed>> $debts Exact path/fingerprint debts. @param \OCA\Mcp\Tests\Architecture\Inventory\TicketState $tickets Completion state. @return list<string> New violations or unused debt. */
	public static function problems(array $files, array $debts, \OCA\Mcp\Tests\Architecture\Inventory\TicketState $tickets): array {
		$errors = [];
		foreach ($debts as $path => $debt) {
			$tickets->pending($debt['expires']);
			if (!isset($files[$path])) { $errors[] = 'Unused style debt: ' . $path; }
		}
		foreach ($files as $path => $source) {
			$actual = self::issues($path, $source);
			$allowed = $debts[$path]['issues'] ?? [];
			foreach ($actual as $key => $count) {
				if ($count > ($allowed[$key] ?? 0)) { $errors[] = $path . ':' . $key; }
			}
			foreach ($allowed as $key => $count) { if (($actual[$key] ?? 0) < $count) { $errors[] = 'Remove obsolete style debt: ' . $path . ':' . $key; } }
		}
		return $errors;
	}
}
