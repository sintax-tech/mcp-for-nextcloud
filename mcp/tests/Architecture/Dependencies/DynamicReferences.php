<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Dependencies;

use RuntimeException;

/** Finite manual resolution of the one legacy dynamic native mapper site, never a prefix exception. */
final class DynamicReferences {
	/** @param string $path Physical source. @param string $source Source bytes. @param array<string,mixed> $edge Prefix diagnostic. @return list<string> Exact classes. */
	public static function resolve(string $path, string $source, array $edge): array {
		if (!in_array($path, ['lib/Tools/Forms/Results/SubmissionReads.php', 'lib/Modules/Forms/Integration/SubmissionReads.php'], true) || $edge['target'] !== 'OCA\\Forms\\Db\\') { throw new RuntimeException('Unreviewed dynamic class prefix: ' . $path . ':' . $edge['line']); }
		$tokens = token_get_all($source);
		$code = implode('', array_map(static fn ($t) => is_array($t) ? (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, $tokens));
		$site = <<<'PHP'
return$this->container->get('OCA\\Forms\\Db\\'.$name);
PHP;
		if (substr_count($code, $site) !== 1) { throw new RuntimeException('Dynamic mapper expression changed: ' . $path); }
		preg_match_all('/\$this->mapper\(([^)]*)\)/', $source, $calls);
		$expected = ["'SubmissionMapper'", "'SubmissionMapper'", "'QuestionMapper'", "'OptionMapper'", "'AnswerMapper'"];
		if ($calls[1] !== $expected) { throw new RuntimeException('Dynamic mapper caller set changed: ' . $path); }
		return array_map(static fn ($name) => 'OCA\\Forms\\Db\\' . trim($name, "'"), array_values(array_unique($expected)));
	}
}
