<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\Modules\Forms\Restructuring\StructureFlow;

/** Confirmed effect of forms_change_question_type: one native call and a readback receipt. */
final class ChangeQuestionTypeHandler {
	/**
	 * @param StructureFlow $flow shared structure flow
	 * @param TypeChanges $operation the forms_change_question_type operation
	 */
	public function __construct(private StructureFlow $flow, private TypeChanges $operation) {
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
