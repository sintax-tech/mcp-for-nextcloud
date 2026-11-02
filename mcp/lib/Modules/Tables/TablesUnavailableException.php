<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables;

use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The Tables app is not enabled for the user, older than MCP supports, or its classes are not loaded.
 */
class TablesUnavailableException extends ToolFailure {
	/**
	 * Builds the failure with the translated message of {@see TablesMessages::unavailable()}.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(TablesMessages::unavailable());
	}
}
