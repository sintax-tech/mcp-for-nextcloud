<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Presentation;

use OCA\Mcp\L10n\Translator;

/** Accessible text for a stored message. Predefined ids are labeled here; their icons are never read. */
final class StatusMessages {
	/** Static labels only. */
	private function __construct() {
	}

	/**
	 * @param string|null $customMessage stored custom text
	 * @param string|null $messageId stored predefined or unknown id
	 * @return string|null accessible text, or null when there is nothing accessible to say
	 */
	public static function accessible(?string $customMessage, ?string $messageId): ?string {
		if (is_string($customMessage) && trim($customMessage) !== '') {
			return $customMessage;
		}
		return match ($messageId) {
			'meeting' => Translator::t('In a meeting'),
			'commuting' => Translator::t('Commuting'),
			'sick-leave' => Translator::t('Out sick'),
			'vacationing' => Translator::t('Vacationing'),
			'out-of-office' => Translator::t('Out of office'),
			'remote-work' => Translator::t('Working remotely'),
			'call' => Translator::t('In a call'),
			'be-right-back' => Translator::t('Be right back'),
			default => null,
		};
	}
}
