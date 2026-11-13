<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

use OCA\Mcp\Platform\Meetings\LocalInstanceLink;

/** Deck card ids and file references copied out of text the readers already returned. Nothing is fetched. */
final class ReferenceTexts {
	/** Parsing only. */
	private function __construct() {
	}

	/**
	 * @param string $text a link or a description
	 * @param LocalInstanceLink $local which absolute hosts belong to this instance
	 * @return list<int> card ids named by a Deck card URL of this instance, once each
	 */
	public static function deckCardIds(string $text, LocalInstanceLink $local): array {
		$ids = [];
		foreach (self::absoluteUrls($text) as $url) {
			self::addCard($url, $local, $ids);
		}
		if (preg_match_all('~(?:^|[\s"\'])(/apps/deck/(?:#/)?board/\d+/card/(\d+))~', $text, $paths, PREG_SET_ORDER) !== false) {
			foreach ($paths as $match) {
				self::addCard($match[1], $local, $ids);
			}
		}
		return $ids;
	}

	/**
	 * @param string $text a link, a description or a location
	 * @param LocalInstanceLink $local which absolute hosts belong to this instance
	 * @return list<string> file ids and file links of this instance, not Deck card URLs
	 */
	public static function fileReferences(string $text, LocalInstanceLink $local): array {
		$found = [];
		foreach (self::absoluteUrls($text) as $url) {
			self::keep($url, $found, $local);
		}
		if (preg_match_all('#(?:^|[\s"\'])(/(?:f/\d+|apps/files/[^\s<>"\']+|remote\.php/(?:dav/files|webdav)[^\s<>"\']*))#', $text, $paths) !== false) {
			foreach ($paths[1] as $path) {
				self::keep($path, $found, $local);
			}
		}
		return $found;
	}

	/**
	 * Whole absolute URLs, including a scheme-relative form that starts a token.
	 *
	 * A relative path is not taken from inside one of these. The caller decides by the host.
	 *
	 * @param string $text a link or a description
	 * @return list<string> each absolute URL once, in order
	 */
	private static function absoluteUrls(string $text): array {
		$found = [];
		if (preg_match_all('#[a-z][a-z0-9+.-]*://[^\s<>"\']+#i', $text, $urls) !== false) {
			foreach ($urls[0] as $url) {
				$found[] = $url;
			}
		}
		if (preg_match_all('#(?:^|[\s"\'])(//[^\s<>"\']+)#', $text, $relative) !== false) {
			foreach ($relative[1] as $url) {
				if (!in_array($url, $found, true)) {
					$found[] = $url;
				}
			}
		}
		return $found;
	}

	/**
	 * @param string $url one absolute URL or one relative Deck path
	 * @param LocalInstanceLink $local which absolute hosts belong to this instance
	 * @param list<int> $ids card ids kept so far
	 */
	private static function addCard(string $url, LocalInstanceLink $local, array &$ids): void {
		if (preg_match('~/apps/deck/(?:#/)?board/\d+/card/(\d+)~', $url, $match) !== 1) {
			return;
		}
		$value = (int)$match[1];
		if ($value > 0 && $local->accepts($url) && !in_array($value, $ids, true)) {
			$ids[] = $value;
		}
	}

	/**
	 * @param string $value one candidate
	 * @param LocalInstanceLink $local which absolute hosts belong to this instance
	 * @return bool whether it is a Deck card URL of this instance
	 */
	public static function isDeckCard(string $value, LocalInstanceLink $local): bool {
		return $local->accepts($value) && preg_match('~/apps/deck/(?:#/)?board/\d+/card/\d+~', $value) === 1;
	}

	/**
	 * @param string $value one candidate
	 * @param LocalInstanceLink $local which absolute hosts belong to this instance
	 * @return bool whether the file port should resolve it
	 */
	public static function isFile(string $value, LocalInstanceLink $local): bool {
		if (!$local->accepts($value) || self::isDeckCard($value, $local)) {
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
	 * @param LocalInstanceLink $local which absolute hosts belong to this instance
	 */
	private static function keep(string $raw, array &$found, LocalInstanceLink $local): void {
		$value = rtrim(trim($raw), ".,);]>'\" ");
		if ($value !== '' && self::isFile($value, $local) && !in_array($value, $found, true)) {
			$found[] = $value;
		}
	}
}
