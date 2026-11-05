<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Forms\Editing;

/** Publishes the fixed settings value domain; the current question type and final-map relationships are checked after ACL. */
final class EditSettingSchema
{
	/** Builds the supported scalar/list schema for one known key, without admitting an untyped payload.
	 * @param string $key Fixed MCP setting key.
	 * @return array<string,mixed> JSON Schema subset understood by the existing validator.
	 */
	public static function value(string $key): array
	{
		return match ($key) {
			'allowOtherAnswer', 'shuffleOptions' => ['type' => 'boolean'],
			'dateRange', 'timeRange' => ['type' => ['boolean', 'null']],
			'dateMin', 'dateMax' => ['type' => ['integer', 'null']],
			'timeMin', 'timeMax', 'optionsLabelLowest', 'optionsLabelHighest' => ['type' => ['string', 'null'], 'maxLength' => 1024],
			'optionsLowest' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 1],
			'optionsHighest' => ['type' => ['integer', 'null'], 'minimum' => 2, 'maximum' => 10],
			'optionsLimitMin', 'optionsLimitMax' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 200],
			'maxAllowedFilesCount' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
			'maxFileSize' => ['type' => 'integer', 'minimum' => 0],
			'validationType' => ['type' => 'string', 'enum' => ['email', 'phone', 'number', 'regex']],
			'validationRegex' => ['type' => 'string', 'maxLength' => 1024],
			'allowedFileTypes' => ['type' => 'array', 'maxItems' => 32, 'items' => ['type' => 'string', 'enum' => ['image', 'x-office/document', 'x-office/presentation', 'x-office/spreadsheet']]],
			'allowedFileExtensions' => ['type' => 'array', 'maxItems' => 32, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16]],
			default => throw new \LogicException('Unknown fixed editing schema key'),
		};
	}
}
