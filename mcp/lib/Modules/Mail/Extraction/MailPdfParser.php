<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Extraction;

use OCA\Mcp\Platform\Text\PdfTextParser;
use Smalot\PdfParser\Config;

/**
 * smalot/pdfparser's Parser, reading the names of object streams ({@see PdfTextParser}), whose raw data parser decodes streams with {@see MailPdfFilters} ({@see MailPdfRawData}):
 * the library builds its raw parser itself and keeps it in a protected property, so it is replaced right after the
 * parent constructor, through that extension point and without changing the library.
 */
class MailPdfParser extends PdfTextParser {
	/**
	 * Builds the parser with the attachment's filters.
	 *
	 * @param Config $config parser configuration (decode budget, images not kept)
	 * @param MailPdfFilters $filters the stream filters with their budget marks
	 * @return void
	 */
	public function __construct(Config $config, MailPdfFilters $filters) {
		parent::__construct([], $config);
		$this->rawDataParser = new MailPdfRawData($config, $filters);
	}
}
