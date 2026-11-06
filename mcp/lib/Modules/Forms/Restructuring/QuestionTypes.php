<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

/** Question types the structure tools accept, decided by the 1.3.0 scope (F5) and the coordination decision of 06/11/2026. */
final class QuestionTypes {
	/** Types a new question can have: every native answer type except the retired datetime. */
	public const CREATABLE = ['short', 'long', 'color', 'date', 'time', 'linearscale', 'multiple', 'multiple_unique', 'dropdown', 'file', 'grid', 'ranking'];
	/**
	 * Types an existing question can change to. Grid is excluded: it needs extraSettings.questionType, which the native
	 * updateQuestion only validates against the current type, so a single PATCH can never reach a valid grid.
	 */
	public const TARGETS = ['short', 'long', 'color', 'date', 'time', 'linearscale', 'multiple', 'multiple_unique', 'dropdown', 'file', 'ranking'];
	/** Cell types of a grid question (native extraSettings.questionType). */
	public const GRID_TYPES = ['radio', 'checkbox', 'number'];

	/** Constants only: prevents instantiation. */
	private function __construct() {
	}
}
