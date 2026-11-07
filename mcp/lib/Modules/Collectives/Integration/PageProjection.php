<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

/**
 * Turns native Collectives objects into plain arrays by allowlist. Share tokens, absolute storage paths, circle ids,
 * members' preferences and anything a later release adds never pass: only the keys named here are copied.
 */
final class PageProjection {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param object $collective native Collective
	 * @return array{id:int, name:string, emoji:?string, can_edit:bool, can_share:bool} the collective as this module shows it
	 */
	public static function collective(object $collective): array {
		$data = (array)$collective->jsonSerialize();
		return [
			'id' => (int)($data['id'] ?? 0),
			'name' => (string)($data['name'] ?? ''),
			'emoji' => isset($data['emoji']) && is_string($data['emoji']) && $data['emoji'] !== '' ? $data['emoji'] : null,
			'can_edit' => ($data['canEdit'] ?? false) === true,
			'can_share' => ($data['canShare'] ?? false) === true,
		];
	}

	/**
	 * @param object $page native PageInfo
	 * @return array{id:int, title:string, slug:?string, parent_id:int, emoji:?string, last_user:?string, timestamp:int, size:int, subpage_order:list<int>, file_name:string, file_path:string}
	 *   the page; file_name and file_path are relative to the collective and only used inside the module
	 */
	public static function page(object $page): array {
		$data = (array)$page->jsonSerialize();
		$order = is_array($data['subpageOrder'] ?? null) ? $data['subpageOrder'] : [];
		return [
			'id' => (int)($data['id'] ?? 0),
			'title' => (string)($data['title'] ?? ''),
			'slug' => isset($data['slug']) && is_string($data['slug']) && $data['slug'] !== '' ? $data['slug'] : null,
			'parent_id' => (int)($data['parentId'] ?? 0),
			'emoji' => isset($data['emoji']) && is_string($data['emoji']) && $data['emoji'] !== '' ? $data['emoji'] : null,
			'last_user' => isset($data['lastUserDisplayName']) && is_string($data['lastUserDisplayName']) ? $data['lastUserDisplayName'] : null,
			'timestamp' => (int)($data['timestamp'] ?? 0),
			'size' => (int)($data['size'] ?? 0),
			'subpage_order' => array_values(array_map('intval', array_filter($order, 'is_numeric'))),
			'file_name' => (string)($data['fileName'] ?? ''),
			'file_path' => (string)($data['filePath'] ?? ''),
		];
	}

}
