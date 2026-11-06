<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Moving;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Writing\PageReceipt;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use Throwable;

/**
 * The confirmed `collectives_move`: the plan is rebuilt from a fresh read and must still be the approved one, the
 * native edit permission is read again, and the page service moves the page once, which renames its file, updates
 * its slug and both subpage orders. The receipt reads the tree again: parent, title, position, the removal from the
 * old parent's order and, for a rename, the new slug.
 */
class MovePageHandler {
	/**
	 * @param MovePagePlan $plans the plan, read now
	 * @param PlanConfirmation $confirmation plan states
	 * @param CollectivesGateway $gateway native Collectives
	 * @param PageTreeLoader $trees the page tree, read again for the receipt
	 */
	public function __construct(
		private MovePagePlan $plans,
		private PlanConfirmation $confirmation,
		private CollectivesGateway $gateway,
		private PageTreeLoader $trees,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_move arguments
	 * @return array<string, mixed> the receipt
	 * @throws PlanChanged when the state changed since the plan: nothing was moved
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function handle(string $uid, array $arguments): array {
		$built = $this->plans->build($uid, $arguments);
		$this->confirmation->confirm($arguments, $built['plan']);
		if (!$built['changes']) {
			return ['status' => 'not_attempted', 'nativeCallAttempted' => false, 'reason' => 'no_change', 'page' => $built['plan']['page']];
		}
		$cid = (int)$arguments['collective_id'];
		$this->gateway->assertEditable($cid, $uid, 'move');
		$error = null;
		try {
			$this->gateway->move($cid, $built['page_id'], $built['moves'] ? $built['to'] : null,
				$built['title'] !== $built['plan']['from']['title'] ? $built['title'] : null, $built['index'], $uid);
		} catch (Throwable $e) {
			$error = CollectivesGateway::failure($e)->getMessage();
		}
		// A new parent asks for the position there and the removal from the old order; a new title asks for a new slug.
		$renamed = $built['title'] !== $built['plan']['from']['title'];
		$requested = ['parent_id' => $built['to'], 'title' => $built['title']]
			+ ($built['moves'] ? ['position' => $built['plan']['to']['position'], 'in_old_order' => false] : [])
			+ ($renamed ? ['slug_changed' => true] : []);
		$extra = $error === null ? [] : ['native_error' => $error];
		try {
			$tree = $this->trees->load($cid, $uid, 'move');
		} catch (ToolFailure) {
			return PageReceipt::of($requested, null, $extra);
		}
		if (!$tree->has($built['page_id'])) {
			return PageReceipt::of($requested, null, $extra);
		}
		$page = $tree->page($built['page_id']);
		$order = $tree->has($built['to']) ? $tree->page($built['to'])['subpage_order'] : [];
		$position = array_search($built['page_id'], $order, true);
		$old = $tree->has($built['from']) ? in_array($built['page_id'], $tree->page($built['from'])['subpage_order'], true) : null;
		$observed = ['parent_id' => $page['parent_id'], 'title' => $page['title'], 'position' => $position === false ? null : $position,
			'in_old_order' => $old, 'slug_changed' => $page['slug'] !== $built['plan']['page']['slug']];
		return PageReceipt::of($requested, $observed, ['page' => $tree->visible($page)] + $extra);
	}
}
