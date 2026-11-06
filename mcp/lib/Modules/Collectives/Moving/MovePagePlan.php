<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Moving;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Integration\PageProjection;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;

/**
 * The plan of `collectives_move`, read now. A page moves only inside its own collective, under a page of the admitted
 * tree; the landing page never moves, nor does a page under itself or a subpage. An omitted parent keeps the current
 * one (Collectives reads 0 the same way), and `index`, 0 by default, places the page among the subpages of a NEW
 * parent only: Collectives does not reorder when the parent stays, so asking for it is refused. Same parent and same
 * title is a plan that changes nothing.
 */
class MovePagePlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'collectives_move';

	/**
	 * @param CollectivesGateway $gateway native Collectives
	 * @param PageTreeLoader $trees the page tree, read now
	 * @param PlanConfirmation $confirmation plan states
	 */
	public function __construct(
		private CollectivesGateway $gateway,
		private PageTreeLoader $trees,
		private PlanConfirmation $confirmation,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_move arguments
	 * @return array{plan:array<string, mixed>, page_id:int, from:int, to:int, title:string, index:int, changes:bool, moves:bool} the signed plan and the move it stands for
	 * @throws ToolFailure when a page is not admitted, the actor may not edit, or the move is refused
	 */
	public function build(string $uid, array $arguments): array {
		$cid = (int)$arguments['collective_id'];
		$this->gateway->assertEditable($cid, $uid, 'move');
		$tree = $this->trees->load($cid, $uid, 'move');
		$page = $tree->page((int)$arguments['page_id']);
		$id = (int)$page['id'];
		if ($page['parent_id'] === 0) {
			throw new ToolFailure(CollectivesMessages::rootNotMovable());
		}
		$from = (int)$page['parent_id'];
		$target = $tree->page(isset($arguments['parent_id']) ? (int)$arguments['parent_id'] : $from);
		$to = (int)$target['id'];
		if ($to === $id || in_array($to, $tree->descendants($id), true)) {
			throw new ToolFailure(CollectivesMessages::cycle());
		}
		$moves = $to !== $from;
		if (isset($arguments['index']) && !$moves) {
			throw new ToolFailure(CollectivesMessages::indexNeedsParent());
		}
		$title = isset($arguments['title']) ? (string)$arguments['title'] : (string)$page['title'];
		$changes = $moves || $title !== $page['title'];
		$index = (int)($arguments['index'] ?? 0);
		$siblings = $tree->children($to);
		$leaf = $moves && $target['file_name'] !== 'Readme.md';
		$notices = $changes ? array_values(array_filter([$leaf ? CollectivesMessages::parentBecomesFolder() : null,
			CollectivesMessages::linksByPath(), CollectivesMessages::membersNotified()])) : [CollectivesMessages::nothingChanges()];
		$plan = ['action' => self::TOOL, 'collective_id' => $cid, 'page' => PageProjection::visible($page),
			'from' => ['parent_id' => $from, 'parent_title' => $tree->page($from)['title'], 'title' => $page['title'], 'position' => $tree->position($from, $id)],
			'to' => ['parent_id' => $to, 'parent_title' => $target['title'], 'title' => $title, 'position' => $moves ? min($index, count($siblings)) : $tree->position($from, $id)],
			'changes' => $changes, 'parent_becomes_folder' => $leaf, 'recoverable' => true, 'notices' => $notices];
		$shown = ['collective_id' => $cid, 'page_id' => $id, 'from' => $from, 'to' => $to, 'title' => $title, 'old_title' => $page['title'],
			'index' => $index, 'siblings' => $siblings, 'descendants' => $tree->descendants($id), 'leaf' => $leaf];
		return ['plan' => $this->confirmation->sign(self::TOOL, $uid, $plan, $shown), 'page_id' => $id, 'from' => $from, 'to' => $to,
			'title' => $title, 'index' => $index, 'changes' => $changes, 'moves' => $moves];
	}
}
