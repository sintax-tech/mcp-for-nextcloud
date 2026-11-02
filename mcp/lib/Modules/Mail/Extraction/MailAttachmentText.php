<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Extraction;

use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailBodyText;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The text of an attachment, for the kinds that can be read as text, decided by the content.
 *
 * The name, the extension and the declared type are written by the sender, so none of them decides alone:
 * - content that starts as a PDF is read as a PDF ({@see MailAttachmentPdf}) and content that starts as a ZIP archive
 *   as DOCX or ODT ({@see MailAttachmentDocument}), whatever the declared type says;
 * - other content is text only when its declared type is one of {@see self::TEXT_TYPES} or text/html and it holds no
 *   NUL byte once converted from its declared charset; HTML (declared, or text that starts as an HTML document) is
 *   turned into text by {@see MailBodyText::htmlToText()}, never returned as markup and never fetching anything;
 * - anything else is refused, before any parser runs.
 * No macro, script, OCR or external service is involved; the PDF and ZIP readers are the ones the app already ships.
 */
class MailAttachmentText {
	/** Declared types read as plain text when the content agrees. */
	public const TEXT_TYPES = ['text/plain', 'text/csv', 'text/markdown', 'text/calendar', 'text/vcard', 'text/x-vcard', 'application/json'];

	/** Declared type of HTML. */
	private const HTML_TYPE = 'text/html';

	/**
	 * Receives the document reader.
	 *
	 * @param MailAttachmentDocument $documents reads DOCX and ODT archives
	 * @return void
	 */
	public function __construct(
		private MailAttachmentDocument $documents,
	) {
	}

	/**
	 * The kind and the text of the attachment.
	 *
	 * @param string $bytes the decoded attachment, within the attachment budget
	 * @param MailAttachmentPart $part what the message structure declares about it
	 * @return array{kind:string, text:string} kind pdf, docx, odt, html or text, and the raw text (not yet cleaned or cut)
	 * @throws ToolFailure when the kind cannot be read as text, or the file is encrypted, damaged or over the limits
	 */
	public function extract(string $bytes, MailAttachmentPart $part): array {
		if (MailAttachmentPdf::looksLikePdf($bytes)) {
			return ['kind' => 'pdf', 'text' => MailAttachmentPdf::text($bytes)];
		}
		if (MailAttachmentDocument::looksLikeZip($bytes)) {
			return $this->documents->text($bytes);
		}
		if ($part->type !== self::HTML_TYPE && !in_array($part->type, self::TEXT_TYPES, true)) {
			throw new ToolFailure(MailAttachmentMessages::unsupportedType());
		}
		$text = self::utf8($bytes, $part->charset);
		if (str_contains($text, "\0")) {
			throw new ToolFailure(MailAttachmentMessages::unsupportedType());
		}
		if ($part->type === self::HTML_TYPE || self::looksLikeHtml($text)) {
			return ['kind' => 'html', 'text' => MailBodyText::htmlToText($text)];
		}
		return ['kind' => 'text', 'text' => $text];
	}

	/**
	 * The content as UTF-8, from its declared charset; an unknown or missing charset reads as UTF-8, and invalid
	 * sequences are replaced, never kept.
	 *
	 * @param string $bytes the decoded attachment
	 * @param string|null $charset declared charset
	 * @return string valid UTF-8 without a byte order mark
	 */
	private static function utf8(string $bytes, ?string $charset): string {
		$text = null;
		if ($charset !== null && !in_array($charset, ['utf-8', 'us-ascii'], true)) {
			try {
				$converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
				$text = is_string($converted) ? $converted : null;
			} catch (\ValueError) {
				// A charset mbstring does not know: read as UTF-8 below.
			}
		}
		$text = mb_scrub($text ?? $bytes, 'UTF-8');
		return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
	}

	/**
	 * Whether text declared as another type starts as an HTML document.
	 *
	 * @param string $text the text
	 * @return bool true when it opens with a doctype, html, head or body tag
	 */
	private static function looksLikeHtml(string $text): bool {
		return preg_match('~^\s*(<!doctype\s+html|<html\b|<head\b|<body\b)~i', substr($text, 0, 1024)) === 1;
	}
}
