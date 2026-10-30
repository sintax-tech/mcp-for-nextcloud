<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

use OCA\Mcp\Tools\ToolFailure;

/** Refuses excessive projected responses whole; it does not limit native allocation or repair answer bytes. */
final class ResultsBudget {
	public const STRUCTURE = 2000;
	public const ANSWERS = 2000;
	public const PAGE_ANSWERS = 10000;
	public const FIELD_CHARS = 8000;
	public const CHARS = 100000;
	public const BYTES = 1048576;
	/** Requires exact valid UTF8 before character counting or nested answer JSON parsing.
	 * @param mixed $text Projected native text, not an omitted answer.
	 * @return string Unmodified text.
	 */
	public static function text(mixed $text): string {
		if (!is_string($text) || !mb_check_encoding($text, 'UTF-8')) { throw new ToolFailure(ResultsMessages::utf8()); }
		return $text;
	}
	/** Counts only whitelisted/projected strings and retains complete answers or refuses the whole page.
	 * @param array $data Public untrusted projection.
	 * @param array $meta Trusted pagination metadata.
	 * @param bool $list Whether reduce-limit guidance is useful.
	 * @return array Complete envelope.
	 */
	public static function envelope(array $data, array $meta, bool $list): array {
		$chars = 0;
		self::strings($data, $chars, $list);
		$submissions = $list ? $data['submissions'] : [$data['submission']];
		$count = 0;
		foreach ($submissions as $submission) {
			$n = count($submission['answers']); $count += $n;
			if ($n > self::ANSWERS || $count > self::PAGE_ANSWERS) { throw new ToolFailure(ResultsMessages::budget($list)); }
		}
		$result = ['untrusted' => ['notice' => ResultsMessages::notice()] + $data] + $meta + ['limits' => ['pageSize' => 50, 'headers' => SubmissionReads::MAX_HEADERS,
			'structureItems' => self::STRUCTURE, 'answersPerSubmission' => self::ANSWERS, 'answersPerPage' => self::PAGE_ANSWERS,
			'fieldChars' => self::FIELD_CHARS, 'responseChars' => self::CHARS, 'responseBytes' => self::BYTES]];
		if (strlen(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > self::BYTES) { throw new ToolFailure(ResultsMessages::budget($list)); }
		return $result;
	}
	/** Recurses through projected arrays only; omitted data must never affect errors or budgets.
	 * @param mixed $value Public field.
	 * @param int $chars Shared character count.
	 * @param bool $list Page/single guidance.
	 * @return void
	 */
	private static function strings(mixed $value, int &$chars, bool $list): void {
		if (is_string($value)) {
			$length = mb_strlen(self::text($value), 'UTF-8'); $chars += $length;
			if ($length > self::FIELD_CHARS || $chars > self::CHARS) { throw new ToolFailure(ResultsMessages::budget($list)); }
		} elseif (is_array($value)) { foreach ($value as $key => $item) { if (is_string($key)) { self::text($key); } self::strings($item, $chars, $list); } }
	}
}
