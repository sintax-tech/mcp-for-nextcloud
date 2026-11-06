<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;

/** Only authorized public metadata enters plans and receipts, never raw native entities. */
final class SchemaSnapshot {
	/**
	 * @param array<string,mixed> $data Native metadata.
	 * @param string $kind Entity kind.
	 * @return array<string,mixed> Safe public snapshot.
	 */
	public static function of(array $data, string $kind): array {
		if ($kind === 'column' && is_string($data['selectionDefault'] ?? null)) {
			$decoded = json_decode($data['selectionDefault'], true);
			if (json_last_error() === JSON_ERROR_NONE) $data['selectionDefault'] = $decoded;
		}
		$keys = ['id', 'tableId', 'title', 'description', 'emoji'];
		if ($kind === 'table') $keys[] = 'archived';
		if ($kind === 'view') $keys = [...$keys, 'columns', 'columnSettings', 'filter', 'sort'];
		if ($kind === 'column') {
			$keys = [...$keys, 'type', 'subtype', 'mandatory'];
			foreach (ColumnOptions::TYPE_FIELDS[$data['type'] ?? ''] ?? [] as $field) $keys[] = $field;
		}
		return self::clean(array_intersect_key($data, array_flip($keys)));
	}
	/**
	 * @param array<string,mixed> $share Authorized user/group share.
	 * @return array<string,mixed> Closed public representation.
	 */
	public static function share(array $share): array {
		$bits = [];
		foreach (['read', 'create', 'update', 'delete', 'manage'] as $key) $bits[$key] = (bool)($share['permission' . ucfirst($key)] ?? false);
		return ['id' => (int)($share['id'] ?? 0), 'nodeId' => (int)($share['nodeId'] ?? 0), 'nodeType' => $share['nodeType'] ?? '',
			'recipient' => ['type' => $share['receiverType'] ?? '', 'id' => TablesText::clean($share['receiver'] ?? '')], 'permissions' => $bits];
	}
	/**
	 * @param mixed $value Bounded metadata.
	 * @return mixed Clean metadata without internal UUIDs.
	 */
	private static function clean(mixed $value): mixed {
		if (is_string($value)) return TablesText::clean($value);
		if ($value instanceof \JsonSerializable) $value = $value->jsonSerialize();
		if (!is_array($value)) return $value;
		unset($value['uuid'], $value['technicalName']);
		foreach ($value as &$item) $item = self::clean($item);
		return $value;
	}
}
