<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Modules\Tables\Writing\SchemaViewChanges;
use OCA\Mcp\Modules\Tables\Presentation\SchemaSnapshot;

/** Native authorization, DTO validation and read-only preparation. */
class SchemaPreparation {
	/**
	 * @param SchemaServices $services Native resolvers.
	 * @param SchemaTargetReader $reader Fresh native targets.
	 * @param SchemaColumnInput $columns Fused DTO.
	 * @param SchemaSharePolicy $policy Sharing restrictions.
	 * @param \OCA\Mcp\Modules\Tables\Sharing\ShareChanges $shares Pure share intent.
	 */
	public function __construct(private SchemaServices $services, private SchemaTargetReader $reader, private SchemaColumnInput $columns,
		private SchemaSharePolicy $policy, private \OCA\Mcp\Modules\Tables\Sharing\ShareChanges $shares) {}
	/**
	 * @param string $action Action.
	 * @param array<string,mixed> $a Validated arguments.
	 * @param string $uid Caller.
	 * @param string $profile API family.
	 * @return array<string,mixed> Prepared state and native objects, never serialized directly.
	 */
	public function prepare(string $action, array $a, string $uid, string $profile): array {
		$this->services->get('permissions', $uid)->preCheckUserId($uid, false);
		$share = in_array($action, ['share_node', 'unshare_node'], true);
		$kind = $share ? $a['node_type'] : (str_ends_with($action, 'column') ? 'column' : (str_ends_with($action, 'view') ? 'view' : 'table'));
		$create = str_starts_with($action, 'create_');
		$id = $share ? $a['node_id'] : ($a[$kind . '_id'] ?? null);
		$node = null; $table = null; $before = []; $shared = false;
		if ($share || !$create) {
			$node = $this->reader->target($kind, $id, $uid, !$share && $kind !== 'table' ? $a['table_id'] : null);
			$before = SchemaNativeSnapshot::of($node);
		}
		if ($action !== 'create_table') {
			$tableId = $share ? ($kind === 'table' ? $id : (int)$node->getTableId()) : $a['table_id'];
			// A view manager need not manage the whole table. Read the parent only after authorizing that view.
			$table = $kind === 'view' && $node !== null
				? SchemaNativeCalls::run(fn (): object => $this->services->get('tableMapper', $uid)->find($tableId))
				: $this->reader->target('table', $tableId, $uid);
			$shared = (string)$table->getOwnership() !== $uid;
		}
		$changes = $a['patch'] ?? array_intersect_key($a, array_flip(['title', 'description', 'emoji', 'mandatory', 'type', 'subtype', 'options']));
		$p = ['arguments' => $a, 'kind' => $kind, 'id' => $id, 'table' => $table, 'before' => SchemaSnapshot::of($before, $kind),
			'changes' => $changes, 'shared' => $shared, 'version' => $this->services->version(), 'profile' => $profile, 'noop' => false];
		if ($share) {
			if ($action === 'share_node') $this->policy->check($a['recipient'], $uid);
			return $this->shares->of($action, $p, $this->reader->shares($a['node_type'], $a['node_id'], $uid));
		}
		if ($kind === 'column') {
			$p['views'] = [];
			foreach ($a['view_ids'] ?? [] as $viewId) {
				$view = $this->reader->target('view', $viewId, $uid, $a['table_id']);
				$p['views'][$viewId] = SchemaSnapshot::of((array)$view->jsonSerialize(), 'view');
			}
			$column = $this->columns->of($changes, $create ? null : $before, $uid, $profile);
			$p['rewrittenFields'] = $column['rewrittenFields'];
			$p['dto'] = $column['dto']; $p['changes'] = SchemaSnapshot::of($column['data'], 'column');
			// Technical settings are part of confirmation too, even though they never enter the visible plan.
			$p['preserved'] = $before;
		} elseif ($action === 'update_view') {
			$p['changes'] = SchemaViewChanges::of($changes, $before, $this->reader->columns($a['table_id'], $uid, $node), $profile);
			SchemaViewInput::of($p['changes'], $profile);
		}
		$p['after'] = array_replace($p['before'], $p['changes']);
		if ($action === 'update_view' && array_key_exists('columnSettings', $p['changes'])) $p['after']['columns'] = array_column($p['changes']['columnSettings'], 'columnId');
		return $p;
	}
}
