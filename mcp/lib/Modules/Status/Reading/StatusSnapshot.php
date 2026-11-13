<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Reading;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Status\Integration\StatusRow;
use OCA\Mcp\Modules\Status\Presentation\StatusMessages;

/** Projects a stored row. clear_at in the past is reported and never applied. */
final class StatusSnapshot {
	/**
	 * @param StatusRow|null $row stored row, or null when the actor has none
	 * @param int $now current unix time used only for the expired flag
	 * @return array<string, mixed> configured snapshot
	 */
	public function project(?StatusRow $row, int $now): array {
		if ($row === null) {
			return [
				'configured' => false,
				'snapshot' => 'configured',
				'live_presence' => false,
				'message' => Translator::t('No status is configured.'),
			];
		}
		return [
			'configured' => true,
			'snapshot' => 'configured',
			'live_presence' => false,
			'status' => $row->status,
			'status_timestamp' => $row->statusTimestamp,
			'user_defined' => $row->userDefined,
			'message_id' => $row->messageId,
			'message' => StatusMessages::accessible($row->customMessage, $row->messageId),
			'clear_at' => $row->clearAt,
			'expired' => $row->clearAt !== null && $row->clearAt < $now,
			'status_message_timestamp' => $row->statusMessageTimestamp,
		];
	}
}
