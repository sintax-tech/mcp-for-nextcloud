<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files;

use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** Native ordered plan with one shared type snapshot before folder creation or shared confirmation. */
final class MoveBatchPlanner {
	/**
	 * @param CopyMoveContext $context Native inspection and global type policy.
	 * @param MovePlanner $native Existing directory planning, shared with the legacy facade.
	 * @param SharedWriteGuard $guard Existing shared confirmation.
	 */
	public function __construct(private CopyMoveContext $context, private MovePlanner $native, private SharedWriteGuard $guard) {
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param list<array{from:string, to:string}> $moves requested moves
	 * @param list<string> $mkdirs folders the batch would create
	 * @return MovePlan the plan, the runnable items and the pending confirmation
	 * @throws \InvalidArgumentException for a malformed folder path
	 */
	public function plan(Folder $root, string $userId, array $moves, array $mkdirs): MovePlan {
		$this->context->reorganization->assertFolderMoves($root, $moves);
		$typeDenied = [];
		try {
			foreach ((new FileTypePreflight($this->context))->moves($root, $moves, $this->context->policy()->snapshot()) as $conflict) {
				$typeDenied[$conflict['from'] . "\0" . $conflict['to']] = $conflict['reason'];
			}
		} catch (\OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable $e) {
			foreach ($moves as $item) {
				$typeDenied[$item['from'] . "\0" . $item['to']] = $e->getMessage();
			}
		}
		$planned = [];
		$personal = [];
		$conflicts = [];
		$denied = [];
		$shared = [];
		$confirmation = null;
		foreach ($moves as $item) {
			$from = (string)$item['from'];
			$to = (string)$item['to'];
			try {
				if (isset($typeDenied[\OCA\Mcp\Platform\FileAccess\PathGuard::normalize($from) . "\0" . \OCA\Mcp\Platform\FileAccess\PathGuard::normalize($to)])) {
					throw new ToolFailure($typeDenied[\OCA\Mcp\Platform\FileAccess\PathGuard::normalize($from) . "\0" . \OCA\Mcp\Platform\FileAccess\PathGuard::normalize($to)]);
				}
				$check = $this->context->reorganization->inspect($root, $from, $to, mkdirs: $mkdirs);
				// A locked item is one the run would refuse: it is listed as denied with the explanation, so the plan
				// still shows the whole list and the batch never starts by moving half of it.
				$this->context->reorganization->assertUnlocked($check->source, $userId);
			} catch (MoveConflict $e) {
				$conflicts[] = ['from' => $from, 'to' => $to, 'reason' => $e->getMessage()];
				continue;
			} catch (ToolFailure $e) {
				$denied[] = ['from' => $from, 'to' => $to, 'reason' => $e->getMessage()];
				continue;
			}
			$payload = $this->guard->guard($check->source, $userId, $from, false)
				?? $this->guard->guard($check->destination, $userId, $to, false);
			// A shared item stays in the run, in its place in the order: once the user confirms the shared ones, the
			// batch they approved is the whole list, and dropping an item would run one nobody asked for.
			$planned[] = ['from' => $from, 'to' => $to];
			if ($payload !== null) {
				$shared[] = ['from' => $from, 'to' => $to, 'scope' => $payload['scope'] ?? 'shared'];
				// The first non-personal item is the one the user is asked about; the rest are in the plan.
				$confirmation ??= $payload;
				continue;
			}
			$personal[] = ['from' => $from, 'to' => $to];
		}
		$dirs = $this->native->planDirs($root, $mkdirs);
		// A folder made inside somebody else's share asks for the same confirmation as `files_mkdir`, whether
		// or not a move uses it: the moves above only reach it when they land in it.
		$sharedDirs = [];
		foreach ($dirs as $dir) {
			if (!$dir['willCreate']) {
				continue;
			}
			$payload = $this->context->reorganization->guardFolder($root, $userId, $dir['path']);
			if ($payload !== null) {
				$sharedDirs[] = ['path' => $dir['path'], 'scope' => $payload['scope'] ?? 'shared'];
				$confirmation ??= $payload;
			}
		}
		$plan = [
			'ok' => $conflicts === [] && $denied === [] && array_filter($dirs, fn (array $dir) => isset($dir['reason'])) === [],
			'moves' => array_map(fn (array $item) => $item + ['ok' => true], $personal),
			'conflicts' => $conflicts,
			'denied' => $denied,
			'shared' => $shared,
			'sharedDirs' => $sharedDirs,
			'mkdirs' => $dirs,
			'summary' => [
				'total' => count($moves),
				'planned' => count($personal),
				'conflicts' => count($conflicts),
				'denied' => count($denied),
				'shared' => count($shared),
				'sharedDirs' => count($sharedDirs),
				'mkdirs' => count(array_filter($dirs, fn (array $dir) => $dir['willCreate'])),
			],
		];
		return new MovePlan($plan, $planned, $confirmation);
	}

}
