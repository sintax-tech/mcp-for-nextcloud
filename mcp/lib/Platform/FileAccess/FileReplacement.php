<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\FileAccess;

/**
 * Protected replacement port; implemented by Files with its existing pre-write backup, never a bypass of its policy,
 * versioning precondition, locks or native ACLs.
 */
interface FileReplacement {
	/**
	 * @param string $userId Actor UID.
	 * @param string $path Path of the existing file.
	 * @param string|null $etag ETag the caller read before, null to skip the check.
	 * @return array<string,mixed> The file to be replaced (path, fileId, size, etag), the pre-write copy that will be
	 *         made or the administrator suspension that skips it, and the shared-write fields. Nothing is written.
	 */
	public function inspect(string $userId, string $path, ?string $etag): array;

	/**
	 * @param string $userId Actor UID.
	 * @param string $path Path of the existing file.
	 * @param string $content Unmodified bytes.
	 * @param bool $shared Shared-write confirmation.
	 * @param int $fileId File inspected before the content was read.
	 * @param string $etag ETag the write must still find.
	 * @return array<string,mixed> Read-back receipt naming the copy or its suspension, or the shared confirmation.
	 */
	public function replace(string $userId, string $path, string $content, bool $shared, int $fileId, string $etag): array;
}
