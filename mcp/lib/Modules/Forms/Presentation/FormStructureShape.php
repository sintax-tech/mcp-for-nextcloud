<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

use OCA\Mcp\Modules\Forms\Presentation\FormsText;

/** Keeps only the native visible question and option structure, with bounded untrusted text. */
final class FormStructureShape {
	private const EXTRA = ['allowOtherAnswer', 'allowedFileExtensions', 'allowedFileTypes', 'dateMax', 'dateMin', 'dateRange', 'maxAllowedFilesCount', 'maxFileSize', 'optionsHighest', 'optionsLabelHighest', 'optionsLabelLowest', 'optionsLimitMax', 'optionsLimitMin', 'optionsLowest', 'shuffleOptions', 'timeMax', 'timeMin', 'timeRange', 'validationRegex', 'validationType', 'questionType'];

	/** Shapes only nondeleted questions returned by the native service.
	 * @param array<array<string,mixed>> $questions getForm questions, already filtered by the service.
	 * @return list<array<string,mixed>> Whitelisted questions with native order/identity/options.
	 */
	public function of(array $questions): array {
		$result = [];
		foreach ($questions as $question) {
			if ((int)($question['order'] ?? 0) <= 0) { continue; }
			$item = array_intersect_key($question, array_flip(['id', 'order', 'type', 'isRequired', 'name', 'text', 'description', 'extraSettings']));
			foreach (['name', 'text', 'description'] as $key) { $item[$key] = FormsText::clean($item[$key] ?? ''); }
			$item['extraSettings'] = $this->settings(array_intersect_key((array)($item['extraSettings'] ?? []), array_flip(self::EXTRA)));
			$item['options'] = [];
			foreach ($question['options'] ?? [] as $option) {
				$item['options'][] = ['id' => (int)$option['id'], 'order' => (int)$option['order'], 'text' => FormsText::clean($option['text']), 'optionType' => $option['optionType'] ?? null];
			}
			$result[] = $item;
		}
		return $result;
	}

	/** Cleans only the whitelisted scalar or flat-list settings, without interpreting regexes or URLs.
	 * @param array<string,mixed> $settings Native relevant settings.
	 * @return array<string,mixed> Plain scalar/list data.
	 */
	private function settings(array $settings): array {
		foreach ($settings as $key => $value) {
			if (is_string($value)) { $settings[$key] = FormsText::clean($value); }
			elseif (is_array($value)) { $settings[$key] = array_map(FormsText::clean(...), $value); }
			elseif (!is_scalar($value) && $value !== null) { unset($settings[$key]); }
		}
		return $settings;
	}
}
