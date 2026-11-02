<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables;

use OCP\IURLGenerator;

/**
 * Direct links into the Tables app, in the form its own search provider builds them (SearchTablesProvider::getInternalLink()
 * in 1.0.x and 2.x): the absolute route `tables.page.index` followed by `#/table/<id>` or `#/view/<id>`. Rows get no link:
 * no row route was proven stable across the supported releases.
 */
class TablesLinks {
	/**
	 * Receives the URL generator.
	 *
	 * @param IURLGenerator $urls builds absolute links of app routes
	 * @return void
	 */
	public function __construct(
		private IURLGenerator $urls,
	) {
	}

	/**
	 * The link of a table.
	 *
	 * @param int $tableId table id
	 * @return string the absolute link
	 */
	public function table(int $tableId): string {
		return $this->urls->linkToRouteAbsolute('tables.page.index') . '#/table/' . $tableId;
	}

	/**
	 * The link of a view.
	 *
	 * @param int $viewId view id
	 * @return string the absolute link
	 */
	public function view(int $viewId): string {
		return $this->urls->linkToRouteAbsolute('tables.page.index') . '#/view/' . $viewId;
	}
}
