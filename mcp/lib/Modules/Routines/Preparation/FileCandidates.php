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
use OCA\Mcp\Platform\Meetings\MeetingFileRead;
use OCA\Mcp\Platform\Meetings\PreparationSources;

/**
 * Files whose path is already on the occurrence or on a Deck link that was read.
 *
 * A path is one files_read. There is no search by name. A path the reader refuses is omitted, with no count.
 */
final class FileCandidates {
	/**
	 * @param PreparationSources $sources the read ports
	 * @param GrantPolicy $grants the actor's grants
	 */
	public function __construct(
		private PreparationSources $sources,
		private GrantPolicy $grants,
	) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $event the chosen occurrence
	 * @param list<string> $linked paths taken from Deck links already read
	 * @return array<string, mixed> the files source, with no count of what was not read
	 */
	public function collect(string $userId, array $event, array $linked): array {
		$files = $this->sources->files();
		if ($files === null) {
			return $this->closed('unavailable', 'unbound');
		}
		if (!$this->grants->granted($userId, 'files', 'read')) {
			return $this->closed('not_consulted', 'grant');
		}
		[$paths, $reasons] = $this->paths($event, $linked);
		if ($paths === []) {
			return $this->closed('consulted', 'no_path');
		}
		return $this->read($files, $userId, $paths, $reasons);
	}

	/**
	 * @param MeetingFileRead $files the file port
	 * @param string $userId the actor
	 * @param list<string> $paths normalized paths, within the budget after the slice
	 * @param array<string, string> $reasons path => event_file or deck_link
	 * @return array<string, mixed> the files source
	 */
	private function read(MeetingFileRead $files, string $userId, array $paths, array $reasons): array {
		$truncated = count($paths) > DossierBudget::MAX_FILES;
		$kept = [];
		foreach (array_slice($paths, 0, DossierBudget::MAX_FILES) as $path) {
			try {
				$result = $files->read($userId, ['path' => $path]);
			} catch (ToolFailure | \InvalidArgumentException) {
				continue;
			}
			$kept[] = $this->project($path, $reasons[$path], $result);
		}
		return ['status' => 'consulted', 'reason' => 'explicit_path', 'truncated' => $truncated, 'candidates' => $kept];
	}

	/**
	 * @param array<string, mixed> $event the chosen occurrence
	 * @param list<string> $linked paths from Deck links
	 * @return array{0: list<string>, 1: array<string, string>} normalized paths and the reason of each
	 */
	private function paths(array $event, array $linked): array {
		$rows = [];
		foreach ((array)($event['filePaths'] ?? []) as $path) {
			if (is_string($path)) {
				$rows[] = [$path, 'event_file'];
			}
		}
		foreach ($linked as $path) {
			$rows[] = [$path, 'deck_link'];
		}
		$paths = [];
		$reasons = [];
		foreach ($rows as [$raw, $reason]) {
			try {
				$path = PathGuard::normalize($raw);
			} catch (\InvalidArgumentException) {
				continue;
			}
			if ($path === '/' || isset($reasons[$path])) {
				continue;
			}
			$reasons[$path] = $reason;
			$paths[] = $path;
		}
		return [$paths, $reasons];
	}

	/**
	 * @param string $path the path that was read
	 * @param string $reason event_file or deck_link
	 * @param array<string, mixed> $result the files_read answer
	 * @return array<string, mixed> the candidate, with a short excerpt instead of the file
	 */
	private function project(string $path, string $reason, array $result): array {
		$excerpt = '';
		$meta = [];
		foreach ((array)($result['content'] ?? []) as $block) {
			if (!is_array($block)) {
				continue;
			}
			$text = (string)($block['text'] ?? '');
			$decoded = json_decode($text, true);
			if (is_array($decoded) && !array_is_list($decoded) && isset($decoded['path'])) {
				$meta = $decoded;
				continue;
			}
			if ($excerpt === '') {
				$excerpt = mb_substr($text, 0, DossierBudget::EXCERPT);
			}
		}
		$row = [
			'source' => 'files',
			'reason' => $reason,
			'path' => is_string($meta['path'] ?? null) ? $meta['path'] : $path,
			'etag' => (string)($meta['etag'] ?? ''),
			'excerpt' => $excerpt,
		];
		if (isset($meta['size'])) {
			$row['size'] = (int)$meta['size'];
		}
		if (array_key_exists('mtime', $meta)) {
			$row['mtime'] = $meta['mtime'];
		}
		if (is_string($meta['url'] ?? null)) {
			$row['url'] = $meta['url'];
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
