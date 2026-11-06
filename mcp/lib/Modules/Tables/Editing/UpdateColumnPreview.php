<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Editing;

use OCA\Mcp\Modules\Tables\Writing\SchemaFlow;
use OCA\Mcp\Modules\Tables\Integration\UpdateColumnAction;

/** UpdateColumn effect-free plan. */
final class UpdateColumnPreview {
	/** @param SchemaFlow $flow Common protocol. @param UpdateColumnAction $action Tool-specific authorized intent. */
	public function __construct(private SchemaFlow $flow, private UpdateColumnAction $action) {}
	/**
	 * @param array<string,mixed> $args Tool arguments.
	 * @param string $uid Caller.
	 * @return array<string,mixed> MCP plan.
	 */
	public function preview(array $args, string $uid): array { return $this->flow->preview($this->action, $args, $uid); }
}
