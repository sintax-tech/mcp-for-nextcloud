<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\L10n\Translator;

/** Explains explicit key removal, native null and false normalization without inventing a storage reset or upload guarantee. */
final class EditSettingEffects {
	/** Projects only admitted settings operations and their source-proven absence defaults.
	 * @param array<string,mixed> $request Closed request.
	 * @return array<string,mixed>|null Complete setting-operation explanation.
	 */
	public static function of(array $request): ?array {
		if ($request['option']) { return null; }
		$changes = $request['changes']; $defaults = []; $removed = $changes['unset_settings'] ?? [];
		foreach ($changes['settings'] ?? [] as $key => $value) { if ($value === false) { $removed[] = $key; } }
		foreach (array_unique($removed) as $key) {
			$defaults[$key] = match ($key) {
				'allowOtherAnswer','shuffleOptions','dateRange','timeRange' => false,
				'optionsLowest' => 1, 'optionsHighest' => 5,
				'optionsLabelLowest','optionsLabelHighest' => Translator::t('Native localized scale label'),
				'validationType' => Translator::t('Text without a specific validation type'),
				'validationRegex' => Translator::t('No regex key; the final native validation type still applies'),
				'allowedFileTypes','allowedFileExtensions' => Translator::t('This filter is absent; remaining native filters still apply'),
				default => Translator::t('No question-specific bound for this key; other native limits still apply'),
			};
		}
		return ['explicitlyRemoved' => $changes['unset_settings'] ?? [],
			'nativeNullValues' => array_keys(array_filter($changes['settings'] ?? [], static fn (mixed $v): bool => $v === null)),
			'falseNormalizedToAbsent' => array_keys(array_filter($changes['settings'] ?? [], static fn (mixed $v): bool => $v === false)),
			'defaultWhenAbsent' => $defaults];
	}
}
