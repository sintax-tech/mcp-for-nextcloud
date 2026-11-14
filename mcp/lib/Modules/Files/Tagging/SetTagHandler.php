<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tagging;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Presentation\TaggingMessages;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use Throwable;

/**
 * The confirmed files_set_tag: the plan is read again, must match the plan_state the user approved, the files.tags
 * grant is read again, a file of someone else needs confirm_shared, and only then the one relation changes through
 * native DAV ({@see FileMetadataDav::relate()}). The receipt reads the file's tags back the way files_list_tags does.
 */
final class SetTagHandler {
	/**
	 * @param SetTagPlan $plans the plan, read now
	 * @param GrantPolicy $policy the write grant, checked again before the write
	 * @param FileMetadataDav $dav the native relation write and the read-back
	 * @param FilesAccessGuard $guard source of the MCP exclusion tag IDs for the read-back
	 */
	public function __construct(
		private SetTagPlan $plans,
		private GrantPolicy $policy,
		private FileMetadataDav $dav,
		private FilesAccessGuard $guard,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments with confirm: true
	 * @param string $uid authenticated actor
	 * @return array<string,mixed> the receipt, or the shared-write confirmation when confirm_shared is missing
	 * @throws PlanChanged when the file, the tag or the relation differ from the approved plan: nothing was written
	 * @throws ToolFailure when the call is refused before the write
	 * @throws PolicyUnavailable when the grant or the Files scope cannot be read now
	 */
	public function handle(array $arguments, string $uid): array {
		$built = $this->plans->build($uid, $arguments);
		PlanState::require($arguments);
		if (!PlanState::matches((string)$built['plan'][PlanState::ARGUMENT], $arguments)) {
			throw new PlanChanged($built['plan'] + ['warnings' => [['message' => TaggingMessages::planChanged()]]]);
		}
		// Revocation between the plan and the write wins: the grant is read again just before the sink.
		if (!$this->policy->granted($uid, 'files', 'tags')) {
			throw new ToolFailure(TaggingMessages::notAllowed());
		}
		if ($built['confirmation'] !== null && ($arguments['confirm_shared'] ?? false) !== true) {
			return $built['confirmation'];
		}
		$error = null;
		if (!$built['noop']) {
			try {
				$this->dav->relate($uid, $built['file_id'], $built['tag']['id'], $built['assigned']);
			} catch (ToolFailure) {
				// The native refusal of the tag or the file, rebuilt so it is the very refusal of a missing file.
				throw new ToolFailure(CommonMessages::notFound());
			} catch (Throwable) {
				$error = TaggingMessages::writeFailed();
			}
		}
		try {
			$tags = FileTagList::parse((string)$this->dav->tags($uid, $built['file_id'])->body, $this->guard->getHiddenTagIds());
			$now = in_array($built['tag']['id'], array_column($tags, 'id'), true);
		} catch (Throwable) {
			[$tags, $now] = [null, null];
		}
		return ['changed' => $error === null && !$built['noop'], 'path' => $built['path'], 'tag' => $built['tag'],
			'assigned' => $now, 'tags' => $tags] + ($error === null ? [] : ['error' => $error]);
	}
}
