<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Reading\SavedViewRules;
use OCA\Mcp\Modules\Tables\Integration\TablesServices;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;

/**
 * Before any relation value reaches the Tables app, null included: RelationBusiness::validateValue(), canBeParsed() and
 * parseValue() call RelationService::getRelationData(), which reads the label column and every row of the target table or
 * view, with no permission check of its own. A relation cell is written only when the caller could read all of that
 * natively:
 * - target table: PermissionsService::canReadRowsByElementId($target, 'table') and the label column of that table;
 * - target view: canReadRowsByElementId($target, 'view'), the label column among the view's columns and of its table, and
 *   the view's saved filter and sort accepted by {@see SavedViewRules} (the rules RelationService applies);
 * - in both, a complete relation setting (relationType table or view, target and label column ids) and no federated target.
 * Anything else refuses the whole call with one fixed message, which says nothing of the target or of the id. The gate
 * reads only metadata and permissions; whether the id exists stays the Tables app's to check.
 */
class RelationWriteGate {
	/**
	 * Receives the Tables services.
	 *
	 * @param TablesServices $services resolver of the Tables services
	 * @return void
	 */
	public function __construct(
		private TablesServices $services,
	) {
	}

	/**
	 * Checks one relation column, and gives what the plan state binds of its target.
	 *
	 * @param array<string, mixed> $column the relation column as Column::jsonSerialize() gives it
	 * @param string $userId authenticated user UID
	 * @param string $version installed Tables version
	 * @return array<string, mixed> the target's type, id, label column and saved rules
	 * @throws RowWriteRefusal when the target is not readable for the caller
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function check(array $column, string $userId, string $version): array {
		$settings = (array)($column['customSettings'] ?? []);
		$type = $settings['relationType'] ?? null;
		$target = $settings['targetId'] ?? null;
		$label = $settings['labelColumn'] ?? null;
		if (!in_array($type, ['table', 'view'], true) || !self::isId($target) || !self::isId($label)) {
			throw RowWriteRefusal::relation();
		}
		$target = (int)$target;
		$label = (int)$label;
		$permissions = $this->services->permissionsService($userId);
		$columns = $this->services->columnMapper($userId);
		try {
			if (!(bool)$permissions->canReadRowsByElementId($target, $type, $userId)) {
				throw RowWriteRefusal::relation();
			}
			$labelColumn = (array)$columns->find($label)->jsonSerialize();
			if ($type === 'table') {
				$node = $this->services->tableMapper($userId)->find($target);
				$tableId = $target;
				$rules = [null, null];
			} else {
				$node = $this->services->viewMapper($userId)->find($target);
				$tableId = (int)$node->getTableId();
				if (!in_array($label, array_map('intval', (array)$node->getColumnIds()), true)) {
					throw RowWriteRefusal::relation();
				}
				$saved = new SavedViewRules($columns, $tableId);
				$saved->filter(self::text($node->getFilter()), static fn (): array => (array)$node->getFilterArray());
				$saved->sort(self::text($node->getSort()), static fn (): array => (array)$node->getSortArray());
				$rules = [self::text($node->getFilter()), self::text($node->getSort())];
			}
			if ((int)($labelColumn['tableId'] ?? 0) !== $tableId
				|| RowWriteColumns::federated($node, $version)) {
				throw RowWriteRefusal::relation();
			}
		} catch (RowWriteRefusal|TablesUnavailableException $e) {
			throw $e;
		} catch (\Throwable) {
			throw RowWriteRefusal::relation();
		}
		return ['type' => $type, 'target' => $target, 'label' => $labelColumn, 'filter' => $rules[0], 'sort' => $rules[1]];
	}

	/**
	 * Whether a setting is a positive id.
	 *
	 * @param mixed $value the setting
	 * @return bool true for an id
	 */
	private static function isId(mixed $value): bool {
		return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9]\d{0,18}$/', $value) === 1);
	}

	/**
	 * A stored rule as text, null when there is none.
	 *
	 * @param mixed $value the stored value
	 * @return string|null the text
	 */
	private static function text(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}
}
