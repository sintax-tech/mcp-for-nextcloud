<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

/**
 * The Deck tool dispatch a meeting port uses for the two reads. The name selects the existing handler.
 */
interface DeckReadDispatch {
	/**
	 * @param string $name the published tool name
	 * @param array<string, mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string, mixed> the MCP result of that tool
	 */
	public function execute(string $name, array $arguments, string $userId): array;
}
