<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

use OCA\Mcp\L10n\Translator;

/** The sentence only a meeting step answers with; the ones every routine step shares are in WorkflowMessages. */
final class MeetingMessages {
	/** Static messages only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @return string the note of a room that exists but whose link this instance could not build
	 */
	public static function roomLinkMissing(): string {
		return Translator::t('The room was created, but its link could not be built. Open it in Talk and do not create it again.');
	}
}
