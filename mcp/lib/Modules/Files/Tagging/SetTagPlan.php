<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tagging;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCP\Files\Folder;

/**
 * The plan of files_set_tag, read now: the file behind the Files gate, the one tag the call names among those the
 * native catalog lets the actor see, whether the file has it already (the TG-1 read) and who else the change reaches.
 *
 * A file the actor cannot change (no UPDATE, the native write condition) is refused like a missing one, before the
 * tag is even looked up. Nothing is written; the plan is signed with {@see PlanState} so the confirmed call can tell
 * whether what it showed still holds.
 */
final class SetTagPlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'files_set_tag';

	/**
	 * @param FilesCallContext $context actor-root admission and node-error mapping
	 * @param FilesAccessGuard $guard Files gate of the path and source of the MCP exclusion tag IDs
	 * @param FileMetadataDav $dav native tag catalog and tag relation of the file
	 * @param NodeAccessInfo $access scope, owner and permissions of the file for the actor
	 * @param SharedWriteGuard $shared the shared-write confirmation every Files write answers with
	 * @param PlanState $states the instance's HMAC of what the plan showed
	 */
	public function __construct(
		private FilesCallContext $context,
		private FilesAccessGuard $guard,
		private FileMetadataDav $dav,
		private NodeAccessInfo $access,
		private SharedWriteGuard $shared,
		private PlanState $states,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string,mixed> $arguments validated arguments
	 * @return array{plan:array<string,mixed>,file_id:int,tag:array{id:string,name:string},assigned:bool,noop:bool,path:string,confirmation:?array<string,mixed>}
	 *     the signed plan, the exact native values, and the shared-write confirmation when the file is not personal
	 * @throws ArgumentValidationException when both or neither of tag_id and tag are given
	 * @throws ToolFailure the not-found refusal for an unreachable or unchangeable file and an unusable tag
	 */
	public function build(string $uid, array $arguments): array {
		$path = PathGuard::normalize((string)$arguments['path']);
		$assigned = $arguments['assigned'] === true;
		$requested = TagChoice::requested($arguments);
		return $this->context->run($uid, function (Folder $root) use ($uid, $requested, $path, $assigned): array {
			$node = TagTarget::node($root, $path, $this->guard);
			$info = $this->access->describe($node, $uid);
			if ($info['permissions']['update'] !== true) {
				throw new ToolFailure(CommonMessages::notFound());
			}
			$hidden = $this->guard->getHiddenTagIds();
			$tag = TagChoice::pick($requested, FileTagList::parse((string)$this->dav->catalog($uid)->body, $hidden));
			$fileId = (int)$node->getId();
			$current = FileTagList::parse((string)$this->dav->tags($uid, $fileId)->body, $hidden);
			$now = in_array($tag['id'], array_column($current, 'id'), true);
			$confirmation = $info['scope'] === NodeAccessInfo::PERSONAL ? null : $this->shared->request($info, $path);
			$plan = ['action' => self::TOOL, 'path' => $path, 'isDir' => NodeAccess::isFolder($node), 'tag' => $tag,
				'change' => $assigned ? 'assign' : 'remove', 'assigned_now' => $now, 'nothing_to_do' => $now === $assigned,
				'access' => $info, 'shared' => $confirmation === null ? [] : [$confirmation], 'requiresSharedConfirmation' => $confirmation !== null,
				'recoverable' => true, 'message' => CommonMessages::planNothingChanged()];
			$shown = ['file' => $fileId, 'path' => $path, 'tag' => $tag, 'assigned' => $assigned, 'now' => $now, 'scope' => $info['scope']];
			return ['plan' => $plan + [PlanState::ARGUMENT => $this->states->of(self::TOOL, $uid, $shown)], 'file_id' => $fileId, 'tag' => $tag,
				'assigned' => $assigned, 'noop' => $now === $assigned, 'path' => $path, 'confirmation' => $confirmation];
		});
	}
}
