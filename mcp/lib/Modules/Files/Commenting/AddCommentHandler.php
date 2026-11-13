<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Commenting;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Presentation\CommentingMessages;
use OCA\Mcp\Modules\Files\Reading\CommentFeed;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use Throwable;

/**
 * The confirmed files_add_comment: the plan is read again, must match the plan_state the user approved, the
 * files.comments grant is read again, an item outside the personal folder needs confirm_shared, and only then one
 * comment is added through native DAV ({@see FileMetadataDav::comment()}), which admits the item again right before
 * the POST. The mentions are resolved again just before the POST too, through the people search. The receipt reads
 * back exactly the comment the POST created, by the ID of its Content-Location; when the write failed, or the ID is
 * unknown, it names no comment. Nothing here edits or deletes a comment.
 */
final class AddCommentHandler {
	/**
	 * @param AddCommentPlan $plans the plan, read now
	 * @param GrantPolicy $policy the write grant, checked again before the write
	 * @param FileMetadataDav $dav the native comment write and the read-back
	 * @param CommentFeed $feed reads the native comment of the receipt
	 * @param CommentMentions $mentions the mentions, resolved again right before the POST
	 */
	public function __construct(
		private AddCommentPlan $plans,
		private GrantPolicy $policy,
		private FileMetadataDav $dav,
		private CommentFeed $feed,
		private CommentMentions $mentions,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments with confirm: true
	 * @param string $uid authenticated actor
	 * @return array<string,mixed> the receipt, or the shared-write confirmation when confirm_shared is missing
	 * @throws PlanChanged when the item, the text or the mentions differ from the approved plan: nothing was written
	 * @throws ToolFailure when the call is refused before the write
	 * @throws PolicyUnavailable when the grant or the Files scope cannot be read now
	 */
	public function handle(array $arguments, string $uid): array {
		$built = $this->plans->build($uid, $arguments);
		PlanState::require($arguments);
		if (!PlanState::matches((string)$built['plan'][PlanState::ARGUMENT], $arguments)) {
			throw new PlanChanged($built['plan'] + ['warnings' => [['message' => CommentingMessages::planChanged()]]]);
		}
		// Revocation between the plan and the write wins: the grant is read again just before the sink.
		if (!$this->policy->granted($uid, 'files', 'comments')) {
			throw new ToolFailure(CommentingMessages::notAllowed());
		}
		if ($built['confirmation'] !== null && ($arguments['confirm_shared'] ?? false) !== true) {
			return $built['confirmation'];
		}
		// Who the comment notifies is read again just before the POST: an account the author can no longer find is
		// refused, and a different set of notified accounts is a changed plan.
		if (array_column($this->mentions->notified($built['text'], $uid), 'id') !== array_column($built['plan']['notifies'], 'id')) {
			throw new PlanChanged($built['plan'] + ['warnings' => [['message' => CommentingMessages::planChanged()]]]);
		}
		[$error, $id] = [null, null];
		try {
			$id = $this->dav->comment($uid, $built['file_id'], $built['text']);
		} catch (ToolFailure) {
			// The native refusal of the item, rebuilt so it is the very refusal of a missing one.
			throw new ToolFailure(CommonMessages::notFound());
		} catch (PolicyUnavailable $unavailable) {
			throw $unavailable;
		} catch (Throwable) {
			$error = CommentingMessages::writeFailed();
		}
		$comment = $error === null && $id !== null ? $this->receipt($uid, $built['file_id'], $id) : null;
		return ['added' => $error === null, 'path' => $built['path'], 'comment' => $comment] + ($error === null ? [] : ['error' => $error]);
	}

	/**
	 * @param string $uid the author
	 * @param int $fileId the commented item
	 * @param string $id the ID of the comment this call created
	 * @return array<string,mixed>|null that very comment as files_read_comments shows it, null when it cannot be read back
	 */
	private function receipt(string $uid, int $fileId, string $id): ?array {
		try {
			$rows = $this->feed->comments((string)$this->dav->commentById($uid, $fileId, $id)->body);
			return count($rows) === 1 && $rows[0]['id'] === $id ? $rows[0] : null;
		} catch (Throwable) {
			return null;
		}
	}
}
