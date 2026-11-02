<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

/** A response budget applied only after native authorization; cuts are explicit, including empty final fields. */
final class PollsText {
	/** @var int characters still available on this page */
	private int $remaining = 100000;
	/** @var int total characters of authorized, sanitized fields before cutting */
	private int $total = 0;
	/** @var list<array{field:string,totalChars:int,returnedChars:int}> cuts */
	private array $cuts = [];

	/**
	 * Sanitizes one authorized text and applies per-field and per-page character budgets.
	 * @param string $value native author text
	 * @param string $field structural path, never an author-provided field name
	 * @return string plain text repaired to valid UTF-8 and bounded, with literal markup/comparators preserved
	 */
	public function field(string $value, string $field): string {
		// Same plain-text cleanup as Tables RowCellText: keep tab/line breaks and every literal angle bracket.
		$value = (string)preg_replace('/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', mb_scrub($value, 'UTF-8'));
		$total = mb_strlen($value, 'UTF-8');
		$length = min($total, 20000, $this->remaining);
		$this->total += $total;
		$this->remaining -= $length;
		if ($length < $total) {
			$this->cuts[] = ['field' => $field, 'totalChars' => $total, 'returnedChars' => $length];
		}
		return mb_substr($value, 0, $length, 'UTF-8');
	}

	/**
	 * Reports cuts even when the page budget made a final nonempty field empty.
	 * @return array{truncated:bool,totalChars:int,truncatedFields:list<array{field:string,totalChars:int,returnedChars:int}>} metadata
	 */
	public function metadata(): array {
		return ['truncated' => $this->cuts !== [], 'totalChars' => $this->total, 'truncatedFields' => $this->cuts];
	}
}
