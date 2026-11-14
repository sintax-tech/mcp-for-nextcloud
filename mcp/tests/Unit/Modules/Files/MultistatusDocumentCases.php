<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

/** Answers every DAV multistatus parser of Files must refuse exactly like a plain ASCII DOCTYPE. */
final class MultistatusDocumentCases {
	/** A multistatus with an internal entity used inside it. */
	private const ENTITY = '<!DOCTYPE d:multistatus [<!ENTITY x "expanded">]><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
		. '<d:response><d:propstat><d:prop><oc:id>5</oc:id><oc:message>&x;</oc:message></d:prop>'
		. '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

	/** Static cases only. */
	private function __construct() {
	}

	/** @return array<string,string> refused answers by name */
	public static function refused(): array {
		$utf16 = static fn (string $xml, string $encoding, string $bom): string => $bom . mb_convert_encoding($xml, $encoding, 'UTF-8');
		$plain = '<d:multistatus xmlns:d="DAV:"/>';
		return [
			'ascii doctype with entity' => '<?xml version="1.0"?>' . self::ENTITY,
			'utf-16le bom doctype with entity' => $utf16('<?xml version="1.0" encoding="UTF-16"?>' . self::ENTITY, 'UTF-16LE', "\xFF\xFE"),
			'utf-16be bom doctype with entity' => $utf16('<?xml version="1.0" encoding="UTF-16"?>' . self::ENTITY, 'UTF-16BE', "\xFE\xFF"),
			'utf-16le bom without doctype' => $utf16('<?xml version="1.0" encoding="UTF-16"?>' . $plain, 'UTF-16LE', "\xFF\xFE"),
			'utf-8 bom' => "\xEF\xBB\xBF<?xml version=\"1.0\"?>" . $plain,
			'nul byte' => "<?xml version=\"1.0\"?><d:multistatus xmlns:d=\"DAV:\">\0</d:multistatus>",
		];
	}
}
