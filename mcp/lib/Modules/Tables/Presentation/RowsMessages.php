<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * Every string of tables_list_rows: the description and the guide note are fixed English read by the model; the
 * refusals the user can see are translated through {@see Translator} and never name a column, a value or a user.
 */
final class RowsMessages {
	/** Description of tables_list_rows in tools/list. */
	public const TOOL = 'Lists the rows of one table (table_id) or one view (view_id) the authenticated user can read, as the Tables '
		. 'app reads them: only the columns the view shows (or all columns of the table), and through a view only the rows its '
		. 'saved filter keeps, which you can never widen. Optional filter (groups OR-ed, conditions inside a group AND-ed, on '
		. 'column ids from tables_list_columns or the meta columns -1 id, -2 created by, -3 edited by, -4 created at, -5 edited '
		. 'at), search (text columns and selection labels the user can see), sort and limit; pass nextCursor back as cursor for '
		. 'the next page; offsetLimitReached means more rows exist past the deepest page a cursor reaches (narrow the filter). '
		. 'Cells come as {columnId, value} with the native value: a selection gives its option id(s), a relation the id of the '
		. 'related row. Text is cleaned of control characters other than tab and line breaks and of direction marks, and is '
		. 'returned whole up to 20000 characters per cell and 100000 per page; a cut cell carries truncated and totalChars, '
		. 'and truncatedCells counts them. Read only.';

	/** Guide note of the rows tool. */
	public const GUIDE_NOTE = 'tables_list_rows reads rows of a table or a view; take column ids and selection option ids from '
		. 'tables_list_columns. Filter and sort only on columns the view shows. The placeholders accepted are @me on user and '
		. 'group columns, @checked and @unchecked on yes/no columns, and on date columns @datetime-date-today, '
		. '@datetime-date-start-of-year, @datetime-date-start-of-month, @datetime-date-start-of-week, @datetime-time-now and '
		. '@datetime-now, plus @datetime-exact-date:YYYY-MM-DD, @datetime-days-ahead:N and @datetime-days-ago:N from Tables '
		. '1.0.10 on: any other value starting with @ is refused. '
		. 'Pages are offsets: rows edited in between can move between pages.';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Refusal when the user session is not the caller, so Tables could format cells for someone else.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function sessionMismatch(): string {
		return Translator::t('The rows cannot be read in this context: the session does not belong to the authenticated user.');
	}

	/**
	 * Refusal of a filter, search or sort the tool cannot apply as given.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function invalidQuery(): string {
		return Translator::t('This filter, search or sort cannot be applied: use the column ids, operators and values tables_list_columns describes.');
	}

	/**
	 * Refusal of a query over the size limits.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function tooLarge(): string {
		return Translator::t('This query is too large: use fewer filter groups, conditions or a shorter search.');
	}

	/**
	 * Refusal when the saved rules of the view or table cannot be applied safely.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function savedRulesUnusable(): string {
		return Translator::t('The saved filter or sort of this view or table cannot be applied safely, so its rows are not listed.');
	}

	/**
	 * Refusal of a cursor that is not valid for this query and state.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function staleCursor(): string {
		return Translator::t('This cursor does not belong to this query or the view changed since: start again without a cursor.');
	}
}
