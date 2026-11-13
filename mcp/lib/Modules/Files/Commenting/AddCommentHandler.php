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
 * the POST. The receipt reads the newest comments back the way files_read_comments does and finds the actor's own.
 * Nothing here edits or deletes a comment.
 */
final class AddCommentHandler {
	/** Newest comments read back for the receipt. */
	private const RECEIPT_PAGE = 20;

	/**
	 * @param AddCommentPlan $plans the plan, read now
	 * @param GrantPolicy $policy the write grant, checked again before the write
	 * @param FileMetadataDav $dav the native comment write and the read-back
	 * @param CommentFeed $feed reads the native comments page of the receipt
	 */
	public function __construct(
		private AddCommentPlan $plans,
		private GrantPolicy $policy,
		private FileMetadataDav $dav,
		private CommentFeed $feed,
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
		$error = null;
		try {
			$this->dav->comment($uid, $built['file_id'], $built['text']);
		} catch (ToolFailure) {
			// The native refusal of the item, rebuilt so it is the very refusal of a missing one.
			throw new ToolFailure(CommonMessages::notFound());
		} catch (PolicyUnavailable $unavailable) {
			throw $unavailable;
		} catch (Throwable) {
			$error = CommentingMessages::writeFailed();
		}
		return ['added' => $error === null, 'path' => $built['path'], 'comment' => $this->receipt($uid, $built['file_id'], $built['text'])]
			+ ($error === null ? [] : ['error' => $error]);
	}

	/**
	 * @param string $uid the author
	 * @param int $fileId the commented item
	 * @param string $text the exact stored text
	 * @return array<string,mixed>|null the actor's newest comment with that text, null when it cannot be read back
	 */
	private function receipt(string $uid, int $fileId, string $text): ?array {
		try {
			foreach ($this->feed->comments((string)$this->dav->comments($uid, $fileId, self::RECEIPT_PAGE, 0)->body) as $comment) {
				if ($comment['author']['type'] === 'users' && $comment['author']['id'] === $uid && $comment['text'] === $text) {
					return $comment;
				}
			}
		} catch (Throwable) {
		}
		return null;
	}
}
