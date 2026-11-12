<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

use Closure;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;

/**
 * The signed plans of deck_update_label and deck_delete_label, built the same way for the preview and the write.
 *
 * The plan_state binds the caller, the request and the label as shown (with its card count for a delete), so a label
 * renamed, recoloured, moved or newly assigned after the preview needs a new approval.
 */
final class LabelPlans {
	/**
	 * @param array<string, mixed> $arguments Validated arguments: title and color, at least one.
	 * @param array<string, mixed> $state Fresh label state.
	 * @return array{title: string, color: string} The label after the update.
	 * @throws ArgumentValidationException When neither field is sent or one is invalid.
	 * @throws DeckRefusalException When another label of the board has the new title.
	 */
	public static function updated(array $arguments, array $state): array {
		$title = array_key_exists('title', $arguments) && $arguments['title'] !== null ? LabelInput::title((string)$arguments['title']) : null;
		$color = LabelInput::color($arguments['color'] ?? null);
		if ($title === null && $color === null) {
			throw new ArgumentValidationException('Invalid argument: title', 'title', DeckLabelMessages::errorNothingToChange());
		}
		$title ??= $state['label']['title'];
		if (LabelInput::matches($state['labels'], $title, $state['label']['id']) !== []) {
			throw new DeckRefusalException(DeckLabelMessages::titleTaken($title));
		}
		return ['title' => $title, 'color' => $color ?? $state['label']['color']];
	}

	/**
	 * @param string $tool deck_update_label or deck_delete_label.
	 * @param array<string, mixed> $state Fresh label state.
	 * @param array{title: string, color: string}|null $after The label after an update; null for a delete.
	 * @param string $userId Caller the plan_state belongs to.
	 * @param Closure(): PlanState $stateFactory Lazy instance-secret fingerprint service.
	 * @return array<string, mixed> Read-only plan with its plan_state.
	 */
	public static function plan(string $tool, array $state, ?array $after, string $userId, Closure $stateFactory): array {
		$board = ['boardId' => $state['boardId'], 'board' => $state['board']['name'], 'owner' => $state['board']['owner'],
			'ownerDisplayName' => $state['board']['ownerDisplayName'], 'shared' => $state['board']['owner'] !== $userId];
		$label = $state['label'];
		$delete = $after === null;
		return ['action' => $tool,
			'label' => ['id' => $label['id'], 'title' => $label['title'], 'color' => $label['color']],
			'board' => $board, 'cards' => $state['cards']]
			+ ($delete ? [] : ['after' => $after, 'changed' => $after !== ['title' => $label['title'], 'color' => $label['color']]])
			+ ['shared' => $board['shared'] ? [$board] : [], 'recoverable' => !$delete,
				'message' => $delete ? DeckLabelMessages::planDelete() : DeckLabelMessages::planUpdate()]
			+ ($delete ? ['consequence' => DeckLabelMessages::deleteConsequence()] : [])
			+ [PlanState::ARGUMENT => ($stateFactory)()->of($tool, $userId,
				['requested' => $after, 'state' => LabelSnapshot::of($state, $delete)])];
	}

	/** Static helper. */
	private function __construct() {
	}
}
