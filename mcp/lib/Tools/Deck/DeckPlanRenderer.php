<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\L10n\Translator;

/**
 * Renders human-readable confirmation Markdown plans for Deck write operations.
 */
final class DeckPlanRenderer {
	private const MAX_TEXT_LENGTH = 200;

	private \DateTimeZone $zone;

	public function __construct(?\DateTimeZone $zone = null) {
		$this->zone = $zone ?? new \DateTimeZone(date_default_timezone_get());
	}

	/**
	 * @param string $tool Tool name
	 * @param array<string, mixed> $plan Write plan
	 * @return string|null Markdown body, or null to fall back to generic
	 */
	public function render(string $tool, array $plan): ?string {
		try {
			return match ($tool) {
				'deck_create_card' => $this->renderCreate($plan),
				'deck_edit_card' => $this->renderEdit($plan),
				'deck_move_card' => $this->renderMove($plan),
				'deck_delete_card' => $this->renderDelete($plan),
				default => null,
			};
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderCreate(array $plan): ?string {
		$card = $plan['card'] ?? null;
		$dest = $plan['destination'] ?? null;
		if (!is_array($card) || !isset($card['title']) || !is_string($card['title']) || $card['title'] === '') {
			return null;
		}
		if (!is_array($dest) || !isset($dest['board']) || !is_string($dest['board']) || $dest['board'] === '') {
			return null;
		}

		$title = $card['title'];
		$board = $dest['board'];
		$list = isset($dest['list']) && is_string($dest['list']) ? $dest['list'] : '';

		$place = $list !== '' ? "*{$board}* › *{$list}*" : "*{$board}*";

		$lines = [];
		$lines[] = Translator::t('Create card in %s: **%s**', [$place, $title]);

		if (isset($card['description']) && is_string($card['description']) && $card['description'] !== '') {
			$lines[] = Translator::t('- Description: %s', [$this->truncate($card['description'])]);
		}

		$dueDate = $card['dueDate'] ?? $card['duedate'] ?? null;
		if (is_string($dueDate) && $dueDate !== '') {
			$lines[] = Translator::t('- Due date: %s', [$this->formatDate($dueDate)]);
		}

		$assignees = $this->extractAssignees($card);
		if ($assignees !== []) {
			$lines[] = count($assignees) === 1
				? Translator::t('- Assignee: %s', [$assignees[0]])
				: Translator::t('- Assignees: %s', [implode(', ', $assignees)]);
		}

		if (!empty($dest['shared'])) {
			$owner = !empty($dest['ownerDisplayName']) && is_string($dest['ownerDisplayName'])
				? $dest['ownerDisplayName']
				: (string)($dest['owner'] ?? '');
			if ($owner !== '') {
				$lines[] = Translator::t('- Shared board of %s.', [$owner]);
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderEdit(array $plan): ?string {
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

		$board = $plan['board'] ?? null;
		$boardName = is_array($board) && isset($board['board']) && is_string($board['board']) ? $board['board'] : '';

		$lines = [];
		if ($boardName !== '') {
			$lines[] = Translator::t('Edit card in %s: **%s**', ['*' . $boardName . '*', $title]);
		} else {
			$lines[] = Translator::t('Edit card: **%s**', [$title]);
		}

		foreach ($changed as $field) {
			if ($field === 'title' && isset($before['title'], $after['title'])) {
				$lines[] = Translator::t('- Title: %s → %s', [(string)$before['title'], (string)$after['title']]);
			} elseif ($field === 'description' && (array_key_exists('description', $before) || array_key_exists('description', $after))) {
				$beforeDesc = !empty($before['description']) ? $this->truncate((string)$before['description']) : Translator::t('(empty)');
				$afterDesc = !empty($after['description']) ? $this->truncate((string)$after['description']) : Translator::t('(empty)');
				$lines[] = Translator::t('- Description: %s → %s', [$beforeDesc, $afterDesc]);
			} elseif ($field === 'duedate' && (array_key_exists('duedate', $before) || array_key_exists('duedate', $after))) {
				$beforeDue = !empty($before['duedate']) ? $this->formatDate((string)$before['duedate']) : Translator::t('(no due date)');
				$afterDue = !empty($after['duedate']) ? $this->formatDate((string)$after['duedate']) : Translator::t('(no due date)');
				$lines[] = Translator::t('- Due date: %s → %s', [$beforeDue, $afterDue]);
			}
		}

		if (is_array($board) && !empty($board['shared'])) {
			$owner = !empty($board['ownerDisplayName']) && is_string($board['ownerDisplayName'])
				? $board['ownerDisplayName']
				: (string)($board['owner'] ?? '');
			if ($owner !== '') {
				$lines[] = Translator::t('- Shared board of %s.', [$owner]);
			}
		}

		return implode("\n", $lines);
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

		$title = $card['title'];
		$lines = [];
		$lines[] = Translator::t('Move card: **%s**', [$title]);

		$origList = isset($orig['list']) && is_string($orig['list']) ? $orig['list'] : '';
		$destList = isset($dest['list']) && is_string($dest['list']) ? $dest['list'] : '';
		$origPlace = $origList !== '' ? "*{$orig['board']}* › *{$origList}*" : "*{$orig['board']}*";
		$destPlace = $destList !== '' ? "*{$dest['board']}* › *{$destList}*" : "*{$dest['board']}*";

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
			if ($owner !== '') {
				$lines[] = Translator::t('- Destination board shared by %s.', [$owner]);
			}
		} elseif (!empty($orig['shared'])) {
			$owner = !empty($orig['ownerDisplayName']) && is_string($orig['ownerDisplayName'])
				? $orig['ownerDisplayName']
				: (string)($orig['owner'] ?? '');
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

		$title = $card['title'];
		$board = $plan['board'] ?? null;
		$boardName = is_array($board) && isset($board['board']) && is_string($board['board']) ? $board['board'] : '';

		$lines = [];
		if ($boardName !== '') {
			$lines[] = Translator::t('Delete card in %s: **%s**', ['*' . $boardName . '*', $title]);
		} else {
			$lines[] = Translator::t('Delete card: **%s**', [$title]);
		}

		if (isset($plan['consequence']) && is_string($plan['consequence']) && $plan['consequence'] !== '') {
			$lines[] = '- ' . $plan['consequence'];
		}

		if (is_array($board) && !empty($board['shared'])) {
			$owner = !empty($board['ownerDisplayName']) && is_string($board['ownerDisplayName'])
				? $board['ownerDisplayName']
				: (string)($board['owner'] ?? '');
			if ($owner !== '') {
				$lines[] = Translator::t('- Shared board of %s.', [$owner]);
			}
		}

		return implode("\n", $lines);
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

	private function formatDate(string $raw): string {
		try {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
				return $raw;
			}
			$dt = (new \DateTimeImmutable($raw))->setTimezone($this->zone);
			if ($dt->format('H:i:s') === '00:00:00') {
				return $dt->format('Y-m-d');
			}
			return $dt->format('Y-m-d H:i');
		} catch (\Throwable) {
			return $raw;
		}
	}

	private function truncate(string $text): string {
		$clean = trim(str_replace(["\r\n", "\r", "\n"], ' ', $text));
		if (mb_strlen($clean) <= self::MAX_TEXT_LENGTH) {
			return $clean;
		}
		return mb_substr($clean, 0, self::MAX_TEXT_LENGTH - 1) . '…';
	}
}
