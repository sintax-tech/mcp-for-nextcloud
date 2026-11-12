<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Reading;

use JsonException;

/** Exact producer shapes from NC34/35; historical strings are never authorization evidence or output. */
final class ActivityObjectShapes {
	/**
	 * @param array<string,mixed> $row native unformatted row
	 * @return array{action:string,ids:list<int>}|null every known file reference, not merely the first printable one
	 */
	public function files(array $row): ?array {
		if (($row['app'] ?? '') !== 'files' || ($row['object_type'] ?? '') !== 'files' || !$this->emptyMessage($row)) return null;
		$subject = $row['subject'] ?? '';
		$shape = match ($subject) {
			'created_self', 'created_public' => ['created', 1, [0]],
			'changed_self' => ['changed', 1, [0]], 'deleted_self' => ['deleted', 1, [0]], 'restored_self' => ['restored', 1, [0]],
			'created_by' => ['created', 2, [0]], 'changed_by' => ['changed', 2, [0]],
			'deleted_by' => ['deleted', 2, [0]], 'restored_by' => ['restored', 2, [0]],
			'renamed_self' => ['renamed', 2, [0, 1]], 'moved_self' => ['moved', 2, [0, 1]],
			'renamed_by' => ['renamed', 3, [0, 2]], 'moved_by' => ['moved', 3, [0, 2]],
			default => null,
		};
		$params = $this->parameters($row);
		if ($shape === null || $params === null || !array_is_list($params) || count($params) !== $shape[1]) return null;
		if (str_ends_with($subject, '_by') && !is_string($params[1])) return null;
		$ids = [];
		foreach ($shape[2] as $index) {
			$reference = $params[$index];
			if (!is_array($reference) || count($reference) !== 1 || !is_string(reset($reference))) return null;
			$id = $this->id(array_key_first($reference));
			if ($id === null) return null;
			$ids[] = $id;
		}
		if ($ids[0] !== $this->id($row['object_id'] ?? null)) return null;
		return ['action' => $shape[0], 'ids' => array_values(array_unique($ids))];
	}
	/**
	 * @param array<string,mixed> $row native DAV Activity row
	 * @return array{action:string,calendarId:int,uid:string}|null simple one-collection VEVENT, never move/share/VTODO
	 */
	public function calendar(array $row): ?array {
		if (($row['app'] ?? '') !== 'dav' || ($row['object_type'] ?? '') !== 'calendar' || !$this->emptyMessage($row)) return null;
		$action = match ($row['subject'] ?? '') {
			'object_add_event', 'object_add_event_self' => 'created', 'object_update_event', 'object_update_event_self' => 'changed',
			'object_delete_event', 'object_delete_event_self', 'object_move_to_trash_event', 'object_move_to_trash_event_self' => 'deleted',
			'object_restore_event', 'object_restore_event_self' => 'restored', default => null,
		};
		$params = $this->parameters($row);
		if ($action === null || $params === null || array_diff(array_keys($params), ['actor', 'calendar', 'object']) !== [] || !is_string($params['actor'] ?? null)) return null;
		$calendar = $params['calendar'] ?? null; $object = $params['object'] ?? null;
		if (!is_array($calendar) || !is_array($object) || array_diff(array_keys($calendar), ['id','uri','name']) !== []
			|| array_diff(array_keys($object), ['id','name','classified','link']) !== []) return null;
		if (!is_string($calendar['uri'] ?? null) || !is_string($calendar['name'] ?? null) || !is_string($object['name'] ?? null)
			|| !is_bool($object['classified'] ?? null)) return null;
		if (isset($object['link'])) {
			if (!is_array($object['link']) || array_diff(array_keys($object['link']), ['owner','object_uri','calendar_uri']) !== []) return null;
			foreach (['owner','object_uri','calendar_uri'] as $key) if (!is_string($object['link'][$key] ?? null)) return null;
		}
		$id = $this->id($calendar['id'] ?? null); $uid = $object['id'] ?? null;
		if ($id === null || $id !== $this->id($row['object_id'] ?? null) || !is_string($uid) || $uid === '' || strlen($uid) > 255 || str_contains($uid, "\0")) return null;
		return ['action' => $action, 'calendarId' => $id, 'uid' => $uid];
	}
	/**
	 * @param array<string,mixed> $row opaque native metadata
	 * @return bool ACT-1's reference-free subset remains unchanged
	 */
	public function metadataOnly(array $row): bool {
		foreach (['object_type', 'file', 'object_name', 'link'] as $key) if (!in_array($row[$key] ?? null, ['', null], true)) return false;
		if (!in_array($row['object_id'] ?? null, [0, '0', '', null], true) || !in_array($row['objects'] ?? null, [[], null], true)) return false;
		foreach (['subjectparams', 'messageparams'] as $key) if (!in_array($row[$key] ?? '[]', ['[]', '{}', '', [], null], true)) return false;
		return true;
	}
	/**
	 * @param array<string,mixed> $row known producer
	 * @return bool unknown secondary message/object references omit the whole event
	 */
	private function emptyMessage(array $row): bool {
		return in_array($row['message'] ?? '', ['', null], true) && in_array($row['messageparams'] ?? '[]', ['[]', '{}', '', [], null], true)
			&& in_array($row['objects'] ?? null, [[], null], true);
	}
	/**
	 * @param array<string,mixed> $row bounded native field
	 * @return array|null decoded identities only, never raw projected parameters
	 */
	private function parameters(array $row): ?array {
		$value = $row['subjectparams'] ?? null;
		if (!is_string($value) || strlen($value) > 8192) return null;
		try { $params = json_decode($value, true, 8, JSON_THROW_ON_ERROR); }
		catch (JsonException) { return null; }
		return is_array($params) ? $params : null;
	}
	/**
	 * @param mixed $value native positive identity
	 * @return int|null malformed or overflowing IDs are unknown
	 */
	private function id(mixed $value): ?int {
		if (!is_int($value) && (!is_string($value) || !ctype_digit($value))) return null;
		$id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		return $id === false ? null : $id;
	}
}
