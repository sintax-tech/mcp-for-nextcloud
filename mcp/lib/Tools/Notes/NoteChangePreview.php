<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/**
 * The plan of an append or a replace, the same one whether a call without `confirm` asks for it or a confirmed call
 * found the note in another state ({@see NoteChangeConfirmation}). It refuses what the write refuses, so nobody is
 * asked to approve a change the server would not make, and it carries the etag and the {@see PlanState} to confirm with.
 */
final class NoteChangePreview {
	/**
	 * @param NotesRepository $notes note lookup and reads
	 * @param NodeAccessInfo $accessInfo who owns the note and who else it reaches
	 * @param PlanState $states the instance's plan states
	 * @param LockAwareWrite|null $locks the files_lock check, null when the app is not there
	 * @param FileTypeGuard|null $types file-name policy for this plan; null only outside production
	 */
	public function __construct(
		private NotesRepository $notes,
		private NodeAccessInfo $accessInfo,
		private PlanState $states,
		private ?LockAwareWrite $locks = null,
		private ?FileTypeGuard $types = null,
	) {
	}

	/**
	 * @param Folder|null $root notes folder
	 * @param string $userId authenticated user
	 * @param array<string, mixed> $arguments validated notes_edit arguments; without `etag` the plan describes the note as it is now
	 * @param string $mode NoteChange::APPEND or NoteChange::REPLACE
	 * @param bool $changed whether a confirmed call found this state instead of the approved one: a warning says so first
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when the note is missing, read-only or stale, the passage is absent or not unique, the result is
	 *   over the limit or the new title is refused
	 * @throws FileTypeDenied when the active policy refuses the current name or the renamed basename
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 */
	public function plan(?Folder $root, string $userId, array $arguments, string $mode, bool $changed = false): array {
		$note = $this->notes->find($root, $arguments['id']);
		NoteChange::assertCurrent($note, $arguments['etag'] ?? null);
		if (!$note->isUpdateable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		$current = $this->notes->raw($note);
		$updated = NoteChange::apply($mode, $current, $arguments);
		$title = (new NoteChangeTitle($this->notes))->after($note, isset($arguments['title']) ? (string)$arguments['title'] : null);
		$names = [$note->getName()];
		if (isset($arguments['title'])) {
			$renamed = (new NoteChangeTitle($this->notes))->target($note, (string)$arguments['title']);
			if ($renamed !== null) {
				$names[] = NoteNames::leaf($renamed);
			}
		}
		(new NoteTypeBoundary($this->types))->assertProjected($names);
		$access = $this->accessInfo->describe($note, $userId);
		$lock = $this->locks?->planNotice($note, $userId) ?? [];
		$warnings = array_merge($changed ? [['message' => NotesMessages::notePlanChanged()]] : [], $lock['warnings'] ?? []);
		return NoteChangePlan::of($mode, $this->notes->info($note, $root), $title, $current, $updated, $arguments) + [
			PlanState::ARGUMENT => NoteChange::state($this->states, $userId, (int)$note->getId(), $current, $mode, $arguments),
			'access' => $access,
			'shared' => $access['scope'] === NodeAccessInfo::PERSONAL ? [] : [$access],
			'recoverable' => true,
		] + ($warnings === [] ? [] : ['warnings' => $warnings]) + $lock;
	}
}
