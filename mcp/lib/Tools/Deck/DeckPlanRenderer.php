<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\PlanText;

/**
 * Renders human-readable confirmation Markdown plans for Deck write operations.
 */
final class DeckPlanRenderer {
	private const MAX_TEXT_LENGTH = 200;

	public function __construct(private ?\DateTimeZone $zone = null) {
	}

	/**
	 * @param string $tool Tool name
	 * @param array<string, mixed> $plan Write plan
	 * @return string|null Markdown body, or null to fall back to generic
	 */
	public function render(string $tool, array $plan): ?string {
		try {
			$zone = $this->resolveZone($plan);
			return match ($tool) {
				'deck_create_card' => $this->renderCreate($plan, $zone),
				'deck_edit_card' => $this->renderEdit($plan, $zone),
				'deck_move_card' => $this->renderMove($plan),
				'deck_delete_card' => $this->renderDelete($plan),
				'deck_create_board' => $this->renderCreateBoard($plan, $zone),
				'deck_create_stack' => $this->renderCreateStack($plan),
				'deck_delete_stack' => $this->renderDeleteStack($plan),
				'deck_delete_board' => $this->renderDeleteBoard($plan),
				default => null,
			};
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Resolves the timezone from the plan if present, falling back to constructor or system default.
	 *
	 * @param array<string, mixed> $plan
	 */
	private function resolveZone(array $plan): \DateTimeZone {
		if (isset($plan['timezone']) && is_string($plan['timezone']) && $plan['timezone'] !== '') {
			try {
				return new \DateTimeZone($plan['timezone']);
			} catch (\Throwable) {
				// fall through
			}
		}
		if ($this->zone !== null) {
			return $this->zone;
		}
		return new \DateTimeZone(date_default_timezone_get());
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderCreate(array $plan, \DateTimeZone $zone): ?string {
		$card = $plan['card'] ?? null;
		$dest = $plan['destination'] ?? null;
		if (!is_array($card) || !isset($card['title']) || !is_string($card['title']) || $card['title'] === '') {
			return null;
		}
		if (!is_array($dest) || !isset($dest['board']) || !is_string($dest['board']) || $dest['board'] === '') {
			return null;
		}

		$title = $this->name($card['title']);
		$place = $this->place($dest['board'], $dest['list'] ?? null);

		$lines = [];
		$lines[] = Translator::t('Create card in %s: **%s**', [$place, $title]);

		if (isset($card['description']) && is_string($card['description']) && $card['description'] !== '') {
			$lines[] = Translator::t('- Description: %s', [$this->truncate($card['description'])]);
		}

		$dueDate = $card['dueDate'] ?? $card['duedate'] ?? null;
		if (is_string($dueDate) && $dueDate !== '') {
			$lines[] = Translator::t('- Due date: %s', [$this->formatDate($dueDate, $zone)]);
		}

		$assignees = $this->extractAssignees($card);
		if ($assignees !== []) {
			$lines[] = count($assignees) === 1
				? Translator::t('- Assignee: %s', [PlanText::inline($assignees[0])])
				: Translator::t('- Assignees: %s', [PlanText::inline(implode(', ', $assignees), 300)]);
		}

		if (!empty($dest['shared'])) {
			$owner = !empty($dest['ownerDisplayName']) && is_string($dest['ownerDisplayName'])
				? $dest['ownerDisplayName']
				: (string)($dest['owner'] ?? '');
			$owner = PlanText::inline($owner);
			if ($owner !== '') {
				$lines[] = Translator::t('- Shared board of %s.', [$owner]);
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderEdit(array $plan, \DateTimeZone $zone): ?string {
		$card = $plan['card'] ?? null;
		if (!is_array($card) || !isset($card['before'], $card['after']) || !is_array($card['before']) || !is_array($card['after'])) {
			return null;
		}
		$changed = $plan['changed'] ?? null;
		if (!is_array($changed)) {
			return null;
		}

		$before = $card['before'];
		$after = $card['after'];
		$title = $before['title'] ?? $after['title'] ?? null;
		if (!is_string($title) || $title === '') {
			return null;
		}
		$title = $this->name($title);

		$board = $plan['board'] ?? null;
		$boardName = is_array($board) && isset($board['board']) && is_string($board['board']) ? PlanText::em($board['board']) : '';

		$lines = [];
		if ($boardName !== '') {
			$lines[] = Translator::t('Edit card in %s: **%s**', [$boardName, $title]);
		} else {
			$lines[] = Translator::t('Edit card: **%s**', [$title]);
		}

		foreach ($changed as $field) {
			if ($field === 'title' && isset($before['title'], $after['title'])) {
				$lines[] = Translator::t('- Title: %s → %s', [$this->name((string)$before['title']), $this->name((string)$after['title'])]);
			} elseif ($field === 'description' && (array_key_exists('description', $before) || array_key_exists('description', $after))) {
				$beforeDesc = !empty($before['description']) ? $this->truncate((string)$before['description']) : Translator::t('(empty)');
				$afterDesc = !empty($after['description']) ? $this->truncate((string)$after['description']) : Translator::t('(empty)');
				$lines[] = Translator::t('- Description: %s → %s', [$beforeDesc, $afterDesc]);
			} elseif ($field === 'duedate' && (array_key_exists('duedate', $before) || array_key_exists('duedate', $after))) {
				$beforeDue = !empty($before['duedate']) ? $this->formatDate((string)$before['duedate'], $zone) : Translator::t('(no due date)');
				$afterDue = !empty($after['duedate']) ? $this->formatDate((string)$after['duedate'], $zone) : Translator::t('(no due date)');
				$lines[] = Translator::t('- Due date: %s → %s', [$beforeDue, $afterDue]);
			}
		}

		array_push($lines, ...$this->assigneeLines($plan['assignees'] ?? null));

		if (is_array($board) && !empty($board['shared'])) {
			$owner = !empty($board['ownerDisplayName']) && is_string($board['ownerDisplayName'])
				? $board['ownerDisplayName']
				: (string)($board['owner'] ?? '');
			$owner = PlanText::inline($owner);
			if ($owner !== '') {
				$lines[] = Translator::t('- Shared board of %s.', [$owner]);
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * Lines of the assignee section of an edit plan: who joins, who leaves, the final list and what is ignored.
	 *
	 * @param mixed $assignees `assignees` section of the plan, or null when the call did not touch them.
	 * @return list<string> Markdown lines, empty when there is nothing to say.
	 */
	private function assigneeLines(mixed $assignees): array {
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
	private function uids(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, static fn ($uid): bool => is_string($uid) && $uid !== '')) : [];
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderMove(array $plan): ?string {
		$card = $plan['card'] ?? null;
		$orig = $plan['origin'] ?? null;
		$dest = $plan['destination'] ?? null;
		if (!is_array($card) || !isset($card['title']) || !is_string($card['title']) || $card['title'] === '') {
			return null;
		}
		if (!is_array($orig) || !isset($orig['board']) || !is_string($orig['board']) || $orig['board'] === '') {
			return null;
		}
		if (!is_array($dest) || !isset($dest['board']) || !is_string($dest['board']) || $dest['board'] === '') {
			return null;
		}

		$title = $this->name($card['title']);
		$lines = [];
		$lines[] = Translator::t('Move card: **%s**', [$title]);

		$origPlace = $this->place($orig['board'], $orig['list'] ?? null);
		$destPlace = $this->place($dest['board'], $dest['list'] ?? null);

		if (!empty($plan['sameList'])) {
			$lines[] = Translator::t('- Reorder in list: %s', [$origPlace]);
		} else {
			$lines[] = Translator::t('- From: %s', [$origPlace]);
			$lines[] = Translator::t('- To: %s', [$destPlace]);
		}

		if (isset($plan['order']) && is_int($plan['order'])) {
			$lines[] = Translator::t('- Position: %d', [$plan['order']]);
		}

		if (!empty($dest['shared'])) {
			$owner = !empty($dest['ownerDisplayName']) && is_string($dest['ownerDisplayName'])
				? $dest['ownerDisplayName']
				: (string)($dest['owner'] ?? '');
			$owner = PlanText::inline($owner);
			if ($owner !== '') {
				$lines[] = Translator::t('- Destination board shared by %s.', [$owner]);
			}
		} elseif (!empty($orig['shared'])) {
			$owner = !empty($orig['ownerDisplayName']) && is_string($orig['ownerDisplayName'])
				? $orig['ownerDisplayName']
				: (string)($orig['owner'] ?? '');
			$owner = PlanText::inline($owner);
			if ($owner !== '') {
				$lines[] = Translator::t('- Origin board shared by %s.', [$owner]);
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderDelete(array $plan): ?string {
		$card = $plan['card'] ?? null;
		if (!is_array($card) || !isset($card['title']) || !is_string($card['title']) || $card['title'] === '') {
			return null;
		}

		$title = $this->name($card['title']);
		$board = $plan['board'] ?? null;
		$boardName = is_array($board) && isset($board['board']) && is_string($board['board']) ? PlanText::em($board['board']) : '';

		$lines = [];
		if ($boardName !== '') {
			$lines[] = Translator::t('Delete card in %s: **%s**', [$boardName, $title]);
		} else {
			$lines[] = Translator::t('Delete card: **%s**', [$title]);
		}

		if (isset($plan['consequence']) && is_string($plan['consequence']) && $plan['consequence'] !== '') {
			$lines[] = '- ' . PlanText::inline($plan['consequence'], 300);
		}

		if (is_array($board) && !empty($board['shared'])) {
			$owner = !empty($board['ownerDisplayName']) && is_string($board['ownerDisplayName'])
				? $board['ownerDisplayName']
				: (string)($board['owner'] ?? '');
			$owner = PlanText::inline($owner);
			if ($owner !== '') {
				$lines[] = Translator::t('- Shared board of %s.', [$owner]);
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * The tree of a board that will be built: lists in order, each with its cards and what is set on them.
	 *
	 * @param array<string, mixed> $plan
	 */
	private function renderCreateBoard(array $plan, \DateTimeZone $zone): ?string {
		$board = $plan['board'] ?? null;
		$stacks = $plan['stacks'] ?? null;
		if (!is_array($board) || !isset($board['title']) || !is_string($board['title']) || $board['title'] === '' || !is_array($stacks)) {
			return null;
		}

		$cards = 0;
		foreach ($stacks as $stack) {
			$cards += is_array($stack['cards'] ?? null) ? count($stack['cards']) : 0;
		}
		$lines = [Translator::t('Create board **%s**: %s, %s.', [
			$this->name($board['title']),
			Translator::n('%n list', '%n lists', count($stacks)),
			Translator::n('%n card', '%n cards', $cards),
		])];

		// The colour is typed by the caller: it is printed only when it is exactly what Deck stores.
		if (!empty($board['colorGiven']) && is_string($board['color'] ?? null) && preg_match('/^[0-9a-f]{6}$/', $board['color']) === 1) {
			$lines[] = Translator::t('- Color: #%s', [$board['color']]);
		}

		foreach ($stacks as $stack) {
			if (!is_array($stack) || !is_string($stack['title'] ?? null)) {
				return null;
			}
			$lines[] = Translator::t('- List **%s**', [$this->name($stack['title'])]);
			foreach (is_array($stack['cards'] ?? null) ? $stack['cards'] : [] as $card) {
				if (!is_array($card) || !is_string($card['title'] ?? null)) {
					return null;
				}
				$lines[] = $this->boardCardLine($card, $zone);
			}
		}

		$lines[] = Translator::t('- The board is open only to you until you share it in Deck.');

		return implode("\n", $lines);
	}

	/**
	 * One card of the tree: its title and, in brackets, what is set on it.
	 *
	 * @param array<string, mixed> $card Card of a list of the plan.
	 */
	private function boardCardLine(array $card, \DateTimeZone $zone): string {
		$details = [];
		$due = $card['duedate'] ?? null;
		if (is_string($due) && $due !== '') {
			$details[] = Translator::t('due %s', [$this->formatDate($due, $zone)]);
		}
		$assignees = $this->extractAssignees($card);
		if ($assignees !== []) {
			$details[] = Translator::t('assigned to %s', [PlanText::inline(implode(', ', $assignees), 300)]);
		}
		if (is_string($card['description'] ?? null) && $card['description'] !== '') {
			$details[] = Translator::t('with description');
		}

		return $details === []
			? '  ' . Translator::t('- **%s**', [$this->name($card['title'])])
			: '  ' . Translator::t('- **%s** (%s)', [$this->name($card['title']), implode('; ', $details)]);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderCreateStack(array $plan): ?string {
		$stack = $plan['stack'] ?? null;
		$board = $plan['board'] ?? null;
		if (!is_array($stack) || !is_string($stack['title'] ?? null) || $stack['title'] === '') {
			return null;
		}
		if (!is_array($board) || !is_string($board['board'] ?? null) || $board['board'] === '') {
			return null;
		}

		$lines = [Translator::t('Create list in %s: **%s**', [PlanText::em($board['board']), $this->name($stack['title'])])];
		$lines[] = is_int($stack['order'] ?? null)
			? Translator::t('- Position: %d', [$stack['order']])
			: Translator::t('- Position: after the last list');
		array_push($lines, ...$this->sharedLine($board));

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderDeleteStack(array $plan): ?string {
		$stack = $plan['stack'] ?? null;
		$board = $plan['board'] ?? null;
		if (!is_array($stack) || !is_string($stack['title'] ?? null) || $stack['title'] === '') {
			return null;
		}
		if (!is_array($board) || !is_string($board['board'] ?? null) || $board['board'] === '') {
			return null;
		}

		$lines = [Translator::t('Delete list in %s: **%s**', [PlanText::em($board['board']), $this->name($stack['title'])])];
		$lines[] = Translator::t('- The list has no cards.');
		array_push($lines, ...$this->consequenceLine($plan));
		array_push($lines, ...$this->sharedLine($board));

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderDeleteBoard(array $plan): ?string {
		$board = $plan['board'] ?? null;
		if (!is_array($board) || !is_string($board['title'] ?? null) || $board['title'] === '') {
			return null;
		}

		$lines = [Translator::t('Delete board: **%s**', [$this->name($board['title'])])];
		$lines[] = Translator::t('- The board has no cards.');
		$stacks = array_values(array_filter(
			is_array($plan['stacks'] ?? null) ? $plan['stacks'] : [],
			static fn ($title): bool => is_string($title) && $title !== '',
		));
		$lines[] = $stacks === []
			? Translator::t('- It has no lists.')
			: Translator::t('- Lists that go with it: %s', [PlanText::inline(implode(', ', $stacks), 300)]);
		array_push($lines, ...$this->consequenceLine($plan));

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan Plan that may carry a `consequence`.
	 * @return list<string> The consequence as a bullet, or nothing.
	 */
	private function consequenceLine(array $plan): array {
		return isset($plan['consequence']) && is_string($plan['consequence']) && $plan['consequence'] !== ''
			? ['- ' . PlanText::inline($plan['consequence'], 300)]
			: [];
	}

	/**
	 * @param array<string, mixed> $board Board of the plan, with its owner when it is somebody else's.
	 * @return list<string> The shared-board notice, or nothing.
	 */
	private function sharedLine(array $board): array {
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
	private function extractAssignees(array $card): array {
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

	private function formatDate(string $raw, \DateTimeZone $zone): string {
		try {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
				return $raw;
			}
			$dt = (new \DateTimeImmutable($raw))->setTimezone($zone);
			if ($dt->format('H:i:s') === '00:00:00') {
				return $dt->format('Y-m-d');
			}
			return $dt->format('Y-m-d H:i');
		} catch (\Throwable) {
			return PlanText::inline($raw);
		}
	}

	private function truncate(string $text): string {
		return PlanText::inline($text, self::MAX_TEXT_LENGTH);
	}

	/** @param string $title card title typed by somebody else @return string inert text, or the placeholder when nothing is left */
	private function name(string $title): string {
		$inline = PlanText::inline($title);
		return $inline !== '' ? $inline : Translator::t('(no title)');
	}

	/**
	 * @param string $board board name
	 * @param mixed $list list name, when the plan has one
	 * @return string "*board* › *list*" with both names inert
	 */
	private function place(string $board, mixed $list): string {
		$place = PlanText::em($board);
		$listName = is_string($list) ? PlanText::em($list) : '';
		return $listName !== '' ? $place . ' › ' . $listName : $place;
	}
}
