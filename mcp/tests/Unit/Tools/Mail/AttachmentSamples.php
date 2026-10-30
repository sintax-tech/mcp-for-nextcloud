<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

/**
 * Attachment contents built in the test itself, so no binary sample is checked in: a valid one-page PDF with a correct
 * cross-reference table, and ZIP archives shaped as DOCX, ODT or anything else.
 */
final class AttachmentSamples {
	/**
	 * A one-page PDF showing a line of text in Helvetica.
	 *
	 * @param string $text the line, ASCII without parentheses or backslashes
	 * @param string $contentFilter filter of the page content stream: "" (none) or "FlateDecode"
	 * @param string $extraTrailer more entries of the trailer dictionary, such as "/Encrypt 9 0 R"
	 * @param string $extraObject one more raw object body (number 6) such as a dictionary naming a filter
	 * @return string the PDF
	 */
	public static function pdf(string $text, string $contentFilter = '', string $extraTrailer = '', string $extraObject = ''): string {
		return self::pdfWithContent('BT /F1 18 Tf 20 100 Td (' . $text . ') Tj ET', $contentFilter === 'FlateDecode', $extraTrailer, $extraObject);
	}

	/**
	 * A one-page PDF whose page content stream is the given operators.
	 *
	 * @param string $content page content operators, Helvetica as /F1
	 * @param bool $flate whether the content stream is FlateDecode compressed
	 * @param string $extraTrailer more entries of the trailer dictionary
	 * @param string $extraObject one more raw object body (number 6)
	 * @param bool $dropChecksum whether the compressed stream lacks its final Adler-32 checksum, as damaged streams do
	 * @return string the PDF
	 */
	public static function pdfWithContent(string $content, bool $flate, string $extraTrailer = '', string $extraObject = '', bool $dropChecksum = false): string {
		$dictionary = '';
		if ($flate) {
			$content = (string)gzcompress($content, 9);
			$content = $dropChecksum ? substr($content, 0, -4) : $content;
			$dictionary = '/Filter /FlateDecode ';
		}
		$objects = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			'<< ' . $dictionary . '/Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		];
		if ($extraObject !== '') {
			$objects[] = $extraObject;
		}
		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $index => $body) {
			$offsets[] = strlen($pdf);
			$pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= 'xref' . "\n" . '0 ' . (count($objects) + 1) . "\n" . "0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}
		return $pdf . 'trailer' . "\n" . '<< /Size ' . (count($objects) + 1) . ' /Root 1 0 R ' . $extraTrailer . " >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}

	/**
	 * A DOCX holding two paragraphs.
	 *
	 * @param string $first first paragraph
	 * @param string $second second paragraph
	 * @return string the archive
	 */
	public static function docx(string $first, string $second): string {
		return self::zip([
			'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="w"><w:body><w:p><w:r><w:t>' . $first
				. '</w:t></w:r></w:p><w:p><w:r><w:t>' . $second . '</w:t></w:r></w:p></w:body></w:document>',
		]);
	}

	/**
	 * An ODT holding one paragraph.
	 *
	 * @param string $paragraph the paragraph
	 * @param bool $encrypted whether the manifest declares encryption
	 * @return string the archive
	 */
	public static function odt(string $paragraph, bool $encrypted = false): string {
		return self::zip([
			'mimetype' => 'application/vnd.oasis.opendocument.text',
			'META-INF/manifest.xml' => '<manifest:manifest xmlns:manifest="m"><manifest:file-entry manifest:full-path="content.xml">'
				. ($encrypted ? '<manifest:encryption-data manifest:checksum="x"/>' : '') . '</manifest:file-entry></manifest:manifest>',
			'content.xml' => '<office:document-content xmlns:office="o" xmlns:text="t"><office:body><office:text><text:p>' . $paragraph
				. '</text:p></office:text></office:body></office:document-content>',
		]);
	}

	/**
	 * A ZIP archive of the given entries.
	 *
	 * @param array<string, string> $entries content by entry name
	 * @param string|null $password encrypts every entry with AES-256 when given
	 * @return string the archive
	 */
	public static function zip(array $entries, ?string $password = null): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'mcp-zip');
		try {
			$zip = new \ZipArchive();
			$zip->open($path, \ZipArchive::OVERWRITE);
			foreach ($entries as $name => $content) {
				$zip->addFromString($name, $content);
				if ($password !== null) {
					$zip->setEncryptionName($name, \ZipArchive::EM_AES_256, $password);
				}
			}
			$zip->close();
			return (string)file_get_contents($path);
		} finally {
			@unlink($path);
		}
	}
}
