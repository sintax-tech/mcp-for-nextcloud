<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Parses the multistatus answer of the embedded native DAV server, and nothing else.
 *
 * Sabre writes that answer as UTF-8 without a byte-order mark, without NUL bytes and without a document type. Any of
 * these, in any encoding, is refused before or right after parsing: a UTF-16 document with a BOM could otherwise carry
 * a DOCTYPE with internal entities past a plain-text check. No network access is allowed while parsing.
 */
final class MultistatusDocument {
	/** WebDAV namespace of the multistatus envelope, bound to the `d` prefix. */
	public const DAV = 'DAV:';
	/** Namespace of the ownCloud/Nextcloud properties, bound to the `oc` prefix. */
	public const OC = 'http://owncloud.org/ns';
	/** Byte-order marks of UTF-8, UTF-16 and UTF-32. */
	private const BOMS = ["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE", "\x00\x00\xFE\xFF"];

	/** Static parser only. */
	private function __construct() {
	}

	/**
	 * @param string $xml native multistatus answer
	 * @return DOMXPath query over the parsed answer, with the `d` and `oc` prefixes bound
	 * @throws RuntimeException when the answer is not a plain UTF-8 multistatus document
	 */
	public static function xpath(string $xml): DOMXPath {
		$refused = $xml === '' || str_contains($xml, "\0");
		foreach (self::BOMS as $bom) {
			$refused = $refused || str_starts_with($xml, $bom);
		}
		$document = new DOMDocument();
		$loaded = false;
		if (!$refused) {
			$previous = libxml_use_internal_errors(true);
			try {
				$loaded = $document->loadXML($xml, LIBXML_NONET);
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors($previous);
			}
		}
		if (!$loaded || $document->doctype !== null || $document->documentElement?->namespaceURI !== self::DAV
			|| $document->documentElement->localName !== 'multistatus') {
			throw new RuntimeException('Unexpected DAV multistatus answer');
		}
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('d', self::DAV);
		$xpath->registerNamespace('oc', self::OC);
		return $xpath;
	}
}
