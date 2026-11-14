<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Commenting;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Presentation\CommentingMessages;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Modules\Files\Reading\MultistatusDocument;
use OCA\Mcp\Modules\Files\Reading\VisibleNode;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCP\Files\Folder;
use Throwable;

/**
 * The plan of files_add_comment, read now: the item behind the Files gate, the exact text Nextcloud will store, who
 * the mentions will notify, the author's own read marker the comment will move, and who else sees it.
 *
 * The text is trimmed as the native setMessage does and kept within IComment::MAX_MESSAGE_LENGTH (1000 in v34.0.4 and
 * v35.0.1), so the plan shows exactly what will be stored. Nothing is written; the plan is signed with
 * {@see PlanState} so the confirmed call can tell whether what it showed still holds.
 */
final class AddCommentPlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'files_add_comment';
	/** Native message limit (OCP\Comments\IComment::MAX_MESSAGE_LENGTH). */
	public const MAX_LENGTH = 1000;

	/**
	 * @param FilesCallContext $context actor-root admission and node-error mapping
	 * @param FilesAccessGuard $guard Files gate of the path
	 * @param FileMetadataDav $dav the author's read marker, through native DAV
	 * @param CommentMentions $mentions the mentions the text carries and who they notify
	 * @param NodeAccessInfo $access scope and owner of the item for the actor
	 * @param SharedWriteGuard $shared the shared-write confirmation every Files write answers with
	 * @param PlanState $states the instance's HMAC of what the plan showed
	 */
	public function __construct(
		private FilesCallContext $context,
		private FilesAccessGuard $guard,
		private FileMetadataDav $dav,
		private CommentMentions $mentions,
		private NodeAccessInfo $access,
		private SharedWriteGuard $shared,
		private PlanState $states,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string,mixed> $arguments validated arguments: path and text
	 * @return array{plan:array<string,mixed>,file_id:int,text:string,path:string,confirmation:?array<string,mixed>}
	 *     the signed plan, the exact values of the write, and the shared-write confirmation when the item is not personal
	 * @throws ToolFailure for an empty or too long text, a refused mention, or an unreachable item (as a missing one)
	 */
	public function build(string $uid, array $arguments): array {
		$path = PathGuard::normalize((string)$arguments['path']);
		$text = trim((string)$arguments['text']);
		if ($text === '') {
			throw new ToolFailure(CommentingMessages::emptyText());
		}
		if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
			throw new ToolFailure(CommentingMessages::tooLong(self::MAX_LENGTH));
		}
		$notified = $this->mentions->notified($text, $uid);
		return $this->context->run($uid, function (Folder $root) use ($uid, $path, $text, $notified): array {
			$node = VisibleNode::get($root, $path, $this->guard);
			$info = $this->access->describe($node, $uid);
			$fileId = (int)$node->getId();
			$marker = $this->marker($uid, $fileId);
			$confirmation = $info['scope'] === NodeAccessInfo::PERSONAL ? null : $this->shared->request($info, $path);
			$plan = ['action' => self::TOOL, 'path' => $path, 'isDir' => NodeAccess::isFolder($node), 'text' => $text,
				'notifies' => $notified, 'read_marker' => ['now' => $marker, 'after' => 'now'],
				'access' => $info, 'shared' => $confirmation === null ? [] : [$confirmation], 'requiresSharedConfirmation' => $confirmation !== null,
				'recoverable' => false, 'message' => CommonMessages::planNothingChanged()];
			$shown = ['file' => $fileId, 'path' => $path, 'text' => $text, 'notifies' => array_column($notified, 'id'), 'scope' => $info['scope']];
			return ['plan' => $plan + [PlanState::ARGUMENT => $this->states->of(self::TOOL, $uid, $shown)], 'file_id' => $fileId,
				'text' => $text, 'path' => $path, 'confirmation' => $confirmation];
		});
	}

	/**
	 * @param string $uid authenticated actor
	 * @param int $fileId admitted item
	 * @return string|null the actor's read marker in ISO 8601 UTC, null when there is none or it cannot be read
	 * @throws ToolFailure when DAV does not find the item for the actor (the not-found refusal)
	 */
	private function marker(string $uid, int $fileId): ?string {
		try {
			$answer = $this->dav->readMarker($uid, $fileId);
		} catch (ToolFailure) {
			// The native refusal of the item, rebuilt so it is the very refusal of a missing one.
			throw new ToolFailure(CommonMessages::notFound());
		}
		try {
			$value = trim((string)MultistatusDocument::xpath((string)$answer->body)->query('//oc:readMarker')?->item(0)?->textContent);
			$date = $value === '' ? false : \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
			return $date === false ? null : $date->format('Y-m-d\TH:i:s\Z');
		} catch (Throwable) {
			return null;
		}
	}
}
