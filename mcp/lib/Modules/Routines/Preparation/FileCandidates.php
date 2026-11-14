<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\Meetings\LocalInstanceLink;
use OCA\Mcp\Platform\Meetings\MeetingFileRead;
use OCA\Mcp\Platform\Meetings\PreparationSources;

/**
 * Files whose path or file link is already on the occurrence or on a card that was read.
 *
 * A file id or a file URL is resolved to one path, then read. There is no search by name. A reference the reader
 * refuses is omitted, with no count. At most {@see DossierBudget::MAX_REFERENCE_LOOKUPS} references are resolved.
 */
final class FileCandidates {
	/**
	 * @param PreparationSources $sources the read ports
	 * @param GrantPolicy $grants the actor's grants
	 * @param LocalInstanceLink $local which absolute hosts belong to this instance
	 */
	public function __construct(
		private PreparationSources $sources,
		private GrantPolicy $grants,
		private LocalInstanceLink $local,
	) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $event the chosen occurrence
	 * @param list<string> $linked paths taken from Deck links already read
	 * @param list<string> $references file links found on the cards
	 * @return array<string, mixed> the files source, with no count of what was not read
	 */
	public function collect(string $userId, array $event, array $linked, array $references = []): array {
		$files = $this->sources->files();
		if ($files === null) {
			return $this->closed('unavailable', 'unbound');
		}
		if (!$this->grants->granted($userId, 'files', 'read')) {
			return $this->closed('not_consulted', 'grant');
		}
		[$rows, $reasons, $truncated] = $this->paths($files, $userId, $event, $linked, $references);
		if ($rows === []) {
			$closed = $this->closed('consulted', 'no_path');
			$closed['truncated'] = $truncated || ($event['linksTruncated'] ?? false) === true;
			return $closed;
		}
		return $this->read($files, $userId, $rows, $reasons, $truncated || ($event['linksTruncated'] ?? false) === true);
	}

	/**
	 * @param MeetingFileRead $files the file port
	 * @param string $userId the actor
	 * @param list<array{path: string, id: int, mtime: int, url: string}> $rows files already within the budget
	 * @param array<string, string> $reasons path => event_file or deck_link
	 * @param bool $truncated whether the path list or the reference lookup was cut
	 * @return array<string, mixed> the files source
	 */
	private function read(MeetingFileRead $files, string $userId, array $rows, array $reasons, bool $truncated): array {
		$kept = [];
		foreach ($rows as $row) {
			try {
				$result = $files->read($userId, ['path' => $row['path']]);
			} catch (ToolFailure | \InvalidArgumentException) {
				continue;
			}
			$kept[] = $this->project($row, $reasons[$row['path']], $result);
		}
		return ['status' => 'consulted', 'reason' => 'explicit_path', 'truncated' => $truncated, 'candidates' => $kept];
	}

	/**
	 * @param MeetingFileRead $files the file port
	 * @param string $userId the actor
	 * @param array<string, mixed> $event the chosen occurrence
	 * @param list<string> $linked paths from Deck links
	 * @param list<string> $references file links from the cards
	 * @return array{0: list<array{path: string, id: int, mtime: int, url: string}>, 1: array<string, string>, 2: bool} files, the reason of each path, and whether resolution was cut
	 */
	private function paths(MeetingFileRead $files, string $userId, array $event, array $linked, array $references): array {
		$rows = [];
		foreach ((array)($event['links'] ?? []) as $link) {
			if (is_string($link) && ReferenceTexts::isFile($link, $this->local)) {
				$rows[] = ['reference' => $link, 'reason' => 'event_file'];
			}
		}
		foreach ($linked as $path) {
			$rows[] = ['reference' => $path, 'reason' => 'deck_link'];
		}
		foreach ($references as $reference) {
			$rows[] = ['reference' => $reference, 'reason' => 'deck_link'];
		}
		$kept = [];
		$reasons = [];
		$lookups = 0;
		$cut = false;
		foreach ($rows as $row) {
			if ($lookups >= DossierBudget::MAX_REFERENCE_LOOKUPS) {
				$cut = true;
				break;
			}
			$lookups++;
			$placed = $this->one($files, $userId, (string)$row['reference']);
			if ($placed === null || isset($reasons[$placed['path']])) {
				continue;
			}
			$reasons[$placed['path']] = $row['reason'];
			$kept[] = $placed;
		}
		$cut = $cut || count($kept) > DossierBudget::MAX_FILES;
		return [array_slice($kept, 0, DossierBudget::MAX_FILES), $reasons, $cut];
	}

	/**
	 * @param MeetingFileRead $files the file port
	 * @param string $userId the actor
	 * @param string $reference a path or a file link of this instance
	 * @return array{path: string, id: int, mtime: int, url: string}|null the file the locator accepted
	 */
	private function one(MeetingFileRead $files, string $userId, string $reference): ?array {
		try {
			$placed = $files->placed($userId, $reference);
		} catch (ToolFailure | \InvalidArgumentException) {
			return null;
		}
		if (!is_array($placed) || !is_string($placed['path'] ?? null) || $placed['path'] === '') {
			return null;
		}
		try {
			$path = PathGuard::normalize($placed['path']);
		} catch (\InvalidArgumentException) {
			return null;
		}
		if ($path === '/' || !is_int($placed['id'] ?? null) || $placed['id'] <= 0 || !is_int($placed['mtime'] ?? null) || !is_string($placed['url'] ?? null) || $placed['url'] === '') {
			return null;
		}
		return ['path' => $path, 'id' => $placed['id'], 'mtime' => $placed['mtime'], 'url' => $placed['url']];
	}

	/**
	 * @param array{path: string, id: int, mtime: int, url: string} $placed the file the locator accepted
	 * @param string $reason event_file or deck_link
	 * @param array<string, mixed> $result the files_read answer
	 * @return array<string, mixed> the candidate, with a short excerpt instead of the file
	 */
	private function project(array $placed, string $reason, array $result): array {
		$blocks = array_values((array)($result['content'] ?? []));
		$excerpt = is_array($blocks[0] ?? null) ? mb_substr((string)($blocks[0]['text'] ?? ''), 0, DossierBudget::EXCERPT) : '';
		$meta = [];
		if (is_array($blocks[1] ?? null)) {
			$decoded = json_decode((string)($blocks[1]['text'] ?? ''), true);
			if (is_array($decoded) && !array_is_list($decoded)) {
				$meta = $decoded;
			}
		}
		$row = [
			'source' => 'files',
			'reason' => $reason,
			'path' => is_string($meta['path'] ?? null) ? $meta['path'] : $placed['path'],
			'etag' => (string)($meta['etag'] ?? ''),
			'excerpt' => $excerpt,
			'url' => $placed['url'],
			'mtime' => $placed['mtime'],
		];
		if (isset($meta['size'])) {
			$row['size'] = (int)$meta['size'];
		}
		return $row;
	}

	/**
	 * @param string $status consulted, not_consulted or unavailable
	 * @param string $reason why, with no count of hidden objects
	 * @return array<string, mixed> a files source that holds no files
	 */
	private function closed(string $status, string $reason): array {
		return ['status' => $status, 'reason' => $reason, 'truncated' => false, 'candidates' => []];
	}
}
