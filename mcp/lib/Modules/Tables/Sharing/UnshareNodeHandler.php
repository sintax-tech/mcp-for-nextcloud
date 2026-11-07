<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Sharing;

use OCA\Mcp\Modules\Tables\Writing\SchemaFlow;
use OCA\Mcp\Modules\Tables\Integration\UnshareNodeAction;

/** UnshareNode confirmed effect. */
final class UnshareNodeHandler {
	/**
	 * @param SchemaFlow $flow Common protocol.
	 * @param UnshareNodeAction $action Tool-specific authorized intent.
	 */
	public function __construct(private SchemaFlow $flow, private UnshareNodeAction $action) {}
	/**
	 * @param array<string,mixed> $args Tool arguments.
	 * @param string $uid Caller.
	 * @return array<string,mixed> MCP result.
	 */
	public function handle(array $args, string $uid): array { return $this->flow->handle($this->action, $args, $uid); }
}
