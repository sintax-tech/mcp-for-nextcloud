<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;

/** Prepares the deck_create_label confirmation without a write. */
final class CreateLabelPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(CreateLabelHandler::TOOL, function () use ($arguments, $userId): array {
			['state' => $state, 'label' => $label] = CreateLabelHandler::prepare($this->context->gateway(), $arguments, $userId);
			$board = ['boardId' => $state['boardId']] + $this->context->board($state['board'], $userId);
			return [
				'action' => CreateLabelHandler::TOOL,
				'label' => $label,
				'board' => $board,
				'shared' => $board['shared'] ? [$board] : [],
				'recoverable' => true,
				'message' => DeckLabelMessages::planCreate(),
			];
		}, $userId);
	}
}
