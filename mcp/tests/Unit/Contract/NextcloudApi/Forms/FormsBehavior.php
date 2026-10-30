<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

/** Reads executable-token method bodies from pinned source fixtures, never executing native source in unit contracts. */
final class FormsBehavior {
	/** Extracts one method's executable tokens without depending on whitespace/comments.
	 * @param string $source Official source or a deliberately mutated fixture.
	 * @param string $method Native method name.
	 * @return string Normalized token body.
	 */
	public static function body(string $source, string $method): string {
		$tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn ($token): bool => !$token->isIgnorable()));
		foreach ($tokens as $i => $token) {
			if ($token->id !== T_FUNCTION || ($tokens[$i + 1]->text ?? '') !== $method) { continue; }
			while (($tokens[$i]->text ?? '') !== '{') { $i++; }
			$depth = 1; $body = '';
			while (isset($tokens[++$i])) {
				$text = $tokens[$i]->text;
				if ($text === '{') { $depth++; }
				if ($text === '}') { $depth--; if ($depth === 0) { break; } }
				$body .= $text;
			}
			return $body;
		}
		throw new \RuntimeException('Native method missing: ' . $method);
	}

	/** Checks the precise null/actor branch mechanism, including the divergent sentinel share lookup afterward.
	 * @param string $source Official FormsService source or a mutation.
	 * @return bool Whether the supported mechanism still exists in this source.
	 */
	public static function identity(string $source): bool {
		$body = self::body($source, 'getPermissions');
		$null = strpos($body, 'if(!$this->currentUser){return[];}');
		$owner = strpos($body, 'if($this->currentUser->getUID()===$form->getOwnerId()){returnConstants::PERMISSION_ALL;}');
		$shares = strpos($body, '$this->getSharesWithUser($form->getId(),$this->currentUser->getUID())');
		return $null !== false && $owner !== false && $shares !== false && $null < $owner && $owner < $shares;
	}
}
