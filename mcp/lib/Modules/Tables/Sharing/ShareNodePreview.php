<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Sharing;

use OCA\Mcp\Modules\Tables\Writing\SchemaFlow;

/** ShareNode effect-free plan. */
final class ShareNodePreview {
	/** @param SchemaFlow $flow Shared authorization and observation protocol. */
	public function __construct(private SchemaFlow $flow) {}
	/**
	 * @param array<string,mixed> $args Tool arguments.
	 * @param string $uid Caller.
	 * @return array<string,mixed> MCP plan.
	 */
	public function preview(array $args, string $uid): array { return $this->flow->preview('share_node', $args, $uid); }
}
