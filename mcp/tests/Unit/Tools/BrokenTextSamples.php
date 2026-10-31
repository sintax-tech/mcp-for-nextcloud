<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Tests\Unit\Tools\Mail\AttachmentSamples;

/**
 * Documents whose parser output is not valid UTF-8 (issue #25), built in the test itself so no binary sample is checked in
 * and the bytes are reproducible from this file alone.
 *
 * - {@see self::pdf()}: a one-page PDF whose Type0 font maps glyphs through a ToUnicode CMap; glyph 3 maps to the UTF-16
 *   surrogate pair D83D DE00 (U+1F600). smalot/pdfparser writes each half as its own CESU-8 sequence (ED A0 BD ED B8 80),
 *   which json_encode refuses as "Malformed UTF-8 characters".
 * - {@see self::docx()} and {@see self::odt()}: document XML written in Windows-1252, so "ação" reaches the text as the
 *   single bytes E7 E3.
 */
final class BrokenTextSamples {
	/** What the PDF shows, as valid UTF-8: glyphs 1, 2 and 3. */
	public const PDF_TEXT = "Aá\u{1F600}";

	/** What the DOCX and ODT paragraphs say, as valid UTF-8. */
	public const LATIN_TEXT = 'Relatório de ação';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The PDF with the surrogate pair in its ToUnicode map.
	 *
	 * @return string the PDF, with a correct cross-reference table
	 */
	public static function pdf(): string {
		$cmap = '/CIDInit /ProcSet findresource begin 12 dict begin begincmap /CMapName /Mcp-Issue25 def'
			. ' 1 begincodespacerange <0000> <FFFF> endcodespacerange'
			. ' 3 beginbfchar <0001> <0041> <0002> <00E1> <0003> <D83DDE00> endbfchar'
			. ' endcmap CMapName currentdict /CMap defineresource pop end end';
		$content = 'BT /F1 12 Tf 20 100 Td <000100020003> Tj ET';
		$objects = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			self::stream($content),
			'<< /Type /Font /Subtype /Type0 /BaseFont /Mcp-Issue25 /Encoding /Identity-H /ToUnicode 6 0 R >>',
			self::stream($cmap),
		];
		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $index => $body) {
			$offsets[] = strlen($pdf);
			$pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}
		return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}

	/**
	 * A DOCX whose paragraph is in Windows-1252.
	 *
	 * @return string the archive
	 */
	public static function docx(): string {
		return AttachmentSamples::docx(self::latin(), self::latin());
	}

	/**
	 * An ODT whose paragraph is in Windows-1252.
	 *
	 * @return string the archive
	 */
	public static function odt(): string {
		return AttachmentSamples::odt(self::latin());
	}

	/**
	 * {@see self::LATIN_TEXT} in Windows-1252.
	 *
	 * @return string the single-byte text
	 */
	private static function latin(): string {
		return (string)mb_convert_encoding(self::LATIN_TEXT, 'Windows-1252', 'UTF-8');
	}

	/**
	 * An uncompressed stream object body.
	 *
	 * @param string $data stream content
	 * @return string the dictionary and the stream
	 */
	private static function stream(string $data): string {
		return '<< /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
	}
}
