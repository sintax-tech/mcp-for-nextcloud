<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion;

use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCA\Mcp\Tools\ToolResult;

/** Observations are bounded: absence is not a trash receipt and native void is never treated as success. */
final class DeleteOutcome {
	/**
	 * Measures the original location without interpreting native void as a recovery receipt.
	 *
	 * @param Folder $root Actor's root, only resolving the already admitted path.
	 * @param string $path Approved path.
	 * @param int $id Approved identity.
	 * @param string $etag ETag from the final prepare.
	 * @param bool $failed Whether the native operation threw, including exceptions after side effects.
	 * @return array<string, mixed> MCP envelope; preserved and uncertain outcomes are errors.
	 */
	public static function observe(Folder $root, string $path, int $id, string $etag, bool $failed): array {
		$state = 'uncertain';
		try {
			$node = $root->get(ltrim($path, '/'));
			if ((int)$node->getId() === $id && (string)$node->getEtag() === $etag) {
				$state = 'preserved';
			}
		} catch (NotFoundException) {
			$state = $failed ? 'uncertain' : 'removed_destination_unconfirmed';
		} catch (\Throwable) {
			// Observation failure never becomes success or disclosure of native error details.
		}
		$message = match ($state) {
			'preserved' => DeleteMessages::preserved(),
			'removed_destination_unconfirmed' => DeleteMessages::removed() . ' ' . DeleteMessages::risk(),
			default => DeleteMessages::uncertain() . ' ' . DeleteMessages::risk(),
		};
		return ToolResult::json(['status' => $state, 'path' => $path, 'idBefore' => $id,
			'recoveryConfirmed' => false, 'message' => $message]) + ($state === 'removed_destination_unconfirmed' ? [] : ['isError' => true]);
	}
}
