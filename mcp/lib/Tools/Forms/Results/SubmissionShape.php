<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

use OCA\Forms\Db\Answer;

/** Strips all respondent identities and exposes only admitted active answers. */
final class SubmissionShape {
	/** Receives the common native reader and the unique answer projection.
	 * @param SubmissionReads $reads Authorized selected-submission reads.
	 * @param AnswerShape $answers Active-map and Files admission.
	 */
	public function __construct(private SubmissionReads $reads, private AnswerShape $answers) {
	}
	/** Projects a selected header only; raw answers never enter the header digest.
	 * @param array $header Already authorized private header.
	 * @param array $active Active question map.
	 * @param string $uid Actor.
	 * @param bool $filesAllowed Independent Files grant.
	 * @param bool $list Whether reduce-limit guidance applies.
	 * @return array Public submission without account ids or omitted-answer counts.
	 */
	public function of(array $header, array $active, string $uid, bool $filesAllowed, bool $list = true): array {
		$items = [];
		foreach ($this->reads->answers($header['id']) as $answer) {
			if (!$answer instanceof Answer || (int)$answer->getSubmissionId() !== $header['id']) { throw new \UnexpectedValueException('Mismatched native answer'); }
			$item = $this->answers->of($answer, $active, $uid, $filesAllowed);
			if ($item !== null) {
				$items[] = $item;
				if (count($items) > ResultsBudget::ANSWERS) { throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure(ResultsMessages::budget($list)); }
			}
		}
		usort($items, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		return ['id' => $header['id'], 'timestamp' => $header['timestamp'], 'anonymous' => str_starts_with($header['userId'], 'anon-user-'), 'answers' => $items];
	}
}
