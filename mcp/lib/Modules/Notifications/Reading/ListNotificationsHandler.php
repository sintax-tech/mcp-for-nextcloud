<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Reading;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsGateway;
use OCA\Mcp\Modules\Notifications\Presentation\NotificationsSchema;
use Throwable;

/** Validates the explicit bound and wraps the safe metadata projection. */
final class ListNotificationsHandler {
	/** @param NotificationsGateway $native lazy, owner-filtered native read @param NotificationsInput $input strict limit parser */
	public function __construct(private NotificationsGateway $native, private NotificationsInput $input) {}
	/**
	 * @param string $actor authenticated account
	 * @param array<string,mixed> $arguments limit only; no target user or historical cursor
	 * @return array<string,mixed> limited own inbox metadata
	 */
	public function handle(string $actor, array $arguments): array {
		try {
			$limit = $this->input->limit($arguments);
			$page = $this->native->latest($actor, $limit);
			return ToolResult::json(['items' => $page['items'], 'partial' => true, 'coverage' => NotificationsSchema::LIMITED,
				'truncated' => $page['truncated']]);
		} catch (PolicyUnavailable $error) { throw $error;
		} catch (InvalidArgumentException) {
			return ToolResult::error(Translator::t('Provide a notification limit between 1 and 200.'));
		} catch (Throwable) {
			return ToolResult::error(Translator::t('The notification inbox is unavailable.'));
		}
	}
}
