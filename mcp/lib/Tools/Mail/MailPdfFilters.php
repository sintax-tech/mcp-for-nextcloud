<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use Smalot\PdfParser\RawData\FilterHelper;

/**
 * The stream filters of smalot/pdfparser for a mail attachment, with a decoding budget that holds and tells when it
 * was hit, through the parser's own extension points (its protected decoder methods), never a change to the library.
 *
 * - FlateDecode inflates incrementally, in small input steps, counting the output; past the budget it stops at once and
 *   marks the document as over budget, instead of the library's fallback, which on overflow re-reads the stream and
 *   returns its first bytes as if they were all of it.
 * - LZWDecode and RunLengthDecode, which the library expands without any limit, never run: a stream naming them marks
 *   the document as refused (the raw preflight of {@see MailAttachmentPdf} already refuses such files; this is the
 *   decoder-side guarantee).
 * The library swallows decoder exceptions and keeps the stream undecoded, so the outcome is kept here and
 * {@see MailAttachmentPdf} refuses the document after parsing when either mark is set: the text never comes back
 * silently incomplete.
 */
class MailPdfFilters extends FilterHelper {
	/** Input bytes fed to zlib per step: one step inflates to at most about 1032 times this. */
	private const INFLATE_STEP = 256;

	/** Whether a FlateDecode stream decoded to more than the budget. */
	public bool $overBudget = false;

	/** Whether a stream named a filter that has no budget at all. */
	public bool $unbounded = false;

	/**
	 * FlateDecode within the budget, or a mark and an exception past it.
	 *
	 * @param string $data compressed stream (zlib format)
	 * @param int $decodeMemoryLimit most bytes the stream may decode to; 0 is refused as no budget
	 * @return string|null the whole decoded stream, never a cut one
	 * @throws \RuntimeException when the stream is past the budget or not zlib data
	 */
	protected function decodeFilterFlateDecode(string $data, int $decodeMemoryLimit): ?string {
		if ($decodeMemoryLimit <= 0) {
			$this->unbounded = true;
			throw new \RuntimeException('FlateDecode without a budget');
		}
		$context = inflate_init(ZLIB_ENCODING_DEFLATE);
		if ($context === false) {
			throw new \RuntimeException('zlib unavailable');
		}
		$out = '';
		for ($offset = 0, $length = strlen($data); $offset < $length; $offset += self::INFLATE_STEP) {
			$chunk = @inflate_add($context, substr($data, $offset, self::INFLATE_STEP), ZLIB_SYNC_FLUSH);
			if ($chunk === false) {
				break;
			}
			$out .= $chunk;
			if (strlen($out) > $decodeMemoryLimit) {
				$this->overBudget = true;
				throw new \RuntimeException('FlateDecode over budget');
			}
			if (inflate_get_status($context) === ZLIB_STREAM_END) {
				break;
			}
		}
		if ($out === '') {
			throw new \RuntimeException('FlateDecode: invalid data');
		}
		return $out;
	}

	/**
	 * LZWDecode never runs: the library expands it without limit.
	 *
	 * @param string $data compressed stream
	 * @return string never returned
	 * @throws \RuntimeException always
	 */
	protected function decodeFilterLZWDecode(string $data): string {
		$this->unbounded = true;
		throw new \RuntimeException('LZWDecode has no budget');
	}

	/**
	 * RunLengthDecode never runs: the library expands it without limit.
	 *
	 * @param string $data compressed stream
	 * @return string never returned
	 * @throws \RuntimeException always
	 */
	protected function decodeFilterRunLengthDecode(string $data): string {
		$this->unbounded = true;
		throw new \RuntimeException('RunLengthDecode has no budget');
	}
}
