<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

/**
 * A write checked by {@see RowWritePreflight}, ready to be planned or confirmed: the request, its target, each cell in its
 * three forms, what the plan state binds of hidden values and relation targets, and the row as it is for an update.
 */
final class RowWritePrepared {
	/**
	 * Receives what the preflight found.
	 *
	 * @param RowWriteRequest $request the write
	 * @param RowWriteTarget $target its table or view
	 * @param list<array{columnId:int, argument:mixed, effect:mixed}> $cells the cells, in the order given
	 * @param list<array{0:int, 1:string}> $defaults the hidden values the native enhancer will add, resolved, in its order
	 * @param list<array<string, mixed>> $referenced the columns the view's saved filter names, hidden ones included
	 * @param array<int, array<string, mixed>> $relations what the relation gate found, by column id
	 * @param array{values: array<int, mixed>, lastEditAt: string}|null $current the row before an update, null for a creation
	 * @return void
	 */
	public function __construct(
		public readonly RowWriteRequest $request,
		public readonly RowWriteTarget $target,
		public readonly array $cells,
		public readonly array $defaults,
		public readonly array $referenced,
		public readonly array $relations,
		public readonly ?array $current,
	) {
	}

	/**
	 * Whether the write updates a row.
	 *
	 * @return bool true for an update
	 */
	public function isUpdate(): bool {
		return $this->request->rowId !== null;
	}
}
