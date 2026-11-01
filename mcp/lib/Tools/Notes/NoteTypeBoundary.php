<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;

/**
 * One file-name policy snapshot shared by every basename of a single Notes preflight.
 *
 * The guard is optional only so existing unit doubles that build a module without the container keep the
 * previous behavior. Production always receives the guard from the container. A null guard does not mean the
 * policy is off: an injected guard whose stored mode is off is the real off switch.
 */
final class NoteTypeBoundary {
	/**
	 * Receives the policy reader shared by one preflight or one content write.
	 *
	 * @param FileTypeGuard|null $types Policy reader. Null skips the check and is not a bypass in production.
	 * @return void
	 */
	public function __construct(private ?FileTypeGuard $types) {
	}

	/**
	 * Read the policy once and apply that immutable snapshot to every projected basename.
	 *
	 * An empty list writes nothing, so it does not read the policy. A refusal throws before the caller
	 * creates a category or writes bytes.
	 *
	 * @param list<string> $basenames Final basenames of this preflight, never paths.
	 * @return void
	 * @throws FileTypeDenied When the active policy refuses one of the names.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read. That is not treated as off.
	 */
	public function assertProjected(array $basenames): void {
		if ($this->types === null || $basenames === []) {
			return;
		}
		$check = $this->types->snapshot();
		foreach ($basenames as $basename) {
			$check->assertWritableName($basename);
		}
	}
}
