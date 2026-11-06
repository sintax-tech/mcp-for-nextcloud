<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Describe ownership, confirmations and versions for move receipts. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCA\Mcp\Modules\Files\Integration\MoveReport;

/** Preserves the existing MoveReceipt operations and refusal order. */
final class MoveReceipt {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param SharedWriteGuard $guard guard collaborator.
	 * @param NodeAccessInfo $access access collaborator.
	 * @param MoveReport $report report collaborator.
	 * @param \OCP\IUserManager $userManager userManager collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly SharedWriteGuard $guard,
		private readonly NodeAccessInfo $access,
		private readonly MoveReport $report,
		private readonly \OCP\IUserManager $userManager,
	) {
	}

	/**
	 * Both ends of the operation go through the guard: moving a file out of a team folder changes what the
	 * team sees, and moving one into it adds something they did not put there.
	 *
	 * @param Node $source the node being moved or copied
	 * @param string $sourcePath the source, as the user wrote it
	 * @param Folder $destination the folder that would receive it
	 * @param string $destinationPath the destination, as the user wrote it
	 * @param string $userId authenticated user
	 * @param bool $confirmedShared whether the caller passed confirm_shared
	 * @return array<string, mixed>|null the confirmation payload, or null when the change may proceed
	 */
	public function confirmBoth(
		Node $source,
		string $sourcePath,
		Folder $destination,
		string $destinationPath,
		string $userId,
		bool $confirmedShared,
	): ?array {
		return $this->guard->guard($source, $userId, $sourcePath, $confirmedShared)
			?? $this->guard->guard($destination, $userId, $destinationPath, $confirmedShared);
	}

	/**
	 * Which of the given nodes reach people other than the caller, described the way the write would ask.
	 *
	 * @param string $userId authenticated user
	 * @param Node ...$nodes ends of the operation: the source and the folder that would receive it
	 * @return list<array<string, mixed>> the ownership description of each non-personal end, empty when all are personal
	 */
	public function sharedOf(string $userId, Node ...$nodes): array {
		$shared = [];
		foreach ($nodes as $node) {
			$info = $this->access->describe($node, $userId);
			if ($info['scope'] !== NodeAccessInfo::PERSONAL) {
				$shared[] = $info;
			}
		}
		return $shared;
	}

	/**
	 * Versions belong to files: a folder has none of its own, and asking anyway would report a count nobody
	 * could check.
	 *
	 * @param Node $node the node to count versions of
	 * @param string $userId authenticated user
	 * @return int|null the version count, or null for a folder or when versioning is off
	 */
	public function versions(Node $node, string $userId): ?int {
		return $node instanceof File ? $this->report->versions($node, $this->user($userId)) : null;
	}

	/**
	 * @param string $userId authenticated user
	 * @return \OCP\IUser|null the user, when the manager knows them
	 */
	private function user(string $userId): ?\OCP\IUser {
		return $this->userManager->get($userId);
	}
}
