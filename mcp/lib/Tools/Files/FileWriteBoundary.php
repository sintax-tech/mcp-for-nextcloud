<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCP\Server;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Resolves the required policy collaborator, including callers constructed outside the app container. */
final class FileWriteBoundary {
	/**
	 * @param FileTypeGuard|null $guard Injected evaluator, or the container's evaluator.
	 * @param string $name Resolved final basename.
	 * @return void
	 * @throws ToolFailure If policy denies the name or cannot be read.
	 */
	public static function check(?FileTypeGuard $guard, string $name): void {
		($guard ?? Server::get(FileTypeGuard::class))->snapshot()->assertWritableName($name);
	}
}
