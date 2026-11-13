<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

/** Deck card ids and file references copied out of text the readers already returned. Nothing is fetched. */
final class ReferenceTexts {
	/** Parsing only. */
	private function __construct() {
	}

	/**
	 * @param string $text a link or a description
	 * @return list<int> card ids named by a Deck card URL, once each
	 */
	public static function deckCardIds(string $text): array {
		if (preg_match_all('~/apps/deck/(?:#/)?board/\d+/card/(\d+)~', $text, $matches) === false) {
			return [];
		}
		$ids = [];
		foreach ($matches[1] as $id) {
			$value = (int)$id;
			if ($value > 0 && !in_array($value, $ids, true)) {
				$ids[] = $value;
			}
		}
		return $ids;
	}

	/**
	 * @param string $text a link, a description or a location
	 * @return list<string> file ids and file links, not Deck card URLs
	 */
	public static function fileReferences(string $text): array {
		$found = [];
		if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $urls) !== false) {
			foreach ($urls[0] as $url) {
				self::keep($url, $found);
			}
		}
		if (preg_match_all('#(?<![A-Za-z0-9])(/(?:f/\d+|apps/files/[^\s<>"\']+|remote\.php/(?:dav/files|webdav)[^\s<>"\']*))#', $text, $paths) !== false) {
			foreach ($paths[1] as $path) {
				self::keep($path, $found);
			}
		}
		return $found;
	}

	/**
	 * @param string $value one candidate
	 * @return bool whether it is a Deck card URL
	 */
	public static function isDeckCard(string $value): bool {
		return preg_match('~/apps/deck/(?:#/)?board/\d+/card/\d+~', $value) === 1;
	}

	/**
	 * @param string $value one candidate
	 * @return bool whether the file port should resolve it
	 */
	public static function isFile(string $value): bool {
		if (self::isDeckCard($value)) {
			return false;
		}
		if (preg_match('#/(?:f|apps/files/files)/\d+#', $value) === 1) {
			return true;
		}
		if (str_contains($value, '/apps/files/') && (str_contains($value, 'fileid=') || preg_match('#/apps/files/files/\d+#', $value) === 1)) {
			return true;
		}
		if (str_contains($value, '/remote.php/dav/files/') || str_contains($value, '/remote.php/webdav')) {
			return true;
		}
		return !str_contains($value, '://') && str_starts_with($value, '/') && !str_starts_with($value, '/apps/') && !str_starts_with($value, '/remote.php');
	}

	/**
	 * @param string $raw one match
	 * @param list<string> $found references kept so far
	 */
	private static function keep(string $raw, array &$found): void {
		$value = rtrim(trim($raw), ".,);]>'\" ");
		if ($value !== '' && self::isFile($value) && !in_array($value, $found, true)) {
			$found[] = $value;
		}
	}
}
