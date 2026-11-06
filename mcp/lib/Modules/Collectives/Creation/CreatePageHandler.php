<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Creation;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Writing\PageReceipt;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use Throwable;

/**
 * The confirmed `collectives_create`: the plan is rebuilt from a fresh read and must still be the approved one, then
 * the page service creates the page once, without content. The receipt comes from reading the tree again: the page,
 * its parent and its place in the parent's subpage order. A failure after the attempt is reported, never retried, and
 * without the id the native call answered the receipt stays uncertain.
 */
class CreatePageHandler {
	/**
	 * @param CreatePagePlan $plans the plan, read now
	 * @param PlanConfirmation $confirmation plan states
	 * @param CollectivesGateway $gateway native Collectives
	 * @param PageTreeLoader $trees the page tree, read again for the receipt
	 */
	public function __construct(
		private CreatePagePlan $plans,
		private PlanConfirmation $confirmation,
		private CollectivesGateway $gateway,
		private PageTreeLoader $trees,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_create arguments
	 * @return array<string, mixed> the receipt
	 * @throws PlanChanged when the state changed since the plan: nothing was written
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function handle(string $uid, array $arguments): array {
		$built = $this->plans->build($uid, $arguments);
		$this->confirmation->confirm($arguments, $built['plan']);
		$cid = (int)$arguments['collective_id'];
		$id = null;
		$error = null;
		try {
			// The id the plan resolved: Collectives turns 0 into the landing page for the file, not for the subpage order.
			$id = $this->gateway->create($cid, $built['parent_id'], (string)$arguments['title'], $uid);
		} catch (Throwable $e) {
			$error = CollectivesGateway::failure($e)->getMessage();
		}
		try {
			$tree = $this->trees->load($cid, $uid, 'create');
		} catch (ToolFailure) {
			return PageReceipt::of(['parent_id' => $built['parent_id'], 'in_parent_order' => true], null, $error === null ? [] : ['native_error' => $error]);
		}
		// Without the id Collectives answered, a new subpage of the parent may be somebody else's: never claim it.
		$page = $id !== null && $tree->has($id) ? $tree->page($id) : null;
		$observed = $page === null ? null : ['parent_id' => $page['parent_id'],
			'in_parent_order' => in_array($id, $tree->has($built['parent_id']) ? $tree->page($built['parent_id'])['subpage_order'] : [], true)];
		return PageReceipt::of(['parent_id' => $built['parent_id'], 'in_parent_order' => true], $observed,
			['page' => $page === null ? null : $tree->visible($page)] + ($error === null ? [] : ['native_error' => $error]));
	}
}
