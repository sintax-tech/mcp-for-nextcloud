<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

/** Validates and encodes link cells exactly as the native Tables parser preserves them. */
final class LinkCellValue {
	/** Pattern the native parser reads before JSON. */
	private const LINK_TITLE_PATTERN = '/(.*) \((http.*)\)/';

	/** Prevents construction of this static value boundary. */
	private function __construct() {
	}

	/**
	 * Whether a link value is a JSON object with exactly title, value and providerId "url", an http(s) url and within the
	 * column's limits: the one form of link the parser and the cell mapper keep as it is.
	 *
	 * @param array<string, mixed> $column the column
	 * @param string $json the link as stored
	 * @return bool true when it is such a link
	 */
	public static function isCanonicalLink(array $column, string $json): bool {
		$data = json_decode($json, true);
		$keys = is_array($data) ? array_keys($data) : [];
		sort($keys);
		if (!is_array($data) || array_is_list($data) || $keys !== ['providerId', 'title', 'value']
			|| !is_string($data['title']) || !is_string($data['value']) || $data['providerId'] !== 'url') {
			return false;
		}
		return self::isWebUrl($data['value']) && self::allowsUrlProvider($column) && TextCellValue::fitsText($column, $json)
			&& preg_match(self::LINK_TITLE_PATTERN, $json) !== 1;
	}

	/**
	 * A link {url, title?} turned into the JSON the parser keeps as it is.
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the value
	 * @return string|null the JSON, null to empty the cell
	 * @throws RowWriteRefusal for anything else
	 */
	public static function link(array $column, mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['url', 'title']) !== [] || !is_string($value['url'] ?? null)
			|| (isset($value['title']) && !is_string($value['title']))) {
			throw RowWriteRefusal::value();
		}
		$title = isset($value['title']) && $value['title'] !== '' ? $value['title'] : $value['url'];
		$json = json_encode(['title' => $title, 'value' => $value['url'], 'providerId' => 'url'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false || !self::isCanonicalLink($column, $json)) {
			throw RowWriteRefusal::value();
		}
		return $json;
	}

	/**
	 * Whether a url is an http or https address.
	 *
	 * @param string $url the url
	 * @return bool true for such an address
	 */
	private static function isWebUrl(string $url): bool {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false
			&& (string)parse_url($url, PHP_URL_HOST) !== '';
	}

	/**
	 * Whether the link column allows the provider "url".
	 *
	 * @param array<string, mixed> $column the column
	 * @return bool true when it does
	 */
	private static function allowsUrlProvider(array $column): bool {
		return in_array('url', array_map('trim', explode(',', (string)($column['textAllowedPattern'] ?? ''))), true);
	}
}
