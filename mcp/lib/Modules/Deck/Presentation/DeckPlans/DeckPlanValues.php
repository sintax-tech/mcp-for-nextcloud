<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation\DeckPlans;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Formats the same Deck plan values for the card and structure text. */
class DeckPlanValues {
	/** Existing maximum length of inert confirmation values. */
	protected const MAX_TEXT_LENGTH = 200;
	/**
	 * @param array<string, mixed> $plan Plan that may carry a `consequence`.
	 * @return list<string> The consequence as a bullet, or nothing.
	 */
	protected function consequenceLine(array $plan): array {
		return isset($plan['consequence']) && is_string($plan['consequence']) && $plan['consequence'] !== ''
			? ['- ' . PlanText::inline($plan['consequence'], 300)]
			: [];
	}

	/**
	 * @param array<string, mixed> $board Board of the plan, with its owner when it is somebody else's.
	 * @return list<string> The shared-board notice, or nothing.
	 */
	protected function sharedLine(array $board): array {
		if (empty($board['shared'])) {
			return [];
		}
		$owner = !empty($board['ownerDisplayName']) && is_string($board['ownerDisplayName'])
			? $board['ownerDisplayName']
			: (string)($board['owner'] ?? '');
		$owner = PlanText::inline($owner);

		return $owner === '' ? [] : [Translator::t('- Shared board of %s.', [$owner])];
	}

	/**
	 * @param array<string, mixed> $card
	 * @return list<string>
	 */
	protected function extractAssignees(array $card): array {
		$users = $card['assignedUsers'] ?? $card['assignees'] ?? null;
		if (!is_array($users)) {
			return [];
		}
		$names = [];
		foreach ($users as $user) {
			if (is_string($user) && $user !== '') {
				$names[] = $user;
			} elseif (is_array($user)) {
				$name = $user['displayName'] ?? $user['name'] ?? $user['uid'] ?? null;
				if (is_string($name) && $name !== '') {
					$names[] = $name;
				}
			}
		}
		return $names;
	}

	/**
	 * Delegate deadline text and timezone disambiguation to its focused display boundary.
	 *
	 * @param string $raw Deadline stored in the plan.
	 * @param \DateTimeZone $zone Account timezone.
	 * @return string Safe deadline text.
	 */
	protected function formatDate(string $raw, \DateTimeZone $zone): string {
		return \OCA\Mcp\Modules\Deck\DueDates\CardDueDateView::formatPlan($raw, $zone);
	}

	/**
	 * @param string $text text to render inertly
	 * @return string unchanged result of this operation
	 */
	protected function truncate(string $text): string {
		return PlanText::inline($text, self::MAX_TEXT_LENGTH);
	}

	/** @param string $title card title typed by somebody else @return string inert text, or the placeholder when nothing is left */
	protected function name(string $title): string {
		$inline = PlanText::inline($title);
		return $inline !== '' ? $inline : Translator::t('(no title)');
	}

	/**
	 * @param string $board board name
	 * @param mixed $list list name, when the plan has one
	 * @return string "*board* › *list*" with both names inert
	 */
	protected function place(string $board, mixed $list): string {
		$place = PlanText::em($board);
		$listName = is_string($list) ? PlanText::em($list) : '';
		return $listName !== '' ? $place . ' › ' . $listName : $place;
	}

	/**
	 * Lines of the assignee section of an edit plan: who joins, who leaves, the final list and what is ignored.
	 *
	 * @param mixed $assignees `assignees` section of the plan, or null when the call did not touch them.
	 * @return list<string> Markdown lines, empty when there is nothing to say.
	 */
	protected function assigneeLines(mixed $assignees): array {
		if (!is_array($assignees)) {
			return [];
		}
		$lines = [];
		$added = $this->extractAssignees(['assignees' => $assignees['added'] ?? []]);
		$removed = $this->extractAssignees(['assignees' => $assignees['removed'] ?? []]);
		if ($added !== []) {
			$lines[] = Translator::t('- Assigned: %s', [PlanText::inline(implode(', ', $added), 300)]);
		}
		if ($removed !== []) {
			$lines[] = Translator::t('- Unassigned: %s', [PlanText::inline(implode(', ', $removed), 300)]);
		}
		if (array_key_exists('after', $assignees)) {
			$after = $this->extractAssignees(['assignees' => $assignees['after']]);
			$lines[] = Translator::t('- Assignees after the change: %s', [
				$after === [] ? Translator::t('(nobody)') : PlanText::inline(implode(', ', $after), 300),
			]);
		}
		$notAssigned = $this->uids($assignees['notAssigned'] ?? []);
		if ($notAssigned !== []) {
			$lines[] = Translator::t('- Ignored, not assigned to the card: %s', [PlanText::inline(implode(', ', $notAssigned), 300)]);
		}
		$already = $this->uids($assignees['alreadyAssigned'] ?? []);
		if ($already !== []) {
			$lines[] = Translator::t('- Ignored, already assigned to the card: %s', [PlanText::inline(implode(', ', $already), 300)]);
		}

		return $lines;
	}

	/**
	 * @param mixed $value Account IDs of the plan.
	 * @return list<string> The non-empty strings of the list.
	 */
	protected function uids(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, static fn ($uid): bool => is_string($uid) && $uid !== '')) : [];
	}
}
