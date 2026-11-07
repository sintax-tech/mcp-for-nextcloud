<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\Files\InMemoryBatchStore;

/**
 * What the Files contracts observe after each step, as deterministic JSON-encodable values: every node of the tree
 * with its id (a move keeps it, a copy draws a new one) and its bytes, the write log of the tree, the recorded batches
 * and the checkout links. A token is kept without its hash: the hash is a keyed digest of a random value.
 */
final class FilesEffectState {
	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * @param FakeTree $tree the files of alice
	 * @return array{nodes: array<string, string>, ops: list<string>} each path with `dir #id` or `#id <bytes>`, and the log
	 */
	public static function tree(FakeTree $tree): array {
		$nodes = [];
		foreach ($tree->nodes as $path => $node) {
			$nodes[$path] = $node['type'] === 'dir' ? 'dir #' . $node['id'] : '#' . $node['id'] . ' ' . $node['content'];
		}
		ksort($nodes);
		return ['nodes' => $nodes, 'ops' => $tree->ops];
	}

	/**
	 * @param InMemoryBatchStore $store the batch store
	 * @return list<array<string, mixed>> each recorded batch: owner, time, moves, created folders and undo time
	 */
	public static function batches(InMemoryBatchStore $store): array {
		return array_values(array_map(static fn (array $row): array => [
			'id' => $row['id'],
			'user_id' => $row['user_id'],
			'created_at' => $row['created_at'],
			'moves' => json_decode((string)$row['moves_json'], true),
			'dirs' => json_decode((string)$row['dirs_json'], true),
			'undone_at' => $row['undone_at'],
		], $store->rows));
	}

	/**
	 * @param InMemoryCheckoutTokenStore $store the checkout token store
	 * @return list<array<string, mixed>> each stored link without its hash, with whether it was spent and when
	 */
	public static function tokens(InMemoryCheckoutTokenStore $store): array {
		return array_values(array_map(static function (array $row): array {
			unset($row['token_hash']);
			ksort($row);
			return $row;
		}, $store->rows));
	}
}
