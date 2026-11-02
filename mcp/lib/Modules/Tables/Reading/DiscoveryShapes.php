<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Reading;

use OCA\Mcp\Modules\Tables\TablesLinks;
use OCA\Mcp\Modules\Tables\Presentation\TablesText;

/**
 * What a table and a view look like in an answer: ids, titles and descriptions (cleaned, untrusted), the facts the
 * user needs to go on, and a link. Nothing about other users: no owner id, no share list, no view filter (the Tables app
 * does not serialize a view's filter either).
 */
class DiscoveryShapes {
	/**
	 * Receives the links.
	 *
	 * @param TablesLinks $links direct links into the Tables app
	 * @return void
	 */
	public function __construct(
		private TablesLinks $links,
	) {
	}

	/**
	 * One table.
	 *
	 * @param object $table OCA\Tables\Db\Table the user can open
	 * @param bool $manages whether the user manages it, so tables_list_views lists its views
	 * @param string $userId authenticated user UID
	 * @return array{id:int, title:string, emoji:string, description:string, archived:bool, ownedByMe:bool, viewsListed:bool, url:string} the table
	 */
	public function table(object $table, bool $manages, string $userId): array {
		$id = (int)$table->getId();
		return [
			'id' => $id,
			'title' => TablesText::clean($table->getTitle()),
			'emoji' => TablesText::clean($table->getEmoji()),
			'description' => TablesText::clean($table->getDescription()),
			'archived' => (bool)$table->getArchived(),
			'ownedByMe' => (string)$table->getOwnership() === $userId,
			'viewsListed' => $manages,
			'url' => $this->links->table($id),
		];
	}

	/**
	 * One view.
	 *
	 * @param object $view OCA\Tables\Db\View the user can open
	 * @param list<string> $via where it was found: table, shared, context
	 * @return array{id:int, tableId:int, title:string, emoji:string, description:string, columnCount:int, via:list<string>, url:string} the view
	 */
	public function view(object $view, array $via): array {
		$id = (int)$view->getId();
		return [
			'id' => $id,
			'tableId' => (int)$view->getTableId(),
			'title' => TablesText::clean($view->getTitle()),
			'emoji' => TablesText::clean($view->getEmoji()),
			'description' => TablesText::clean($view->getDescription()),
			'columnCount' => count((array)$view->getColumnIds()),
			'via' => $via,
			'url' => $this->links->view($id),
		];
	}
}
