<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use OCA\Mcp\L10n\Translator;

/**
 * Renders human-readable confirmation Markdown plans for Notes write operations.
 */
final class NotesPlanRenderer {
	private const MAX_TEXT_LENGTH = 200;

	/**
	 * @param string $tool Tool name
	 * @param array<string, mixed> $plan Write plan
	 * @return string|null Markdown body, or null to fall back to generic
	 */
	public function render(string $tool, array $plan): ?string {
		try {
			return match ($tool) {
				'notes_create' => $this->renderCreate($plan),
				'notes_edit' => $this->renderEdit($plan),
				'notes_move' => $this->renderMove($plan),
				'notes_delete' => $this->renderDelete($plan),
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
		$note = $plan['note'] ?? null;
		if (!is_array($note) || !isset($note['title']) || !is_string($note['title']) || $note['title'] === '') {
			return null;
		}

		$title = $note['title'];
		$category = isset($note['category']) && is_string($note['category']) ? trim($note['category'], '/') : '';

		$lines = [];
		if ($category !== '') {
			$lines[] = Translator::t('Create note in *%s*: **%s**', [$category, $title]);
		} else {
			$lines[] = Translator::t('Create note: **%s**', [$title]);
		}

		if (!empty($note['categoryCreated'])) {
			$lines[] = Translator::t('- The category will be created.');
		}

		if (isset($note['content']) && is_string($note['content']) && $note['content'] !== '') {
			$lines[] = Translator::t('- Content: %s', [$this->truncate($note['content'])]);
		}

		$this->appendSharedConsequences($lines, $plan['shared'] ?? []);

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderEdit(array $plan): ?string {
		$note = $plan['note'] ?? null;
		if (!is_array($note) || !isset($note['before'], $note['after']) || !is_array($note['before']) || !is_array($note['after'])) {
			return null;
		}
		$changed = $plan['changed'] ?? null;
		if (!is_array($changed)) {
			return null;
		}

		$before = $note['before'];
		$after = $note['after'];
		$title = $before['title'] ?? $after['title'] ?? null;
		if (!is_string($title) || $title === '') {
			return null;
		}

		$category = (string)($before['category'] ?? $after['category'] ?? '');
		$lines = [];
		if ($category !== '') {
			$lines[] = Translator::t('Edit note in *%s*: **%s**', [$category, $title]);
		} else {
			$lines[] = Translator::t('Edit note: **%s**', [$title]);
		}

		foreach ($changed as $field) {
			if ($field === 'title' && isset($before['title'], $after['title'])) {
				$lines[] = Translator::t('- Title: %s → %s', [(string)$before['title'], (string)$after['title']]);
			} elseif ($field === 'content' && (array_key_exists('content', $before) || array_key_exists('content', $after))) {
				$beforeExcerpt = $this->truncate((string)($before['content'] ?? ''));
				$afterExcerpt = $this->truncate((string)($after['content'] ?? ''));
				$lines[] = Translator::t('- Content: %s → %s', [$beforeExcerpt, $afterExcerpt]);
			}
		}

		$this->appendSharedConsequences($lines, $plan['shared'] ?? []);

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderMove(array $plan): ?string {
		$note = $plan['note'] ?? null;
		if (!is_array($note) || !isset($note['title']) || !is_string($note['title']) || $note['title'] === '') {
			return null;
		}
		if (!isset($plan['from'], $plan['to']) || !is_string($plan['from']) || !is_string($plan['to'])) {
			return null;
		}

		$title = $note['title'];
		$from = trim($plan['from'], '/');
		$to = trim($plan['to'], '/');

		$lines = [];
		$lines[] = Translator::t('Move note: **%s**', [$title]);

		$fromName = $from !== '' ? '*' . $from . '*' : Translator::t('(root)');
		$toName = $to !== '' ? '*' . $to . '*' : Translator::t('(root)');
		$lines[] = Translator::t('- Category: %s → %s', [$fromName, $toName]);

		if (!empty($plan['categoryCreated'])) {
			$lines[] = Translator::t('- The destination category will be created.');
		}

		if (!empty($plan['titleTaken'])) {
			$lines[] = Translator::t('- A note with this title already exists in the destination category.');
		}

		$this->appendSharedConsequences($lines, $plan['shared'] ?? []);

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function renderDelete(array $plan): ?string {
		$note = $plan['note'] ?? null;
		if (!is_array($note) || !isset($note['title']) || !is_string($note['title']) || $note['title'] === '') {
			return null;
		}

		$title = $note['title'];
		$category = isset($note['category']) && is_string($note['category']) ? trim($note['category'], '/') : '';

		$lines = [];
		if ($category !== '') {
			$lines[] = Translator::t('Delete note in *%s*: **%s**', [$category, $title]);
		} else {
			$lines[] = Translator::t('Delete note: **%s**', [$title]);
		}

		if (isset($plan['consequence']) && is_string($plan['consequence']) && $plan['consequence'] !== '') {
			$lines[] = '- ' . $plan['consequence'];
		}

		$this->appendSharedConsequences($lines, $plan['shared'] ?? []);

		return implode("\n", $lines);
	}

	/**
	 * @param list<string> $lines
	 * @param mixed $shared
	 */
	private function appendSharedConsequences(array &$lines, mixed $shared): void {
		if (!is_array($shared) || $shared === []) {
			return;
		}
		$first = $shared[0] ?? null;
		if (is_array($first)) {
			if (!empty($first['teamFolder']) && is_string($first['teamFolder'])) {
				$lines[] = Translator::t('- Team folder: %s', [$first['teamFolder']]);
				return;
			}
			if (!empty($first['sharedBy']) && is_string($first['sharedBy'])) {
				$lines[] = Translator::t('- Note shared by %s', [$first['sharedBy']]);
				return;
			}
		}
		$lines[] = Translator::t('- Shared resource.');
	}

	private function truncate(string $text): string {
		$clean = trim(str_replace(["\r\n", "\r", "\n"], ' ', $text));
		if (mb_strlen($clean) <= self::MAX_TEXT_LENGTH) {
			return $clean;
		}
		return mb_substr($clean, 0, self::MAX_TEXT_LENGTH - 1) . '…';
	}
}
