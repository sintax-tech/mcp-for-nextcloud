<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Structure\CreateStackHandler;

/** Prepares the same CreateStack confirmation without a write. */
final class CreateStackPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(CreateStackHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * @param array{boardId: int, title: string, order?: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 */
	private function prepare(array $arguments, string $userId): array {
		$title = \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::title((string)$arguments['title'], 'title');
		$board = $this->context->board($this->context->gateway()->boardOwnership($userId, (int)$arguments['boardId']), $userId);

		return [
			'action' => CreateStackHandler::TOOL,
			'stack' => ['title' => $title, 'order' => array_key_exists('order', $arguments) ? (int)$arguments['order'] : null],
			'board' => $board,
			'shared' => $board['shared'] ? [$board] : [],
			'recoverable' => true,
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planCreateStack(),
		];
	}
}
