<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Dismissal;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsDismissal;

/** Confirmed dismiss of one own notification. The registry only reaches this when confirm is true. */
final class DismissNotificationEffect {
	/** @param DismissNotificationInput $input strict id @param NotificationsDismissal $native native dismiss path and read-back */
	public function __construct(private DismissNotificationInput $input, private NotificationsDismissal $native) {
	}

	/**
	 * @param string $actor authenticated account
	 * @param array<string,mixed> $arguments submitted arguments
	 * @return array<string,mixed> receipt, or a tool error when the id is not usable
	 */
	public function apply(string $actor, array $arguments): array {
		try {
			$id = $this->input->id($arguments);
		} catch (InvalidArgumentException) {
			return ToolResult::error(Translator::t('Provide a notification id of 1 or greater.'));
		}
		return ToolResult::json($this->native->apply($actor, $id));
	}
}
