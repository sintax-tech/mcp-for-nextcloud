<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Reading;

use Closure;
use InvalidArgumentException;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Backs `notes_search`: notes whose title or content contains the query, newest first, with a snippet around the
 * first match in the content.
 *
 * Without a notes folder the answer is still an empty JSON list, followed by the notice of {@see NotesFolderNotice}.
 */
final class SearchNotesHandler {
	/** Characters of context kept before a content match. */
	private const BEFORE = 50;
	/** Characters of a snippet besides the query itself. */
	private const LENGTH = 120;
	/** Characters of the start of a note shown when only the title matched. */
	private const HEAD = 100;

	/**
	 * Receives note lookup, the access description, administrator exclusions and the category lookup.
	 *
	 * @param NotesRepository $notes Note lookup and reads.
	 * @param NodeAccessInfo $accessInfo Who owns a note and who else it reaches.
	 * @param VisibilityGuard|null $visibilityGuard Administrator exclusions, null when none apply.
	 * @param Closure(?Folder, string): ?Folder $existingCategory The module's lookup of a visible, existing category.
	 * @return void
	 */
	public function __construct(
		private NotesRepository $notes,
		private NodeAccessInfo $accessInfo,
		private ?VisibilityGuard $visibilityGuard,
		private Closure $existingCategory,
	) {
	}

	/**
	 * Searches the notes of the account.
	 *
	 * @param Folder|null $root Notes folder, null when it does not exist.
	 * @param string $userId Authenticated user.
	 * @param array{query:string, limit:int, category?:string} $arguments Validated tool arguments.
	 * @return array{content: list<array{type:string, text:string}>} The matches, with the missing-folder notice when it applies.
	 * @throws InvalidArgumentException When the category is invalid.
	 * @throws \JsonException When the answer cannot be encoded.
	 */
	public function handle(?Folder $root, string $userId, array $arguments): array {
		$matches = $this->matches($root, $userId, (string)$arguments['query'], (int)$arguments['limit'], (string)($arguments['category'] ?? ''));
		return NotesFolderNotice::attach('notes_search', $root, ToolResult::json($matches));
	}

	/**
	 * The matching notes, at most $limit.
	 *
	 * @param Folder|null $root Notes folder, null when it does not exist.
	 * @param string $userId Authenticated user.
	 * @param string $query Search term.
	 * @param int $limit Maximum number of results.
	 * @param string $category Category to search in; '' for every note.
	 * @return list<array{id:int, title:string, category:string, modified:int, etag:string, snippet:string, access:array<string, mixed>}> Matches.
	 * @throws InvalidArgumentException When the category is invalid.
	 */
	private function matches(?Folder $root, string $userId, string $query, int $limit, string $category): array {
		if ($root === null || $query === '') {
			return [];
		}
		$folder = ($this->existingCategory)($root, $category);
		if ($folder === null) {
			return [];
		}
		$all = $this->notes->all($folder);
		usort($all, static fn (File $a, File $b): int => $b->getMTime() <=> $a->getMTime());
		$results = [];
		foreach ($this->visibilityGuard?->filter($all) ?? array_values($all) as $note) {
			if (!$note instanceof File || !NotesRepository::isNote($note) || !$note->isReadable()) {
				continue;
			}
			$snippet = $this->snippet($note, $query);
			if ($snippet === null) {
				continue;
			}
			$results[] = $this->notes->info($note, $root) + [
				'snippet' => $snippet,
				'access' => $this->accessInfo->describe($note, $userId),
			];
			if (count($results) >= $limit) {
				break;
			}
		}
		return $results;
	}

	/**
	 * The snippet of a matching note, or null when neither its title nor its content contains the query.
	 *
	 * Content over the read limit is not opened, so such a note matches by title only and shows no text.
	 *
	 * @param File $note Readable note.
	 * @param string $query Search term.
	 * @return string|null Snippet with whitespace collapsed, '...' where text was cut; null when nothing matched.
	 */
	private function snippet(File $note, string $query): ?string {
		$titleMatch = mb_stripos(pathinfo($note->getName(), PATHINFO_FILENAME), $query) !== false;
		$content = '';
		$position = false;
		if ($note->getSize() <= NotesRepository::MAX_BYTES) {
			$content = mb_scrub((string)$note->getContent(), 'UTF-8');
			$position = mb_stripos($content, $query);
		}
		if ($position !== false) {
			$start = max(0, $position - self::BEFORE);
			$length = self::LENGTH + mb_strlen($query);
			$snippet = self::collapse(mb_substr($content, $start, $length));
			return ($start > 0 ? '...' : '') . $snippet . ($start + $length < mb_strlen($content) ? '...' : '');
		}
		if (!$titleMatch) {
			return null;
		}
		return self::collapse(mb_substr($content, 0, self::HEAD)) . (mb_strlen($content) > self::HEAD ? '...' : '');
	}

	/**
	 * Collapses runs of whitespace into one space and trims the result.
	 *
	 * @param string $text Raw text.
	 * @return string Text on one line.
	 */
	private static function collapse(string $text): string {
		return trim((string)preg_replace('/\s+/', ' ', $text));
	}
}
