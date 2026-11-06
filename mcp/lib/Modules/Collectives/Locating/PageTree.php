<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Locating;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\Files\Folder;

/**
 * The pages of one collective the actor may see, as read at one moment: the landing page (parent 0) and every page
 * below it, ordered the way Collectives shows them (each parent's subpage order, then the rest by title). A page
 * outside this tree does not exist for the module, whatever its id.
 */
final class PageTree {
	/** @var array<int, array<string, mixed>> pages by id */
	private array $pages = [];

	/**
	 * @param int $collectiveId the collective
	 * @param Folder $folder the actor's folder of the collective
	 * @param list<array<string, mixed>> $pages admitted pages of {@see \OCA\Mcp\Modules\Collectives\Integration\PageProjection::page()}
	 */
	public function __construct(public readonly int $collectiveId, public readonly Folder $folder, array $pages) {
		foreach ($pages as $page) {
			$this->pages[(int)$page['id']] = $page;
		}
	}

	/**
	 * @param int $id page id
	 * @return array<string, mixed> the page
	 * @throws ToolFailure when the tree has no such page
	 */
	public function page(int $id): array {
		return $this->pages[$id] ?? throw new ToolFailure(CommonMessages::notFound());
	}

	/**
	 * @param int $id page id
	 * @return bool whether the tree has it
	 */
	public function has(int $id): bool {
		return isset($this->pages[$id]);
	}

	/** @return array<string, mixed>|null the landing page, null when the actor cannot see it */
	public function landing(): ?array {
		foreach ($this->pages as $page) {
			if ($page['parent_id'] === 0) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * @param int $parentId parent page id
	 * @return list<int> its direct subpages in the order Collectives shows them
	 */
	public function children(int $parentId): array {
		$children = array_filter($this->pages, static fn (array $page): bool => $page['parent_id'] === $parentId && $page['id'] !== $parentId);
		$order = isset($this->pages[$parentId]) ? $this->pages[$parentId]['subpage_order'] : [];
		uasort($children, static function (array $a, array $b) use ($order): int {
			$ia = array_search($a['id'], $order, true);
			$ib = array_search($b['id'], $order, true);
			if ($ia !== false || $ib !== false) {
				return $ia === false ? 1 : ($ib === false ? -1 : $ia <=> $ib);
			}
			return [strcasecmp($a['title'], $b['title']), $a['id']] <=> [0, $b['id']];
		});
		return array_map('intval', array_keys($children));
	}

	/**
	 * @param int $id page id
	 * @return list<int> every page below it, at any depth, depth first
	 */
	public function descendants(int $id): array {
		$found = [];
		foreach ($this->children($id) as $child) {
			$found[] = $child;
			array_push($found, ...$this->descendants($child));
		}
		return $found;
	}

	/** @return list<array<string, mixed>> every page, landing page first, depth first */
	public function ordered(): array {
		$landing = $this->landing();
		if ($landing === null) {
			return array_values($this->pages);
		}
		$ids = [$landing['id'], ...$this->descendants($landing['id'])];
		$orphans = array_diff(array_keys($this->pages), $ids);
		return array_map(fn (int $id): array => $this->pages[$id], [...$ids, ...array_map('intval', $orphans)]);
	}

	/**
	 * @param int $parentId parent page id
	 * @param int $id page id
	 * @return int|null position of the page among the parent's subpages, null when it is not one of them
	 */
	public function position(int $parentId, int $id): ?int {
		$index = array_search($id, $this->children($parentId), true);
		return $index === false ? null : $index;
	}
}
