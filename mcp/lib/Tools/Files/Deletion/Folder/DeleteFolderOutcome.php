<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion\Folder;

use OCA\Mcp\Tools\Files\Deletion\DeleteMessages;
use OCA\Mcp\Tools\ToolResult;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/** A surviving root is insufficient evidence that its entire subtree survived a native void/partial operation. */
final class DeleteFolderOutcome {
	/**
	 * Reobserves the complete bounded tree, never deleting or restoring anything as compensation.
	 * @param Folder $root Actor's personal root.
	 * @param string $path Admitted source path.
	 * @param int $id Admitted root identity.
	 * @param string $etag Admitted root ETag.
	 * @param bool $failed Whether the attempt or observation invalidation threw.
	 * @param array<string,mixed> $before Complete pre-attempt observation.
	 * @param callable(Folder):array<string,mixed> $inspect Fresh complete-tree observation, or refusal.
	 * @return array<string,mixed> MCP receipt without a trash/recovery promise.
	 */
	public static function observe(Folder $root, string $path, int $id, string $etag, bool $failed, array $before, callable $inspect): array {
		$state = 'uncertain';
		try {
			$node = $root->get(ltrim($path, '/'));
			if ($node instanceof Folder && (int)$node->getId() === $id && (string)$node->getEtag() === $etag
				&& $inspect($node) === $before) {
				$state = 'preserved';
			}
		} catch (NotFoundException) {
			$state = $failed ? 'uncertain' : 'removed_destination_unconfirmed';
		} catch (\Throwable) {
			// A partial/inaccessible subtree never becomes a claim of preserved contents.
		}
		$message = match ($state) {
			'preserved' => DeleteFolderMessages::preserved(),
			'removed_destination_unconfirmed' => DeleteFolderMessages::removed() . ' ' . DeleteMessages::risk(),
			default => DeleteMessages::uncertain() . ' ' . DeleteMessages::risk(),
		};
		return ToolResult::json(['status' => $state, 'path' => $path, 'idBefore' => $id,
			'recoveryConfirmed' => false, 'message' => $message]) + ($state === 'removed_destination_unconfirmed' ? [] : ['isError' => true]);
	}
}
