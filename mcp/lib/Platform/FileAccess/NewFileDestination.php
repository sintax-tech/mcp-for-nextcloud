<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\FileAccess;

/** Protected creation port; implemented by Files, never a bypass of its policy or native ACLs. */
interface NewFileDestination {
	/**
	 * @param string $userId Actor UID.
	 * @param string $path Final path.
	 * @return array<string,mixed> Guarded destination metadata, without creating a node.
	 * @throws FileNameTaken When the name is already taken; {@see FileReplacement} is the explicit alternative.
	 */
	public function inspect(string $userId, string $path): array;
	/**
	 * @param string $userId Actor UID.
	 * @param string $path Final path.
	 * @param string $content Unmodified bytes.
	 * @param bool $shared Shared-write confirmation.
	 * @param int $folderId Previously resolved folder.
	 * @return array<string,mixed> Read-back receipt or shared confirmation.
	 * @throws FileNameTaken When the name was taken since the inspection; nothing is overwritten.
	 */
	public function create(string $userId, string $path, string $content, bool $shared, int $folderId): array;
}
