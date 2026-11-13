<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Turns the multistatus document of the native comments REPORT into plain comment rows.
 *
 * The document carries, per comment, exactly the properties of `CommentNode::getPropertyNames()`
 * (apps/dav/lib/Comments/CommentNode.php:64-87 of v34.0.4, 65-88 of v35.0.1), only the ones answered with 200
 * (CommentsPlugin::onReport, CommentsPlugin.php:167-176 / 168-177). Each row keeps what a reader needs: the id, the
 * author as the native type, id and display name, the creation time, the text exactly as stored (mentions stay
 * `@id` text), the comment it answers, the verb, the unread flag computed from the reader's own marker, and the
 * mentions with the display names the DAV app already resolved. Nothing else is looked up.
 */
final class CommentFeed {
	/** Namespace of the comment properties. */
	private const OC = 'http://owncloud.org/ns';
	/** Namespace of the multistatus envelope. */
	private const DAV = 'DAV:';

	/**
	 * @param string $xml native multistatus document
	 * @return list<array{id: string, author: array{type: string, id: string, display_name: ?string}, created_at: ?string,
	 *     text: string, reply_to: ?string, verb: string, unread: ?bool, mentions: list<array{type: string, id: string,
	 *     display_name: string}>}> the comments in the native order (newest first)
	 * @throws RuntimeException when the document is not a multistatus answer
	 */
	public function comments(string $xml): array {
		$document = new DOMDocument();
		if ($xml === '' || str_contains($xml, '<!DOCTYPE') || !@$document->loadXML($xml, LIBXML_NONET)
			|| $document->documentElement?->namespaceURI !== self::DAV || $document->documentElement->localName !== 'multistatus') {
			throw new RuntimeException('Unexpected comments REPORT answer');
		}
		$rows = [];
		foreach ($document->getElementsByTagNameNS(self::DAV, 'prop') as $prop) {
			$rows[] = $this->row($prop);
		}
		return $rows;
	}

	/**
	 * @param DOMElement $prop the 200 property set of one comment
	 * @return array<string,mixed> one comment row
	 */
	private function row(DOMElement $prop): array {
		$value = fn (string $name): ?string => $this->child($prop, $name)?->textContent;
		$parent = $value('parentId');
		$unread = $value('isUnread');
		$mentions = [];
		foreach ($prop->getElementsByTagNameNS(self::OC, 'mention') as $mention) {
			$part = fn (string $name): string => (string)$this->child($mention, $name)?->textContent;
			$mentions[] = ['type' => $part('mentionType'), 'id' => $part('mentionId'), 'display_name' => $part('mentionDisplayName')];
		}
		return [
			'id' => (string)$value('id'),
			'author' => ['type' => (string)$value('actorType'), 'id' => (string)$value('actorId'), 'display_name' => $value('actorDisplayName')],
			'created_at' => self::iso($value('creationDateTime')),
			'text' => (string)$value('message'),
			'reply_to' => $parent === null || $parent === '' || $parent === '0' ? null : $parent,
			'verb' => (string)$value('verb'),
			'unread' => $unread === null || $unread === '' ? null : $unread === 'true',
			'mentions' => $mentions,
		];
	}

	/**
	 * @param DOMElement $parent element to look into
	 * @param string $name local name in the ownCloud namespace
	 * @return DOMElement|null the direct child, if present
	 */
	private function child(DOMElement $parent, string $name): ?DOMElement {
		foreach ($parent->childNodes as $node) {
			if ($node instanceof DOMElement && $node->namespaceURI === self::OC && $node->localName === $name) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * @param string|null $date HTTP date written by the native DateTime serializer (CommentsPlugin.php:74-76 / 75-77)
	 * @return string|null the same instant in ISO 8601 UTC, or null when absent or unreadable
	 */
	private static function iso(?string $date): ?string {
		if ($date === null || $date === '') {
			return null;
		}
		$parsed = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $date, new DateTimeZone('UTC'));
		return $parsed === false ? null : $parsed->format('Y-m-d\TH:i:s\Z');
	}
}
