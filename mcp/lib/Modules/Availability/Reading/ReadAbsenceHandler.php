<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Reading;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Availability\Integration\AbsenceGateway;
use Throwable;

/** Reads only the authenticated actor's absence; the feature switch is honoured before any absence read. */
final class ReadAbsenceHandler {
	/**
	 * @param AbsenceGateway $native lazy native read
	 * @param AbsenceView $view answer projection
	 */
	public function __construct(private AbsenceGateway $native, private AbsenceView $view) {}
	/**
	 * @param string $userId authenticated actor, the only account ever read
	 * @return array<string,mixed> MCP result
	 */
	public function handle(string $userId): array {
		try {
			if (!$this->native->enabled()) return ToolResult::error(Translator::t('Absence settings are turned off on this Nextcloud server.'));
			return ToolResult::json($this->view->project($this->native->read($userId)));
		} catch (Throwable) {
			return ToolResult::error(Translator::t('Your absence settings are unavailable.'));
		}
	}
}
