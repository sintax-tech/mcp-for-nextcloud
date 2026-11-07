<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Creation;

use DOMComment;
use DOMDocument;
use DOMDocumentType;
use DOMProcessingInstruction;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailDraftMessages;

/** Parses draft fragments as UTF-8 with the same document shape on legacy and HTML5 libxml parsers. */
final class MailDraftHtmlDocument {
	/** Encoding declaration prepended to every parse; libxml has no other UTF-8 hint for HTML input. */
	private const HINT = '<?xml encoding="UTF-8">';

	/** Static parser only. @return void */
	private function __construct() {}

	/**
	 * Parses with explicit UTF-8 and no network; only unknown-element diagnostics are recoverable.
	 *
	 * Other recovery may lose duplicate attributes or move descendants out of head/form before the filter sees them.
	 * libxml up to 2.13 keeps the hint as a processing instruction and libxml 2.14 or later, whose HTML5 tokenizer
	 * reads it as a bogus comment, keeps it as a comment; only that first node after the doctype is detached, so the
	 * hint never reaches the filter, the report or the inventory. Content from the fragment cannot precede it.
	 *
	 * @param string $html UTF-8 fragment
	 * @return DOMDocument parsed document without the encoding hint
	 * @throws ToolFailure for a parse failure or information-losing recovery
	 */
	public static function parse(string $html): DOMDocument {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		try {
			$document = new DOMDocument('1.0', 'UTF-8');
			$loaded = $document->loadHTML(self::HINT . ($html === '' ? '<body></body>' : $html), LIBXML_NONET);
			$errors = libxml_get_errors();
			if (!$loaded || array_filter($errors, static fn ($error): bool => $error->code !== 801)) {
				throw new ToolFailure(MailDraftMessages::htmlInvalid());
			}
			$hint = $document->firstChild instanceof DOMDocumentType ? $document->firstChild->nextSibling : $document->firstChild;
			if (($hint instanceof DOMProcessingInstruction && $hint->target === 'xml')
				|| ($hint instanceof DOMComment && $hint->data === substr(self::HINT, 1, -1))) {
				$document->removeChild($hint);
			}
			return $document;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}
}
