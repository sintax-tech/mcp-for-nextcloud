<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Access;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/** Keeps preview lazy, allowing one native actor bootstrap only after an unmapped native miss. */
final class PreviewRoot {
	/**
	 * @param IRootFolder $roots Native root provider, retained for legacy constructions without the actor adapter.
	 * @param ActorRoot|null $actor Real-session actor adapter explicitly supplied by the Files module in production.
	 */
	public function __construct(private IRootFolder $roots, private ?ActorRoot $actor) {
	}

	/**
	 * Runs only the existing tool plan, never a confirmed operation or sink.
	 *
	 * This catches native NotFound before NodeAccess maps it. Policy/ACL/visibility failures propagate
	 * without retry. Native setup itself can have platform effects, including on genuinely missing paths.
	 *
	 * @param string $uid Authenticated preview actor.
	 * @param callable(Folder):array<string,mixed> $plan Existing plan and all of its admission checks.
	 * @return array<string,mixed> First successful plan or the single repeated plan.
	 * @throws NotFoundException When native lookup still misses after the one allowed retry.
	 */
	public function run(string $uid, callable $plan): array {
		try {
			$root = $this->actor?->lazy($uid) ?? $this->roots->getUserFolder($uid);
			return $plan($root);
		} catch (NotFoundException $error) {
			if ($this->actor === null) {
				throw $error;
			}
		}
		return $plan($this->actor->get($uid));
	}
}
