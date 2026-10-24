<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Tools\ToolFailure;

/**
 * The Mail app is disabled for the user, older than MCP supports or its classes are not loaded. A tool failure, so
 * the registry answers with the translated message and never with a server error.
 */
class MailUnavailableException extends ToolFailure {
	/**
	 * Builds the failure with the translated message of {@see MailMessages::unavailable()}.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(MailMessages::unavailable());
	}
}
