<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Tools\ToolFailure;
use OCP\ITempManager;

/**
 * The text of a DOCX or ODT attachment, read from the ZIP archive the way files_read reads them (the document XML,
 * paragraph breaks kept, tags removed), within limits of an attachment.
 *
 * The archive is recognized by what it holds, never by name or declared type: ODT by its `mimetype` entry, DOCX by
 * `[Content_Types].xml` and `word/document.xml`; any other archive is refused. Before anything is inflated the archive
 * may list at most {@see self::MAX_ENTRIES} entries, the document entry must not be encrypted and must declare at most
 * {@see self::MAX_XML_BYTES}, and it is then read with that same length cap, so a header that lies about the size cannot
 * inflate more. An ODT whose manifest declares encryption is refused. ZipArchive reads from a temporary file of the
 * server ({@see ITempManager}), removed at once.
 */
final class MailAttachmentDocument {
	/** Most entries an archive may list. */
	public const MAX_ENTRIES = 1000;

	/** Most bytes of the document XML inflated. */
	public const MAX_XML_BYTES = 4194304;

	/** Most bytes of the `mimetype` entry read. */
	private const MIMETYPE_BYTES = 256;

	/** What the `mimetype` entry of an ODT holds. */
	private const ODT_MIME = 'application/vnd.oasis.opendocument.text';

	/** Breaks of a DOCX document body, applied before the tags are removed (as files_read applies them). */
	private const DOCX_BREAKS = ['#</w:p>#' => "\n", '#<w:tab\s*/>#' => "\t", '#<w:(br|cr)\b[^>]*/>#' => "\n"];

	/** Breaks of an ODT document body, applied before the tags are removed (as files_read applies them). */
	private const ODT_BREAKS = ['#</text:(p|h)>#' => "\n", '#<text:tab\s*/>#' => "\t", '#<text:line-break\s*/>#' => "\n", '#<text:s\s*/>#' => ' '];

	/**
	 * Receives the temporary file manager.
	 *
	 * @param ITempManager $tempManager temporary files of the server
	 * @return void
	 */
	public function __construct(
		private ITempManager $tempManager,
	) {
	}

	/**
	 * Whether the content starts as a ZIP archive (a local file header).
	 *
	 * @param string $bytes the decoded attachment
	 * @return bool true for "PK\x03\x04" at the start
	 */
	public static function looksLikeZip(string $bytes): bool {
		return str_starts_with($bytes, "PK\x03\x04");
	}

	/**
	 * The kind and the text of the document.
	 *
	 * @param string $bytes the decoded attachment, within the attachment budget
	 * @return array{kind:string, text:string} kind "docx" or "odt", and the text
	 * @throws ToolFailure when the archive is no DOCX or ODT, is encrypted, damaged or over the limits
	 */
	public function text(string $bytes): array {
		$path = $this->tempManager->getTemporaryFile('.zip');
		if ($path === false) {
			throw new ToolFailure(MailAttachmentMessages::unreadable());
		}
		try {
			if (file_put_contents($path, $bytes) !== strlen($bytes)) {
				throw new ToolFailure(MailAttachmentMessages::unreadable());
			}
			$zip = new \ZipArchive();
			if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
				throw new ToolFailure(MailAttachmentMessages::unreadable());
			}
			try {
				return $this->document($zip);
			} finally {
				$zip->close();
			}
		} finally {
			@unlink($path);
		}
	}

	/**
	 * The kind and the text of an opened archive.
	 *
	 * @param \ZipArchive $zip the archive
	 * @return array{kind:string, text:string} kind "docx" or "odt", and the text
	 * @throws ToolFailure when the archive is no DOCX or ODT, is encrypted, damaged or over the limits
	 */
	private function document(\ZipArchive $zip): array {
		if ($zip->numFiles > self::MAX_ENTRIES) {
			throw new ToolFailure(MailAttachmentMessages::unreadable());
		}
		$mimetype = $this->entry($zip, 'mimetype', self::MIMETYPE_BYTES);
		if ($mimetype !== null && trim($mimetype) === self::ODT_MIME) {
			$manifest = $this->entry($zip, 'META-INF/manifest.xml', self::MAX_XML_BYTES);
			if ($manifest !== null && str_contains($manifest, 'encryption-data')) {
				throw new ToolFailure(MailAttachmentMessages::encrypted());
			}
			return ['kind' => 'odt', 'text' => self::xmlText($this->required($zip, 'content.xml'), self::ODT_BREAKS)];
		}
		if ($zip->statName('[Content_Types].xml') !== false && $zip->statName('word/document.xml') !== false) {
			return ['kind' => 'docx', 'text' => self::xmlText($this->required($zip, 'word/document.xml'), self::DOCX_BREAKS)];
		}
		throw new ToolFailure(MailAttachmentMessages::unsupportedType());
	}

	/**
	 * A document entry that must be there and readable within the limit.
	 *
	 * @param \ZipArchive $zip the archive
	 * @param string $name entry name
	 * @return string the inflated entry
	 * @throws ToolFailure when it is missing, encrypted, too large or unreadable
	 */
	private function required(\ZipArchive $zip, string $name): string {
		$content = $this->entry($zip, $name, self::MAX_XML_BYTES);
		if ($content === null) {
			throw new ToolFailure(MailAttachmentMessages::unreadable());
		}
		return $content;
	}

	/**
	 * An entry inflated within a limit, after its declared size and encryption are checked.
	 *
	 * @param \ZipArchive $zip the archive
	 * @param string $name entry name
	 * @param int $limit most bytes inflated
	 * @return string|null the entry, or null when the archive has none of that name
	 * @throws ToolFailure when the entry is encrypted, declares more than the limit or cannot be read
	 */
	private function entry(\ZipArchive $zip, string $name, int $limit): ?string {
		$stat = $zip->statName($name);
		if ($stat === false) {
			return null;
		}
		if (($stat['encryption_method'] ?? 0) !== 0) {
			throw new ToolFailure(MailAttachmentMessages::encrypted());
		}
		if (!is_int($stat['size'] ?? null) || $stat['size'] > $limit) {
			throw new ToolFailure(MailAttachmentMessages::unreadable());
		}
		$content = $zip->getFromName($name, $limit);
		if (!is_string($content)) {
			throw new ToolFailure(MailAttachmentMessages::unreadable());
		}
		return $content;
	}

	/**
	 * The visible text of a document XML.
	 *
	 * @param string $xml the document XML
	 * @param array<string, string> $breaks regex => replacement applied before the tags are removed
	 * @return string the text
	 */
	private static function xmlText(string $xml, array $breaks): string {
		$text = strip_tags(preg_replace(array_keys($breaks), array_values($breaks), $xml) ?? $xml);
		return trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
	}
}
