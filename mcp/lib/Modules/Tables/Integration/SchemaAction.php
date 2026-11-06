<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** A tool-specific native intent, not a string dispatched by the common write protocol. */
interface SchemaAction {
	/** @return string Published suffix. */
	public function name(): string;
	/** @return string Required grant operation. */
	public function operation(): string;
	/** @return list<string> Specific confirmation notes. */
	public function notes(): array;
	/** @param array $args Validated request. @param string $uid Caller. @param string $profile Proven API. @return array Native preparation. */
	public function prepare(array $args, string $uid, string $profile): array;
	/** @param array $prepared Revalidated preparation. @param string $uid Caller. @return int|null Returned entity ID. */
	public function apply(array $prepared, string $uid): ?int;
	/** @param array $prepared Revalidated preparation. @param int|null $returned Native ID. @param string $uid Caller. @return array Authorized observation. */
	public function observe(array $prepared, ?int $returned, string $uid): array;
}
