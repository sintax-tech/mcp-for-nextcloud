<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\FileTypes;

/** Immutable validated policy snapshot, shared by every name in one operation boundary. */
final class FileTypeCheck {
	/**
	 * @param array{version:int, mode:string, extensions:list<string>, allowExtensionless:bool} $policy Validated snapshot from FileTypeGuard.
	 */
	public function __construct(private readonly array $policy) {
	}

	/** @return bool Whether this boundary requires file type enforcement and bounded descendant preflight. */
	public function isActive(): bool {
		return $this->policy['mode'] !== 'off';
	}

	/**
	 * Evaluate a basename without reading or invalidating any configuration cache.
	 *
	 * @param string $finalName Resolved final basename.
	 * @return void
	 * @throws FileTypeDenied If this snapshot does not permit the name.
	 */
	public function assertWritableName(string $finalName): void {
		$policy = $this->policy;
		if ($policy['mode'] === 'off') {
			return;
		}
		if ($finalName === '' || $finalName === '.' || $finalName === '..'
			|| str_contains($finalName, '/') || str_contains($finalName, '\\') || str_contains($finalName, ':')
			|| preg_match('/[\x00-\x1f\x7f]/', $finalName) === 1
			|| preg_match('/[.\s]\z/u', $finalName) !== 0) {
			throw new FileTypeDenied();
		}
		$dot = strrpos($finalName, '.');
		if ($dot === false || $dot === 0) {
			if (!$policy['allowExtensionless']) {
				throw new FileTypeDenied();
			}
			return;
		}
		$extension = strtolower(substr($finalName, $dot + 1));
		if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,31}\z/D', $extension) !== 1
			|| !in_array($extension, $policy['extensions'], true)) {
			throw new FileTypeDenied();
		}
	}
}
