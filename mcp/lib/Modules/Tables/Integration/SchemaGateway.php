<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** Thin native boundary delegating preparation, a single attempt and fresh observation. */
class SchemaGateway {
	/** @param SchemaAction $action Tool-specific action. @param array $args Request. @param string $uid Caller. @param string $profile Proven family. @return array Preparation. */
	public function prepare(SchemaAction $action, array $args, string $uid, string $profile): array { return $action->prepare($args, $uid, $profile); }
	/** @param SchemaAction $action Tool-specific effect. @param array $prepared Authorized intent. @param string $uid Caller. @return int|null Native ID. */
	public function apply(SchemaAction $action, array $prepared, string $uid): ?int { return $action->apply($prepared, $uid); }
	/** @param SchemaAction $action Tool-specific observation. @param array $prepared Authorized intent. @param int|null $returned Native ID. @param string $uid Caller. @return array Readback. */
	public function observe(SchemaAction $action, array $prepared, ?int $returned, string $uid): array { return $action->observe($prepared, $returned, $uid); }
}
