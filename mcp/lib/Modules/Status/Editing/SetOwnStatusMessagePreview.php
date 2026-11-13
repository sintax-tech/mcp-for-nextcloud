<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Editing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Status\Integration\StatusGateway;
use OCA\Mcp\Modules\Status\Presentation\StatusMessageRequest;
use OCA\Mcp\Modules\Status\Presentation\StatusMessages;
use OCP\AppFramework\Utility\ITimeFactory;

/** Plans a message change. This class has no write. */
final class SetOwnStatusMessagePreview {
	/**
	 * @param StatusGateway $native select of the current row
	 * @param ITimeFactory $time clock for the expiration check
	 */
	public function __construct(private StatusGateway $native, private ITimeFactory $time) {
	}

	/**
	 * @param string $actor authenticated owner
	 * @param array<string, mixed> $arguments requested change; another user's id is ignored
	 * @return array<string, mixed> plan; nothing is written
	 * @throws \InvalidArgumentException when the change is not one native message change
	 */
	public function plan(string $actor, array $arguments): array {
		$request = StatusMessageRequest::parse($arguments, $this->time->getTime());
		$row = $this->native->find($actor);
		return [
			'requiresConfirmation' => true,
			'action' => 'status_set_message',
			'mode' => $request->mode,
			'before' => StatusMessages::accessible($row?->customMessage, $row?->messageId),
			'after' => $request->after(),
			'clear_at' => $request->clearAt,
			'removes_backup' => $request->mode !== StatusMessageRequest::MODE_CLEAR,
			'message' => match ($request->mode) {
				StatusMessageRequest::MODE_CLEAR => Translator::t('This removes only your own status message and its icon. The temporary backup of your status stays, as when the status menu clears the message.'),
				StatusMessageRequest::MODE_PREDEFINED => Translator::t('This changes only your own status message to a predefined one. A temporary backup of your status is removed, as in the status menu. Nextcloud shows that message\'s standard icon, and a stored custom icon is cleared.'),
				default => Translator::t('This changes only your own status message. A temporary backup of your status is removed, as in the status menu. No new emoji or icon is stored; a stored custom icon is cleared.'),
			},
		];
	}
}
