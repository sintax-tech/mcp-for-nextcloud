<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Editing;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Writing\PageReceipt;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCP\Files\File;
use Throwable;

/**
 * The confirmed `collectives_edit`: the plan is rebuilt from a fresh read, the etag and the plan state must still be
 * the approved ones, the native edit permission is read again right before the write, and the `.md` is written once
 * through the user's mount under the lock check, so Collectives' own listener updates links, author and notifications.
 * The receipt compares the Markdown read back with the one asked for; there is no compare-and-swap with an open editor.
 */
class EditPageHandler {
	/**
	 * @param EditPagePlan $plans the plan, read now
	 * @param PlanConfirmation $confirmation plan states
	 * @param CollectivesGateway $gateway native edit permission, read again
	 * @param LockAwareWrite $locks the lock check around the write
	 * @param PageTreeLoader $trees the page, read again for the receipt
	 */
	public function __construct(
		private EditPagePlan $plans,
		private PlanConfirmation $confirmation,
		private CollectivesGateway $gateway,
		private LockAwareWrite $locks,
		private PageTreeLoader $trees,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_edit arguments
	 * @return array<string, mixed> the receipt
	 * @throws PlanChanged when the content changed since the plan under the same etag: nothing was written
	 * @throws ToolFailure when the etag is missing or stale, or the call is refused before the attempt
	 */
	public function handle(string $uid, array $arguments): array {
		$etag = PageChange::etag($arguments);
		$built = $this->plans->build($uid, $arguments);
		if ($etag !== $built['etag']) {
			throw new ToolFailure(CollectivesMessages::changedSinceRead($built['etag']));
		}
		$this->confirmation->confirm($arguments, $built['plan']);
		$cid = (int)$arguments['collective_id'];
		$requested = ['content_sha256' => hash('sha256', $built['updated'])];
		if (!$built['changed']) {
			return ['status' => 'not_attempted', 'nativeCallAttempted' => false, 'reason' => 'no_change', 'page' => $built['plan']['page']];
		}
		$this->gateway->assertEditable($cid, $uid, 'edit');
		$file = $built['file'];
		$error = null;
		$this->locks->assertWritable($file, $uid);
		try {
			$this->locks->capture(static fn () => $file->putContent($built['updated']), $file, $uid);
		} catch (Throwable $e) {
			$error = CollectivesGateway::failure($e)->getMessage();
		}
		return PageReceipt::of($requested, $this->readBack($cid, (int)$built['page']['id'], $uid), ($error === null ? [] : ['native_error' => $error]));
	}

	/**
	 * @param int $cid collective id
	 * @param int $pageId page id
	 * @param string $uid authenticated actor
	 * @return array{content_sha256:string, etag:string}|null what a fresh, authorized read shows; null when it cannot
	 */
	private function readBack(int $cid, int $pageId, string $uid): ?array {
		try {
			$tree = $this->trees->load($cid, $uid, 'edit');
			$file = $this->trees->file($tree, $pageId, $uid, 'edit');
			return $file instanceof File ? ['content_sha256' => hash('sha256', (string)$file->getContent()), 'etag' => trim((string)$file->getEtag(), '"')] : null;
		} catch (ToolFailure) {
			return null;
		}
	}
}
