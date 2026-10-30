<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

use OCA\Forms\Db\Answer;
use OCA\Mcp\Tools\Forms\Attachments\ResultFileAccess;

/** Active-map admission precedes any text interpretation or file lookup. */
final class AnswerShape {
	/** Receives the independent Files authorization boundary.
	 * @param ResultFileAccess $files Actor-relative file projection.
	 */
	public function __construct(private ResultFileAccess $files) {
	}
	/** Preserves answer values, omitting unavailable question/file associations without counters or placeholders.
	 * @param Answer $answer Authorized submission's native answer.
	 * @param array $active Active question map restricted to the form.
	 * @param string $uid Actor.
	 * @param bool $filesAllowed Independent Files grant.
	 * @return array|null Whitelisted answer or uniform omission.
	 */
	public function of(Answer $answer, array $active, string $uid, bool $filesAllowed): ?array {
		$questionId = (int)$answer->getQuestionId();
		if (!isset($active[$questionId])) { return null; }
		$type = $active[$questionId]['type'];
		$row = ['id' => (int)$answer->getId(), 'questionId' => $questionId, 'type' => $type];
		if ($type === 'file' || $answer->getFileId() !== null) {
			if (!$filesAllowed || $answer->getFileId() === null) { return null; }
			$file = $this->files->resolve($uid, (int)$answer->getFileId());
			return $file === null ? null : ['type' => 'file'] + $row + ['attachment' => $file];
		}
		$text = ResultsBudget::text($answer->getText());
		if (!in_array($type, ['grid', 'ranking'], true)) { return $row + ['encoding' => 'text', 'value' => $text]; }
		try {
			$value = json_decode($text, true, 5, JSON_THROW_ON_ERROR);
			if (!is_array($value)) { throw new \UnexpectedValueException('Unrecognized structured answer'); }
			return $row + ['encoding' => 'forms-json', 'storedText' => $text, 'value' => $value];
		} catch (\JsonException|\UnexpectedValueException) {
			return $row + ['encoding' => 'unparsed', 'storedText' => $text, 'notice' => ResultsMessages::unparsed()];
		}
	}
}
