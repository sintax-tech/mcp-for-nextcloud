<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Locating\PageTreeLoader;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/**
 * The plan of `collectives_create`, read now: the native edit permission (fresh), the parent as an admitted page of
 * the tree (0 is the landing page), whether that parent is a leaf Collectives turns into a folder, and the subpages it
 * has. The preview shows it; the confirmed call rebuilds it and writes only when it is still the approved one.
 */
class CreatePagePlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'collectives_create';

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
	 * @param array<string, mixed> $arguments validated collectives_create arguments
	 * @return array{plan:array<string, mixed>, parent_id:int, children:list<int>} the signed plan, the real parent id and its subpages now
	 * @throws ToolFailure when the collective or the parent is not admitted, or the actor may not edit
	 */
	public function build(string $uid, array $arguments): array {
		$cid = (int)$arguments['collective_id'];
		$this->gateway->assertEditable($cid, $uid, 'create');
		$tree = $this->trees->load($cid, $uid, 'create');
		$parent = (int)$arguments['parent_id'] === 0
			? ($tree->landing() ?? throw new ToolFailure(CommonMessages::notFound()))
			: $tree->page((int)$arguments['parent_id']);
		$title = (string)$arguments['title'];
		$children = $tree->children((int)$parent['id']);
		$leaf = $parent['file_name'] !== 'Readme.md';
		$taken = array_filter($children, static fn (int $id): bool => strcasecmp((string)$tree->page($id)['title'], $title) === 0) !== [];
		$notices = array_values(array_filter([
			$leaf ? CollectivesMessages::parentBecomesFolder() : null,
			$taken ? CollectivesMessages::titleMayChange() : null,
			CollectivesMessages::membersNotified(),
		]));
		$plan = ['action' => self::TOOL, 'collective_id' => $cid, 'parent' => $tree->visible($parent), 'page_title' => $title,
			'parent_becomes_folder' => $leaf, 'title_taken' => $taken, 'content' => '', 'recoverable' => true, 'notices' => $notices];
		$shown = ['collective_id' => $cid, 'parent_id' => (int)$parent['id'], 'title' => $title, 'leaf' => $leaf, 'children' => $children];
		return ['plan' => $this->confirmation->sign(self::TOOL, $uid, $plan, $shown), 'parent_id' => (int)$parent['id'], 'children' => $children];
	}
}
