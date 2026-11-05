<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Extraction;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\RawData\RawDataParser;

/**
 * smalot/pdfparser's raw data parser with {@see MailPdfFilters} as its stream filters, set in the protected property
 * the library reads them from.
 */
class MailPdfRawData extends RawDataParser {
	/**
	 * Builds the raw parser with the attachment's filters.
	 *
	 * @param Config $config parser configuration
	 * @param MailPdfFilters $filters the stream filters with their budget marks
	 * @return void
	 */
	public function __construct(Config $config, MailPdfFilters $filters) {
		parent::__construct([], $config);
		$this->filterHelper = $filters;
	}
}
