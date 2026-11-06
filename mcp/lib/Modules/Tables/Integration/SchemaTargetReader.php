<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;
use OCA\Mcp\Modules\Tables\Writing\RowWriteColumns;

/** Native read/manage boundary, including every parent-child reference. */
class SchemaTargetReader {
	/** @param SchemaServices $services Lazy native services. */
	public function __construct(private SchemaServices $services) {}
	/**
	 * @param string $kind table/view/column.
	 * @param int $id Identifier.
	 * @param string $uid Caller.
	 * @param int|null $parent Expected table.
	 * @return object Authorized native entity.
	 */
	public function target(string $kind, int $id, string $uid, ?int $parent = null): object {
		$node = SchemaNativeCalls::run(fn (): object => $this->services->get($kind . 'Mapper', $uid)->find($id));
		$permissions = $this->services->get('permissions', $uid);
		$tableId = $kind === 'table' ? $id : (int)$node->getTableId();
		$readable = SchemaNativeCalls::run(fn (): bool => $kind === 'view'
			? $permissions->canAccessView($node, $uid)
			: $permissions->canReadColumnsByTableId($tableId, $uid));
		if (!$readable || ($parent !== null && $parent !== $tableId)) throw new ToolFailure(CommonMessages::notFound());
		$managed = SchemaNativeCalls::run(fn (): bool => $kind === 'view' ? $permissions->canManageView($node, $uid)
			: $permissions->canManageTableById($tableId, $uid));
		if (!$managed) throw new ToolFailure(CommonMessages::forbidden());
		$table = $kind === 'table' ? $node : SchemaNativeCalls::run(fn (): object => $this->services->get('tableMapper', $uid)->find($tableId));
		if (RowWriteColumns::federated($table, $this->services->version())
			|| ($kind === 'view' && RowWriteColumns::federated($node, $this->services->version()))) {
			throw new ToolFailure(SchemaMessages::incompatible());
		}
		return $node;
	}
	/**
	 * @param int $tableId Managed table.
	 * @param string $uid Caller.
	 * @return array<int,array<string,mixed>> Native column snapshots by ID.
	 */
	public function columns(int $tableId, string $uid, ?object $view = null): array {
		if ($view === null) $this->target('table', $tableId, $uid);
		$columns = SchemaNativeCalls::run(fn (): array => $view === null ? $this->services->get('columnMapper', $uid)->findAllByTable($tableId) : $this->services->get('columns', $uid)->findAllByManagedView($view, $uid));
		$result = [];
		foreach ($columns as $column) {
			$data = (array)$column->jsonSerialize();
			if ((int)$data['tableId'] !== $tableId) throw new ToolFailure(CommonMessages::notFound());
			$result[(int)$data['id']] = $data;
		}
		return $result;
	}
	/**
	 * @param string $type Node type.
	 * @param int $id Node ID.
	 * @param string $uid Caller.
	 * @return list<array<string,mixed>> Authorized shares, including internal state only.
	 */
	public function shares(string $type, int $id, string $uid): array {
		$this->target($type, $id, $uid);
		$items = SchemaNativeCalls::run(fn (): array => $this->services->get('shares', $uid)->findAll($type, $id, $uid));
		return array_values(array_map(static fn (object $share): array => (array)$share->jsonSerialize(), $items));
	}
}
