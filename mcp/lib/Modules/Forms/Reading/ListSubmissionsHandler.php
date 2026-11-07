<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reading;

use OCA\Mcp\Modules\Forms\Integration\ResultsReadFlow;

/** Handles one read-only submissions tool through the shared authorized pipeline. */
final class ListSubmissionsHandler {
	/** Receives the read pipeline without eagerly resolving Forms native services.
	 * @param ResultsReadFlow $flow Authorized read-only flow.
	 */
	public function __construct(private ResultsReadFlow $flow) {
	}
	/** Delegates a validated request for the authenticated actor.
	 * @param array $arguments Validated tool arguments.
	 * @param string $uid Authenticated actor.
	 * @return array MCP tool result.
	 */
	public function handle(array $arguments, string $uid): array { return $this->flow->run($arguments, $uid, true); }
}
