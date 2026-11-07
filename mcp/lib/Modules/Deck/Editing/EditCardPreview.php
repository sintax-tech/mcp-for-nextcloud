<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Editing;

use InvalidArgumentException;
use OCA\Mcp\Modules\Deck\Editing\EditCardHandler;

/** Prepares the same EditCard confirmation without a write. */
final class EditCardPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(EditCardHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * @param array{cardId: int, title?: string, description?: string, duedate?: string|null, assign?: list<string>, unassign?: list<string>, lastModified?: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 */
	private function prepare(array $arguments, string $userId): array {
		$given = array_intersect_key($arguments, array_flip(['title', 'description', 'duedate']));
		$changes = \OCA\Mcp\Modules\Deck\Editing\AssigneeChanges::fromArguments($arguments);
		$editsAssignees = $changes['assign'] !== [] || $changes['unassign'] !== [];
		if ($given === [] && !$editsAssignees) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorNoFieldToEdit());
		}
		$projection = new \OCA\Mcp\Modules\Deck\Integration\DeckPlanProjection($this->context->gateway());
		$card = $projection->readCard($userId, (int)$arguments['cardId']);
		// Both dates are instants in the caller's zone, the day the write turns into local midnight
		// included, so the same date is never reported as a change only because it is spelled otherwise.
		$zone = $this->context->zones()?->forUser($userId) ?? new \DateTimeZone(date_default_timezone_get());
		$before = $projection->fields($zone);
		$after = [
			'title' => array_key_exists('title', $given) ? \OCA\Mcp\Modules\Deck\Editing\CardInput::requireTitle((string)$given['title']) : $before['title'],
			'description' => array_key_exists('description', $given)
				? \OCA\Mcp\Modules\Deck\Editing\CardInput::requireDescription((string)$given['description'])
				: $before['description'],
			// Absent keeps the date as it is; an explicit null clears it, exactly as the write reads it.
			'duedate' => array_key_exists('duedate', $given)
				? \OCA\Mcp\Modules\Deck\DueDates\CardDueDateView::planValue($given['duedate'], $zone)
				: $before['duedate'],
		];
		$assignees = $editsAssignees ? $this->planAssignees($userId, $card, $projection, $changes) : null;
		$board = $this->context->gateway()->cardOwnership($userId, (int)$arguments['cardId']);

		$changed = array_values(array_filter(
			['title', 'description', 'duedate'],
			static fn (string $field): bool => $before[$field] !== $after[$field],
		));
		if ($assignees !== null && ($assignees['added'] !== [] || $assignees['removed'] !== [])) {
			$changed[] = 'assignees';
		}

		return [
			'action' => EditCardHandler::TOOL,
			'card' => ['id' => (int)$card['id'], 'before' => $before, 'after' => $after],
			'board' => $this->context->board($board, $userId),
			'changed' => $changed,
			'lastModified' => ['current' => (int)$card['lastModified'], 'sent' => $arguments['lastModified'] ?? null],
			'shared' => $board['owner'] === $userId ? [] : [$this->context->board($board, $userId)],
			'recoverable' => true,
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planEdit(),
		] + ($assignees === null ? [] : ['assignees' => $assignees]);
	}

	/**
	 * Who is responsible for the card now, who joins, who leaves and what is ignored.
	 *
	 * Accounts to add go through the same board check the write runs, so a refusal comes with the plan
	 * and nothing is written to find it out.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param array{id:int,stackId:int,lastModified:int} $card minimal card identity
	 * @param \OCA\Mcp\Modules\Deck\Integration\DeckPlanProjection $projection native assignment boundary
	 * @param array{assign: list<string>, unassign: list<string>} $changes Validated lists of the call.
	 * @return array{before: list<array{uid: string, displayName: string}>, after: list<array{uid: string, displayName: string}>, added: list<array{uid: string, displayName: string}>, removed: list<array{uid: string, displayName: string}>, notAssigned: list<string>, alreadyAssigned: list<string>}
	 */
	private function planAssignees(string $userId, array $card, \OCA\Mcp\Modules\Deck\Integration\DeckPlanProjection $projection, array $changes): array {
		$before = $projection->assignees($this->context->formatter($userId));
		$resolved = \OCA\Mcp\Modules\Deck\Editing\AssigneeChanges::resolve($changes['assign'], $changes['unassign'], array_column($before, 'uid'));
		$added = $resolved['add'] === [] ? [] : $this->context->gateway()->validateAssignees($userId, (int)$card['stackId'], $resolved['add']);
		$removed = array_values(array_filter($before, static fn (array $user): bool => in_array($user['uid'], $resolved['remove'], true)));
		$kept = array_values(array_filter($before, static fn (array $user): bool => !in_array($user['uid'], $resolved['remove'], true)));

		return [
			'before' => $before,
			'after' => [...$kept, ...$added],
			'added' => $added,
			'removed' => $removed,
			'notAssigned' => $resolved['notAssigned'],
			'alreadyAssigned' => $resolved['alreadyAssigned'],
		];
	}
}
