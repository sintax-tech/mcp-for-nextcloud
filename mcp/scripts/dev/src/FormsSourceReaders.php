<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/**
 * Explicit root preparation for the three historical Forms source readers, approved for RE-18 after its move.
 * This is a finite exception, not a general proof of paths containing parent segments. Both versions read a mapped
 * file under the same app root; every other token, reader, prefix and unpaired destination stays strict.
 */
final class FormsSourceReaders {
	/** Exact readers and the sole historical source each one reads. */
	private const READERS = [
		'tests/Unit/Contract/NextcloudApi/Forms/CreateNativeArgumentsContractTest.php' => 'lib/Tools/Forms/Creation/FormsCreateNativeFactory.php',
		'tests/Unit/Contract/NextcloudApi/Forms/FormsCreationContractTest.php' => 'lib/Tools/Forms/Creation/FormsCreateNativeFactory.php',
		'tests/Unit/Contract/NextcloudApi/Forms/FormsEditingContractTest.php' => 'lib/Tools/Forms/Editing/EditNativeFactory.php',
	];

	/** Prevents construction of the finite reader preparation. */
	private function __construct() {
	}

	/**
	 * Prepares only a literal concatenated immediately to __DIR__, with exactly five parent segments. Replacing a
	 * comment or quoted example cannot affect the proof: edits use PHP token offsets, never a source-text regex.
	 *
	 * @param string $code reader source bytes
	 * @param string|null $path app-relative reader identity
	 * @param array<string,string> $pairs actual paired files, or destination identities on the after side
	 * @return string source with the approved roots expressed as dirname(__DIR__, 5)
	 */
	public static function prepare(string $code, ?string $path, array $pairs): string {
		$old = self::READERS[$path ?? ''] ?? null;
		if ($old === null) { return $code; }
		$new = str_replace('lib/Tools/Forms/Creation/', 'lib/Modules/Forms/Integration/', $old);
		$new = str_replace('lib/Tools/Forms/Editing/', 'lib/Modules/Forms/Integration/', $new);
		$allowed = [];
		if (($pairs[$old] ?? null) === $new) { $allowed[] = $old; }
		if (($pairs[$old] ?? null) === $new || ($pairs[$new] ?? null) === $new) { $allowed[] = $new; }
		$tokens = []; $offset = 0;
		foreach (token_get_all($code) as $token) {
			$text = is_array($token) ? $token[1] : $token;
			if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				$tokens[] = [is_array($token) ? $token[0] : null, $text, $offset];
			}
			$offset += strlen($text);
		}
		$edits = [];
		foreach ($tokens as $i => [$id, $text, $start]) {
			if ($id !== T_DIR || ($tokens[$i + 1][1] ?? '') !== '.' || ($tokens[$i + 2][0] ?? null) !== T_CONSTANT_ENCAPSED_STRING) { continue; }
			$literal = $tokens[$i + 2];
			$value = PhpStringLiteral::value($literal[1]);
			foreach ($allowed as $source) {
				if ($value === '/../../../../../' . $source) {
					$edits[] = [$start, $literal[2] + strlen($literal[1]) - $start, "dirname(__DIR__, 5) . '/" . $source . "'"];
				}
			}
		}
		foreach (array_reverse($edits) as [$start, $length, $replacement]) { $code = substr_replace($code, $replacement, $start, $length); }
		return $code;
	}
}
