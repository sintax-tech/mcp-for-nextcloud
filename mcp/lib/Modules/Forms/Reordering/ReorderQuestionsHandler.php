<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reordering;

use OCA\Mcp\Modules\Forms\Restructuring\StructureFlow;

/** Confirmed effect of forms_reorder_questions: one native call and a readback receipt. */
final class ReorderQuestionsHandler {
	/**
	 * @param StructureFlow $flow shared structure flow
	 * @param QuestionReorder $operation the forms_reorder_questions operation
	 */
	public function __construct(private StructureFlow $flow, private QuestionReorder $operation) {
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
