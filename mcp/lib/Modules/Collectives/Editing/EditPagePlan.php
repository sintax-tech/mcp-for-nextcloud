<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Editing;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesMarkdownPolicy;
use OCA\Mcp\Modules\Collectives\Integration\PageProjection;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\Text\UnifiedDiff;
use OCP\Files\File;

/**
 * The plan of `collectives_edit`, read now: the native edit permission (fresh), the page as an admitted `.md` file of
 * the tree, its content and etag, the Markdown the change leaves (checked by {@see CollectivesMarkdownPolicy}, not by
 * the Files allowlist) and a diff of at most {@see self::MAX_DIFF} bytes. A lock is shown as a warning.
 */
class EditPagePlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'collectives_edit';
	/** Largest diff a plan shows, in bytes; the plan state covers the whole change either way. */
	public const MAX_DIFF = 65536;

	/**
	 * @param CollectivesGateway $gateway native Collectives
	 * @param PageTreeLoader $trees the page tree and file, read now
	 * @param CollectivesMarkdownPolicy $markdown the file-type rule of these tools
	 * @param LockAwareWrite $locks files_lock state of the page
	 * @param PlanConfirmation $confirmation plan states
	 */
	public function __construct(
		private CollectivesGateway $gateway,
		private PageTreeLoader $trees,
		private CollectivesMarkdownPolicy $markdown,
		private LockAwareWrite $locks,
		private PlanConfirmation $confirmation,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_edit arguments
	 * @return array{plan:array<string, mixed>, file:File, updated:string, etag:string, changed:bool, page:array<string, mixed>} the signed plan and what the write needs
	 * @throws ToolFailure when the page is not admitted, the actor may not edit, or the change is refused
	 */
	public function build(string $uid, array $arguments): array {
		$cid = (int)$arguments['collective_id'];
		$mode = PageChange::mode($arguments);
		$this->gateway->assertEditable($cid, $uid, 'edit');
		$tree = $this->trees->load($cid, $uid, 'edit');
		$page = $tree->page((int)$arguments['page_id']);
		$file = $this->trees->file($tree, (int)$page['id'], $uid, 'edit');
		if ($file->getSize() > CollectivesMarkdownPolicy::MAX_BYTES) {
			throw new ToolFailure(CollectivesMessages::pageTooLarge(CollectivesMarkdownPolicy::MAX_BYTES));
		}
		$current = (string)$file->getContent();
		$updated = PageChange::apply($mode, $current, $arguments);
		$this->markdown->assertWritable($file, $updated);
		$etag = trim((string)$file->getEtag(), '"');
		$diff = UnifiedDiff::between($current, $updated);
		$plan = ['action' => self::TOOL, 'collective_id' => $cid, 'page' => PageProjection::visible($page), 'mode' => $mode, 'etag' => $etag,
			'changed' => $updated !== $current, 'bytes_before' => strlen($current), 'bytes_after' => strlen($updated),
			'diff' => strlen($diff) <= self::MAX_DIFF ? $diff : null, 'diff_omitted' => strlen($diff) > self::MAX_DIFF, 'recoverable' => true,
			'notices' => [CollectivesMessages::noTextCoordination(), CollectivesMessages::membersNotified()]] + $this->locks->planNotice($file, $uid);
		$shown = ['collective_id' => $cid, 'page_id' => (int)$page['id'], 'mode' => $mode, 'etag' => $etag,
			'before' => hash('sha256', $current), 'after' => hash('sha256', $updated)];
		return ['plan' => $this->confirmation->sign(self::TOOL, $uid, $plan, $shown), 'file' => $file, 'updated' => $updated,
			'etag' => $etag, 'changed' => $updated !== $current, 'page' => $page];
	}
}
