<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** The only schema/share mutation boundary: exactly one official service call. */
class SchemaNativeWriter {
	/** @param SchemaServices $services Lazy native collaborators. */
	public function __construct(private SchemaServices $services) {}
	/**
	 * @param string $action Operation.
	 * @param array<string,mixed> $prepared Revalidated native intent.
	 * @param string $uid Caller.
	 * @return int|null Returned entity ID when trustworthy.
	 */
	public function apply(string $action, array $prepared, string $uid): ?int {
		$a = $prepared['arguments']; $patch = $prepared['changes']; $profile = $prepared['profile'];
		$result = match ($action) {
			'create_table' => $this->services->get('tables', $uid)->create($patch['title'], 'custom', $patch['emoji'] ?? null, $patch['description'] ?? '', $uid),
			'update_table' => $this->services->get('tables', $uid)->update($a['table_id'], $patch['title'] ?? null, $patch['emoji'] ?? null, $patch['description'] ?? null, $patch['archived'] ?? null, $uid),
			'create_column' => $this->services->get('columns', $uid)->create($uid, $a['table_id'], null, $prepared['dto'], $a['view_ids'] ?? []),
			'update_column' => $this->services->get('columns', $uid)->update($a['column_id'], $uid, $prepared['dto']),
			'create_view' => $this->services->get('views', $uid)->create($patch['title'], $patch['emoji'] ?? null, $prepared['table'], $uid),
			'update_view' => $this->services->get('views', $uid)->update($a['view_id'], SchemaViewInput::of($patch, $profile), $uid),
			'share_node' => $this->share($a, $profile, $uid),
			'unshare_node' => $this->services->get('shares', $uid)->delete($a['share_id']),
		};
		return is_object($result) && is_numeric($result->getId()) && (int)$result->getId() > 0 ? (int)$result->getId() : null;
	}
	/**
	 * @param array<string,mixed> $a Validated share.
	 * @param string $profile API family.
	 * @param string $uid Caller.
	 * @return object Native share.
	 */
	private function share(array $a, string $profile, string $uid): object {
		$values = [$a['node_id'], $a['node_type'], $a['recipient']['id'], $a['recipient']['type']];
		foreach (['read', 'create', 'update', 'delete', 'manage'] as $key) $values[] = $a['permissions'][$key];
		$values[] = 0;
		$service = $this->services->get('shares', $uid);
		if (in_array($profile, ['1.0.0', '2.0.0'], true)) return $service->create(...$values);
		$class = 'OCA\\Tables\\Service\\ValueObject\\ShareCreate';
		return $service->create(new $class(...$values));
	}
}
