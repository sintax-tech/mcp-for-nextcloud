<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Deletion;

use OCA\Mcp\Modules\Forms\Restructuring\StructureFlow;

/** Confirmed effect of forms_delete_question: one native call and a readback receipt. */
final class DeleteQuestionHandler {
	/**
	 * @param StructureFlow $flow shared structure flow
	 * @param QuestionDeletion $operation the forms_delete_question operation
	 */
	public function __construct(private StructureFlow $flow, private QuestionDeletion $operation) {
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
