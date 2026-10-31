<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** T17: every shipped group-editor source string has matching pt_BR/es JSON and browser translations. */
final class AdminGroupAssetsTest extends TestCase {
	/** Token scanning covers literal method references even when invoked indirectly, plus unknown dynamic calls. */
	private static function countingReferences(string $source): array {
		$tokens = array_values(array_filter(token_get_all($source), static fn ($token): bool => !is_array($token)
			|| !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$references = [];
		foreach ($tokens as $i => $token) {
			if (!is_array($token)) continue;
			if (in_array($token[0], [T_STRING, T_CONSTANT_ENCAPSED_STRING], true)) {
				$value = $token[0] === T_STRING ? $token[1] : stripcslashes(substr($token[1], 1, -1));
				$previous = $tokens[$i - 1] ?? null;
				if (is_array($previous) && $previous[0] === T_FUNCTION) continue;
				if (in_array($value, ['beginManyGrants', 'inherited'], true)) $references[] = $value;
			}
			if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
				$next = $tokens[$i + 1] ?? null;
				if (is_array($next) && $next[0] === T_VARIABLE && ($tokens[$i + 2] ?? null) === '(') {
					$references[] = 'dynamic method';
				} elseif ($next === '{') {
					// Reject dynamically computed callable names; dynamic property access remains allowed.
					$end = $i + 2;
					$depth = 1;
					for (; $end < count($tokens) && $depth > 0; ++$end) {
						if ($tokens[$end] === '{') ++$depth;
						if ($tokens[$end] === '}') --$depth;
					}
					if (($tokens[$end] ?? null) === '(') $references[] = 'dynamic method';
				}
			}
		}
		return array_values(array_unique($references));
	}

	public function testCountingBoundaryDetectsIndirectAndDynamicCalls(): void {
		foreach (['beginManyGrants', 'inherited'] as $method) {
			foreach ([
				'$obj->' . $method . '();',
				'[$obj, "' . $method . '"]();',
				'call_user_func([$obj, "' . $method . '"]);',
				'$m = "' . $method . '"; $obj->$m();',
				'$obj->{"' . $method . '"}();',
			] as $source) {
				$this->assertContains($method, self::countingReferences('<?php ' . $source));
			}
		}
		$this->assertContains('dynamic method', self::countingReferences('<?php $obj->$m();'));
		$this->assertContains('dynamic method', self::countingReferences('<?php $obj->{$m}();'));
		$this->assertSame([], self::countingReferences('<?php function inherited() {} // beginManyGrants'));
	}

	public function testGrantOnlyMethodsAreReferencedOnlyByTheAdminCountingReadModel(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		$callers = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->getExtension() !== 'php') continue;
			foreach (self::countingReferences(file_get_contents($file->getPathname())) as $method) {
				$callers[$method][] = substr($file->getPathname(), strlen($lib) + 1);
			}
		}
		ksort($callers);
		foreach ($callers as &$files) {
			sort($files);
		}
		unset($files);
		// The two admin read models: the group statistics and the preview of a reset. Both count for an administrator and
		// never decide access (the preview class repeats the notice).
		$this->assertSame(['beginManyGrants' => ['Service/GroupDirectory.php', 'Service/OverridePreview.php'],
			'inherited' => ['Service/GroupDirectory.php', 'Service/OverridePreview.php']],
			$callers, 'Grant-only references and dynamic methods must never escape the admin read models');
		foreach (['PolicyResolver.php', 'PolicyOperationContext.php', '../OverridePreview.php'] as $file) {
			$this->assertStringContainsString('admin counting only, not an access decision',
				file_get_contents($lib . '/Service/Policy/' . $file));
		}
	}

	public function testGroupStringsAndPlaceholdersAreCompleteInBothFormats(): void {
		$app = dirname(__DIR__, 3);
		$source = file_get_contents($app . '/js/admin-groups.js');
		preg_match_all("/t\\('mcp', '([^']+)'/", $source, $script);
		$template = file_get_contents($app . '/templates/admin.php');
		$start = strpos($template, 'id="mcp-group-editor"');
		$block = substr($template, $start, strpos($template, 'id="mcp-block-matrix"') - $start);
		preg_match_all("/\\\$l->t\\('([^']+)'/", $block, $markup);
		$strings = array_unique([...$script[1], ...$markup[1],
			'Unknown', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.',
			'Granted by groups: {groups}']);
		foreach (['pt_BR', 'es'] as $lang) {
			$translations = json_decode(file_get_contents("$app/l10n/$lang.json"), true, flags: JSON_THROW_ON_ERROR)['translations'];
			$browser = file_get_contents("$app/l10n/$lang.js");
			foreach ($strings as $text) {
				$this->assertArrayHasKey($text, $translations, "$lang: $text");
				preg_match_all('/\{\w+\}/', $text, $expected);
				preg_match_all('/\{\w+\}/', $translations[$text], $actual);
				$this->assertEqualsCanonicalizing($expected[0], $actual[0], "$lang: $text placeholders");
				$this->assertStringContainsString(json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
					. ' : ' . json_encode($translations[$text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $browser);
			}
		}
	}
}
