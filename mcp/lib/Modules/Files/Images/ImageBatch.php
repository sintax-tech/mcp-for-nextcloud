<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;

/** Returns ordered image previews under the unchanged shared byte budget. */
final class ImageBatch {
	/**
	 * @param ImageSelection $selection Existing request-scoped collaborator.
	 * @param ImagePreview $preview Existing request-scoped collaborator.
	 * @param ImageMetadata $metadata Existing request-scoped collaborator.
	 */
	public function __construct(
		private ImageSelection $selection,
		private ImagePreview $preview,
		private ImageMetadata $metadata,
	) {
	}

	/**
	 * Several images, under a shared byte budget. Pass either paths (up to BATCH_MAX_ITEMS) or folder
	 * (plus optional limit) to pick the most recently modified images under that folder. Skipped items
	 * are reported in the trailing summary.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param list<string>|null $paths explicit paths, or null to pick from $folder
	 * @param string|null $folder folder path when $paths is null
	 * @param int $limit maximum items when picking from $folder
	 * @param int $maxSize max dimension in pixels per image
	 * @return array{content: list<array<string, mixed>>}
	 * @throws ToolFailure when neither or both inputs are provided, or when the folder is unreachable
	 */
	public function viewMany(Folder $root, string $userId, ?array $paths, ?string $folder, int $limit, int $maxSize): array {
		if ($paths === null && $folder === null) {
			throw new ToolFailure(FilesMessages::imagesNoTarget());
		}
		if ($paths !== null && $folder !== null) {
			throw new ToolFailure(FilesMessages::imagesConflict());
		}
		$selected = $paths !== null
			? $this->selection->selectPaths($root, $paths)
			: $this->selection->selectFromFolder($root, $userId, (string)$folder, $limit);
		$budget = $this->preview->batchMaxBytes();
		$items = [];
		$skipped = [];
		$used = 0;
		foreach ($selected as $entry) {
			[$path, $file, $error] = $entry;
			if ($error !== null) {
				$skipped[] = ['path' => $path, 'reason' => $error];
				continue;
			}
			$remaining = $budget - $used;
			if ($remaining <= 0) {
				$skipped[] = ['path' => $path, 'reason' => 'batch budget exhausted'];
				continue;
			}
			try {
				[$bytes, $mime, $effective] = $this->preview->renderPreview($file, $maxSize, min($this->preview->singleMaxBytes(), $remaining));
			} catch (ToolFailure $e) {
				$skipped[] = ['path' => $path, 'reason' => $e->getMessage()];
				continue;
			}
			$used += strlen($bytes);
			$items[] = [
				'bytes' => $bytes,
				'mimeType' => $mime,
				'metadata' => $this->metadata->preview($file, $path, $userId, $effective),
			];
		}
		$summary = [
			'returned' => count($items),
			'skipped' => $skipped,
			'bytes_used' => $used,
			'bytes_budget' => $budget,
		];
		return ToolResult::images($items, $summary);
	}
}
