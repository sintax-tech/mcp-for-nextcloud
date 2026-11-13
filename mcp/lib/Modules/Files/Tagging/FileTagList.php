<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tagging;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * The tags of one file as the native relation PROPFIND reports them, without the MCP exclusion tags.
 *
 * The native collection already returned only the tags the actor may see (canUserSeeTag in
 * SystemTagsObjectMappingCollection::getChildren); this keeps that list and its native flags as they are. The first
 * response is the relation collection itself, which has no tag ID and is skipped. A tag the administrator selected
 * to hide files from MCP is dropped without a trace: its ID, name and count never reach the output.
 */
final class FileTagList {
	/** WebDAV namespace of the multistatus envelope. */
	private const DAV = 'DAV:';
	/** Namespace of the tag properties (SystemTagPlugin::*_PROPERTYNAME). */
	private const OC = 'http://owncloud.org/ns';

	/** Static projection only. */
	private function __construct() {
	}

	/**
	 * @param string $multistatus native PROPFIND answer for systemtags-relations/files/<id>, Depth 1
	 * @param list<string> $hiddenTagIds exclusion tag IDs configured for MCP
	 * @return list<array{id:string,name:string,user_visible:bool,user_assignable:bool,can_assign:bool}> visible tags, in native order
	 * @throws RuntimeException when the answer is not the expected multistatus document
	 */
	public static function parse(string $multistatus, array $hiddenTagIds): array {
		$xpath = self::document($multistatus);
		$tags = [];
		foreach ($xpath->query('/d:multistatus/d:response') ?: [] as $response) {
			$props = self::okProps($xpath, $response);
			$id = $props === null ? '' : self::text($xpath, $props, 'oc:id');
			if ($id === '') {
				continue;
			}
			if (!ctype_digit($id)) {
				throw new RuntimeException('Unexpected tag ID in the DAV answer');
			}
			if (in_array($id, $hiddenTagIds, true)) {
				continue;
			}
			$tags[] = [
				'id' => $id,
				'name' => self::text($xpath, $props, 'oc:display-name'),
				'user_visible' => self::text($xpath, $props, 'oc:user-visible') === 'true',
				'user_assignable' => self::text($xpath, $props, 'oc:user-assignable') === 'true',
				'can_assign' => self::text($xpath, $props, 'oc:can-assign') === 'true',
			];
		}
		return $tags;
	}

	/**
	 * @param string $multistatus native answer
	 * @return DOMXPath query over the parsed answer, with the d and oc prefixes bound
	 * @throws RuntimeException when the answer is not a multistatus document
	 */
	private static function document(string $multistatus): DOMXPath {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			$loaded = $multistatus !== '' && $document->loadXML($multistatus, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!$loaded || $document->doctype !== null || $document->documentElement?->namespaceURI !== self::DAV
			|| $document->documentElement->localName !== 'multistatus') {
			throw new RuntimeException('Unexpected DAV tag answer');
		}
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('d', self::DAV);
		$xpath->registerNamespace('oc', self::OC);
		return $xpath;
	}

	/**
	 * @param DOMXPath $xpath query over the answer
	 * @param \DOMNode $response one d:response
	 * @return DOMElement|null the d:prop of its 200 propstat, null when the response has none
	 */
	private static function okProps(DOMXPath $xpath, \DOMNode $response): ?DOMElement {
		foreach ($xpath->query('d:propstat', $response) ?: [] as $propstat) {
			if (preg_match('#^HTTP/\d\.\d 200 #', trim(self::text($xpath, $propstat, 'd:status'))) === 1) {
				$prop = $xpath->query('d:prop', $propstat)?->item(0);
				return $prop instanceof DOMElement ? $prop : null;
			}
		}
		return null;
	}

	/**
	 * @param DOMXPath $xpath query over the answer
	 * @param \DOMNode $context element to read below
	 * @param string $child prefixed child element name
	 * @return string its text, '' when absent
	 */
	private static function text(DOMXPath $xpath, \DOMNode $context, string $child): string {
		return (string)$xpath->query($child, $context)?->item(0)?->textContent;
	}
}
