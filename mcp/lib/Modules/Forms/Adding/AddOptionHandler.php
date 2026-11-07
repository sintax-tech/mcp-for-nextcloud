<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Adding;

use OCA\Mcp\Modules\Forms\Restructuring\StructureFlow;

/** Confirmed effect of forms_add_option: one native call and a readback receipt. */
final class AddOptionHandler {
	/**
	 * @param StructureFlow $flow shared structure flow
	 * @param OptionAddition $operation the forms_add_option operation
	 */
	public function __construct(private StructureFlow $flow, private OptionAddition $operation) {
	}

	/**
	 * @param array<string,mixed> $arguments submitted arguments
	 * @param string $uid actor
	 * @return array<string,mixed> MCP result with the receipt
	 */
	public function handle(array $arguments, string $uid): array {
		return $this->flow->handle($this->operation, $arguments, $uid);
	}
}
