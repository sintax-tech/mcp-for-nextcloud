<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Extraction;

use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;

use OCA\Mcp\Platform\Text\ExtractedText;
use OCA\Mcp\Platform\Text\PdfSupplementaryCharacters;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Smalot\PdfParser\Config;

/**
 * The text of a PDF attachment, with smalot/pdfparser (the parser files_read uses) inside a decoding budget.
 *
 * 1. The raw file is checked first ({@see MailPdfPreflight}): encrypted files, unbounded filters, filters taken from
 *    indirect objects and more than 128 streams are refused before the parser runs.
 * 2. The parser decodes streams with {@see MailPdfFilters} ({@see MailPdfParser}): every FlateDecode stream decodes to at
 *    most {@see self::STREAM_BYTES} or marks the document as over budget, and the unbounded filters never run. Image
 *    content is not kept. The whole decoding stays under 128 × 256 KiB, 32 MiB, whatever the 1 MiB file holds.
 * 3. A document marked over budget, or naming an unbounded filter, is refused after parsing: the library would have
 *    kept such a stream undecoded or cut, and its text would come back silently incomplete.
 * Any parser failure is a refusal, never its message.
 */
final class MailAttachmentPdf {
	/** Most bytes one stream decodes to. */
	public const STREAM_BYTES = 262144;

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Whether the content starts as a PDF. Readers also accept a header after some leading bytes; that is not read here,
	 * so text that merely mentions the header is never taken for a PDF.
	 *
	 * @param string $bytes the decoded attachment
	 * @return bool true when the content starts with `%PDF-`
	 */
	public static function looksLikePdf(string $bytes): bool {
		return str_starts_with($bytes, '%PDF-');
	}

	/**
	 * The text of the PDF, whole or not at all.
	 *
	 * @param string $bytes the decoded attachment, within the attachment budget
	 * @return string the text of every page
	 * @throws ToolFailure when the PDF is encrypted, outside the decoding budget, or cannot be parsed
	 */
	public static function text(string $bytes): string {
		MailPdfPreflight::check($bytes);
		$config = new Config();
		$config->setRetainImageContent(false);
		$config->setDecodeMemoryLimit(self::STREAM_BYTES);
		$filters = new MailPdfFilters();
		try {
			$document = (new MailPdfParser($config, $filters))->parseContent($bytes);
			PdfSupplementaryCharacters::restore($document);
			$text = $document->getText();
		} catch (\Throwable $e) {
			throw new ToolFailure($filters->overBudget ? MailAttachmentMessages::pdfOverBudget() : MailAttachmentMessages::unreadable(), 0, $e);
		}
		if ($filters->overBudget) {
			throw new ToolFailure(MailAttachmentMessages::pdfOverBudget());
		}
		if ($filters->unbounded) {
			throw new ToolFailure(MailAttachmentMessages::unreadable());
		}
		return ExtractedText::utf8($text);
	}
}
