<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** Named official-service sinks; each tool selects its own method through its typed action. */
class SchemaNativeWriter {
	/** @param SchemaServices $services Native collaborators. */
	public function __construct(private SchemaServices $services) {}
	/**
	 * @param array<string,mixed> $prepared Revalidated intent.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function createTable(array $prepared, string $uid): ?int {
		$patch = $prepared['changes'];
		return $this->id($this->services->get('tables', $uid)->create($patch['title'], 'custom', $patch['emoji'] ?? null, $patch['description'] ?? '', $uid));
	}
	/**
	 * @param array<string,mixed> $prepared Revalidated intent.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function updateTable(array $prepared, string $uid): ?int {
		$a = $prepared['arguments'];
		$patch = $prepared['changes'];
		return $this->id($this->services->get('tables', $uid)->update($a['table_id'], $patch['title'] ?? null, $patch['emoji'] ?? null, $patch['description'] ?? null, $patch['archived'] ?? null, $uid));
	}
	/**
	 * @param array<string,mixed> $prepared Revalidated intent.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function createColumn(array $prepared, string $uid): ?int {
		$a = $prepared['arguments'];
		return $this->id($this->services->get('columns', $uid)->create($uid, $a['table_id'], null, $prepared['dto'], $a['view_ids'] ?? []));
	}
	/**
	 * @param array<string,mixed> $prepared Revalidated intent.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function updateColumn(array $prepared, string $uid): ?int {
		$a = $prepared['arguments'];
		return $this->id($this->services->get('columns', $uid)->update($a['column_id'], $uid, $prepared['dto']));
	}
	/**
	 * @param array<string,mixed> $prepared Revalidated intent.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function createView(array $prepared, string $uid): ?int {
		$patch = $prepared['changes'];
		return $this->id($this->services->get('views', $uid)->create($patch['title'], $patch['emoji'] ?? null, $prepared['table'], $uid));
	}
	/**
	 * @param array<string,mixed> $prepared Revalidated intent.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function updateView(array $prepared, string $uid): ?int {
		$a = $prepared['arguments'];
		$patch = $prepared['changes'];
		$profile = $prepared['profile'];
		return $this->id($this->services->get('views', $uid)->update($a['view_id'], SchemaViewInput::of($patch, $profile), $uid));
	}
	/**
	 * @param array<string,mixed> $prepared Revalidated intent.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function shareNode(array $prepared, string $uid): ?int {
		$a = $prepared['arguments'];
		$profile = $prepared['profile'];
		return $this->id($this->share($a, $profile, $uid));
	}
	/**
	 * @param array<string,mixed> $prepared Revalidated intent.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function unshareNode(array $prepared, string $uid): ?int {
		$a = $prepared['arguments'];
		return $this->id($this->services->get('shares', $uid)->delete($a['share_id']));
	}
	/**
	 * @param mixed $result Native return.
	 * @return int|null Positive native identifier, never fabricated.
	 */
	private function id(mixed $result): ?int {
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
