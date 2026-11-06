<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;

use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCP\Files\Folder;

/** Owns files_create planning and confirmation. */
final class CreateFileHandler {
	/** @param FileCreation $creation Resolved target and guarded creation service. */
	public function __construct(private FileCreation $creation) {
	}

	/**
	 * @param Folder $root Owner's root.
	 * @param string $userId Owner.
	 * @param string $path Destination.
	 * @param string $content Text bytes.
	 * @return array<string, mixed> Plan without effects.
	 * @throws ArgumentValidationException If the path or inline type is invalid.
	 * @throws ToolFailure If destination access, size or storage refuses.
	 * @throws FileTypeDenied If policy refuses the final name.
	 * @throws FileWritePolicyUnavailable If the policy cannot be read.
	 */
	public function plan(Folder $root, string $userId, string $path, string $content): array {
		return $this->creation->planCreate($root, $userId, $path, $content);
	}

	/**
	 * @param Folder $root Owner's root.
	 * @param string $userId Owner.
	 * @param string $path Destination.
	 * @param string $content Text bytes.
	 * @param bool $confirmed Shared-write confirmation.
	 * @return array<string, mixed> New-file receipt or shared-write confirmation.
	 * @throws ArgumentValidationException If the path or inline type is invalid.
	 * @throws ToolFailure If destination access, size, lock or storage refuses.
	 * @throws FileTypeDenied If policy refuses the final name.
	 * @throws FileWritePolicyUnavailable If the policy cannot be read.
	 */
	public function execute(Folder $root, string $userId, string $path, string $content, bool $confirmed): array {
		return $this->creation->create($root, $userId, $path, $content, $confirmed);
	}
}
