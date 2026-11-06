<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Modules\Tables\Writing\SchemaViewChanges;
use OCA\Mcp\Modules\Tables\Sharing\ShareChanges;
use OCA\Mcp\Modules\Tables\Presentation\SchemaSnapshot;

/** Native authorization, DTO validation and read-only preparation. */
class SchemaPreparation {
	/**
	 * @param SchemaServices $services Native resolvers.
	 * @param SchemaTargetReader $reader Fresh native targets.
	 * @param SchemaColumnInput $columns Fused DTO.
	 * @param SchemaSharePolicy $policy Sharing restrictions.
	 * @param ShareChanges $shares Pure share intent.
	 */
	public function __construct(private SchemaServices $services, private SchemaTargetReader $reader, private SchemaColumnInput $columns,
		private SchemaSharePolicy $policy, private ShareChanges $shares) {}
	/**
	 * @param string $kind Native entity kind.
	 * @param int|null $id Existing native target.
	 * @param bool $create New entity.
	 * @param bool $share Node sharing intent.
	 * @param array<string,mixed> $a Validated arguments.
	 * @param string $uid Caller.
	 * @param string $profile API family.
	 * @return array<string,mixed> Prepared state and native objects, never serialized directly.
	 */
	public function base(string $kind, ?int $id, bool $create, bool $share, array $a, string $uid, string $profile): array {
		$this->services->get('permissions', $uid)->preCheckUserId($uid, false);

		$node = null;
		$table = null;
		$before = [];
		$shared = false;
		if ($share || !$create) {
			$node = $this->reader->target($kind, $id, $uid, !$share && $kind !== 'table' ? $a['table_id'] : null);
			$before = SchemaNativeSnapshot::of($node);
		}
		if (!$create || $kind !== 'table') {
			$tableId = $share ? ($kind === 'table' ? $id : (int)$node->getTableId()) : $a['table_id'];
			// A view manager need not manage the whole table. Read the parent only after authorizing that view.
			$table = $kind === 'view' && $node !== null
				? SchemaNativeCalls::run(fn (): object => $this->services->get('tableMapper', $uid)->find($tableId))
				: $this->reader->target('table', $tableId, $uid);
			$shared = (string)$table->getOwnership() !== $uid;
		}
		$changes = $a['patch'] ?? array_intersect_key($a, array_flip(['title', 'description', 'emoji', 'mandatory', 'type', 'subtype', 'options']));
		$p = ['arguments' => $a, 'kind' => $kind, 'id' => $id, 'table' => $table, 'before' => SchemaSnapshot::of($before, $kind),
			'preserved' => $before, 'changes' => $changes, 'shared' => $shared, 'version' => $this->services->version(), 'profile' => $profile, 'noop' => false];
		$p['after'] = array_replace($p['before'], $p['changes']);
		return $p;
	}
	/** @param array $p Authorized base intent. @param bool $create New column. @param string $uid Caller. @return array Validated column intent. */
	public function column(array $p, bool $create, string $uid): array {
		$a = $p['arguments'];
		$before = $p['preserved'] ?? [];
		$changes = $p['changes'];
		$profile = $p['profile'];
		$p['views'] = [];
		foreach ($a['view_ids'] ?? [] as $viewId) {
			$view = $this->reader->target('view', $viewId, $uid, $a['table_id']);
			$p['views'][$viewId] = SchemaSnapshot::of((array)$view->jsonSerialize(), 'view');
		}
		$column = $this->columns->of($changes, $create ? null : $before, $uid, $profile);
		$p['rewrittenFields'] = $column['rewrittenFields'];
		$p['dto'] = $column['dto'];
		$p['changes'] = SchemaSnapshot::of($column['data'], 'column');
		// Technical settings are part of confirmation too, even though they never enter the visible plan.
		$p['preserved'] = $before;
		$p['after'] = array_replace($p['before'], $p['changes']);
		return $p;
	}
	/** @param array $p Authorized view. @param string $uid Caller. @return array Validated view intent. */
	public function view(array $p, string $uid): array {
		$a = $p['arguments'];
		$changes = $p['changes'];
		$before = $p['preserved'];
		$profile = $p['profile'];
		$node = $this->reader->target('view', $p['id'], $uid, $a['table_id']);
		$p['changes'] = SchemaViewChanges::of($changes, $before, $this->reader->columns($a['table_id'], $uid, $node), $profile);
		SchemaViewInput::of($p['changes'], $profile);
		$p['after'] = array_replace($p['before'], $p['changes']);
		if (array_key_exists('columnSettings', $p['changes'])) $p['after']['columns'] = array_column($p['changes']['columnSettings'], 'columnId');
		return $p;
	}
	/** @param array $p Authorized node. @param string $uid Caller. @return array Explicit share intent. */
	public function sharing(array $p, string $uid): array {
		$a = $p['arguments'];
		$this->policy->check($a['recipient'], $uid);
		return $this->shares->of(false, $p, $this->reader->shares($a['node_type'], $a['node_id'], $uid));
	}
	/** @param array $p Authorized node. @param string $uid Caller. @return array Bound revocation intent. */
	public function revocation(array $p, string $uid): array {
		$a = $p['arguments'];
		return $this->shares->of(true, $p, $this->reader->shares($a['node_type'], $a['node_id'], $uid));
	}

}
