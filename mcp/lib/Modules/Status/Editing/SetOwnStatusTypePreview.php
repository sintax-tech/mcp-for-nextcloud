<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Editing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Status\Integration\StatusGateway;
use OCA\Mcp\Modules\Status\Presentation\StatusTypes;

/** Plans a type change. This class has no write. */
final class SetOwnStatusTypePreview {
	/** @param StatusGateway $native select of the current row */
	public function __construct(private StatusGateway $native) {
	}

	/**
	 * @param string $actor authenticated owner
	 * @param string $status requested native type
	 * @return array<string, mixed> plan; nothing is written
	 * @throws \InvalidArgumentException when the type is not native
	 */
	public function plan(string $actor, string $status): array {
		StatusTypes::require($status);
		$row = $this->native->find($actor);
		return [
			'requiresConfirmation' => true,
			'action' => 'status_set_type',
			'before' => $row?->status,
			'after' => $status,
			'message_kept' => true,
			'removes_backup' => true,
			'message' => Translator::t('This changes only your own status type. The stored message stays. A temporary backup of your status is removed, as in the status menu.'),
		];
	}
}
