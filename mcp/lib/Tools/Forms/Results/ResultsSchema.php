<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

use OCA\Forms\Db\Question;
use OCA\Forms\Db\Option;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Builds the active, form-relative schema before answering; unknown active types fail even without a page answer. */
final class ResultsSchema {
	/** Receives native read collaborators.
	 * @param SubmissionReads $reads Common mapper reader.
	 */
	public function __construct(private SubmissionReads $reads) {
	}
	/** Loads only active questions/options; no per-answer foreign/deleted question lookup exists.
	 * @param int $formId Authorized form.
	 * @return array<int,array> Whitelisted active map.
	 */
	public function active(int $formId): array {
		$map = []; $items = 0;
		foreach ($this->reads->questions($formId) as $question) {
			if (!$question instanceof Question || (int)$question->getFormId() !== $formId || (int)$question->getOrder() === 0 || isset($map[(int)$question->getId()])) { throw new ToolFailure(ResultsMessages::structure()); }
			$type = $question->getType();
			if (!in_array($type, ResultsTypes::SUPPORTED, true) || ++$items > ResultsBudget::STRUCTURE) { throw new ToolFailure(ResultsMessages::structure()); }
			$row = ['id' => (int)$question->getId(), 'type' => $type, 'name' => ResultsBudget::text($question->getName() ?? ''), 'text' => ResultsBudget::text($question->getText()), 'options' => [], '_order' => (int)$question->getOrder()];
			$options = $this->reads->options($row['id']);
			foreach ($options as $option) {
				if (!$option instanceof Option || (int)$option->getQuestionId() !== $row['id'] || ++$items > ResultsBudget::STRUCTURE) { throw new ToolFailure(ResultsMessages::structure()); }
				$row['options'][] = ['id' => (int)$option->getId(), 'text' => ResultsBudget::text($option->getText()), 'optionType' => ResultsBudget::text($option->getOptionType() ?? ''), '_order' => (int)$option->getOrder()];
			}
			usort($row['options'], static fn (array $a, array $b): int => [$a['_order'], $a['id']] <=> [$b['_order'], $b['id']]);
			foreach ($row['options'] as &$option) { unset($option['_order']); } unset($option);
			$map[$row['id']] = $row;
		}
		uasort($map, static fn (array $a, array $b): int => [$a['_order'], $a['id']] <=> [$b['_order'], $b['id']]);
		foreach ($map as &$question) { unset($question['_order']); } unset($question);
		return $map;
	}
}
