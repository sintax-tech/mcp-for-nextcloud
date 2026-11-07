<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;

/**
 * The content search of Collectives 4.4.0 and later, whose whole native chain only reads (SearchService::searchCollective
 * to FileSearcher and its mappers, proven on the pinned releases by collectives-read-chains.json). The 4.0.0 shape is
 * refused before Collectives is asked: its search creates the `indices` folder of the app data when it is missing
 * (getIndexForCollective to getIndicesFolder to newFolder), a write that a read must not make. Hits are read as file
 * ids in native rank order, without repeats, cut at the budget, and the answer says when the native side may have
 * stopped at it. Authorizing each hit is the caller's.
 */
class ContentSearch {
	/**
	 * @param CollectivesGateway $gateway the native search call
	 * @param CollectivesSurface $surface which of the two shapes is installed
	 */
	public function __construct(
		private CollectivesGateway $gateway,
		private CollectivesSurface $surface,
	) {
	}

	/**
	 * @param int $cid collective id
	 * @param string $query text to find
	 * @param string $uid authenticated actor
	 * @param int $budget most results asked for
	 * @return array{ids:list<int>, capped:bool} page ids in native rank order, and whether the native answer may have stopped at the budget
	 * @throws ToolFailure when this version has no read-only content search (4.0.0 or an unknown shape), or the call is
	 *   not admitted
	 */
	public function search(int $cid, string $query, string $uid, int $budget): array {
		if ($this->surface->searchArity() !== CollectivesSurface::SEARCH_WITHOUT_LIMIT) {
			throw new ToolFailure(CollectivesMessages::contentSearchUnavailable());
		}
		$results = $this->gateway->searchCollective($cid, $query, $uid);
		$ids = [];
		foreach ($results as $hit) {
			$id = is_array($hit) ? (int)($hit['file_id'] ?? 0) : 0;
			if ($id > 0 && !in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}
		return ['ids' => array_slice($ids, 0, $budget), 'capped' => count($results) >= $budget];
	}
}
