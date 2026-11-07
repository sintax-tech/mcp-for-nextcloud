<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Creation;


use OCA\Mcp\Modules\Deck\Creation\CreateCardHandler;
/** Prepares the same CreateCard confirmation without a write. */
final class CreateCardPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(CreateCardHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * @param array{stackId: int, title: string, description?: string, duedate?: string|null, assignees?: list<string>} $arguments
	 * @param string $userId UID of the authenticated caller, also the owner of the card to be created.
	 * @return array<string, mixed>
	 */
	private function prepare(array $arguments, string $userId): array {
		$place = $this->context->place($userId, (int)$arguments['stackId']);
		$assignees = array_key_exists('assignees', $arguments)
			? $this->context->gateway()->validateAssignees($userId, (int)$arguments['stackId'], \OCA\Mcp\Modules\Deck\Editing\CardInput::assignees($arguments['assignees']))
			: null;

		return [
			'action' => CreateCardHandler::TOOL,
			'card' => [
				'title' => \OCA\Mcp\Modules\Deck\Editing\CardInput::requireTitle((string)$arguments['title']),
				'description' => \OCA\Mcp\Modules\Deck\Editing\CardInput::requireDescription((string)($arguments['description'] ?? '')),
				'dueDate' => \OCA\Mcp\Modules\Deck\Editing\CardInput::duedate($arguments['duedate'] ?? null, $this->context->formatter($userId)->timezone()),
				'owner' => $userId,
			] + ($assignees === null ? [] : ['assignees' => $assignees]),
			'destination' => $place,
			'shared' => $place['shared'] ? [$place] : [],
			'recoverable' => true,
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planCreate(),
		];
	}
}
