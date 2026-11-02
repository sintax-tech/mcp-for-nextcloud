<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * Every string of tables_create_row and tables_update_row: the descriptions, the argument texts and the guide note are
 * fixed English read by the model; the refusals, notes and receipts the user can see are translated through
 * {@see Translator} and never name a hidden column, a saved rule, a value or another user.
 */
final class RowWriteMessages {
	/** Description of tables_create_row in tools/list. */
	public const TOOL_CREATE = 'Creates one row in one table (table_id) or one view (view_id) the authenticated user may add rows to, '
		. 'through the Tables app itself, which also fires its events, activity and notifications. cells lists 1 to 100 '
		. '{column_id, value} pairs, each column once, on the columns tables_list_columns shows for that table or view, never '
		. 'read-only ones; the value follows the column type (see the guide). Without confirm the answer is the plan only; '
		. 'show it, ask, and repeat with confirm: true and its plan_state. A table owned by somebody else also needs '
		. 'confirm_shared: true. Through a view, Tables may fill columns the view does not show so the row matches it.';

	/** Description of tables_update_row in tools/list. */
	public const TOOL_UPDATE = 'Changes cells of one row (row_id) the authenticated user may read and update, through one table '
		. '(table_id) or one view (view_id) that shows the row, via the Tables app itself. cells lists 1 to 100 {column_id, value} '
		. 'pairs, each column once, on the columns that table or view shows, never read-only ones; other cells stay as they '
		. 'are. Without confirm the answer is the plan with the values before and after; show it, ask, and repeat with '
		. 'confirm: true and its plan_state. A table owned by somebody else also needs confirm_shared: true.';

	/** Description of the cells argument. */
	public const CELLS = 'The cells to write: a list of 1 to 100 objects {column_id, value}, with nothing else in them and each '
		. 'column_id at most once. value by column type: text a string; link {url, title?} with an http or https url; number a '
		. 'number; stars 0-5 and progress 0-100 as integers; selection an option id; multiple selection a list of option ids; '
		. 'yes/no true or false; date and time "YYYY-MM-DD HH:MM", "YYYY-MM-DD" or "HH:MM"; users and groups a list of '
		. '{id, type} with type 0 user, 1 group, 2 team; relation the id of the related row. Numbers, stars and progress '
		. 'also respect the column\'s minimum and maximum. null empties a cell, except yes/no, stars and progress, which take '
		. 'false or 0, and numbers before Tables 2.3.2, which would store 0.';

	/** Description of confirm_shared. */
	public const CONFIRM_SHARED = 'Set to true, after the user agreed, to write in a table owned by somebody else; the change is '
		. 'seen by everybody the table is shared with.';

	/** Guide note of the row writing tools. */
	public const GUIDE_NOTE = 'tables_create_row and tables_update_row write one row each, through the Tables app, with a plan '
		. 'first: column ids, option ids and the type of each column come from tables_list_columns. A relation can be written '
		. 'only when the related table or view is readable for the user. Creating through a view is refused when the hidden '
		. 'values that view would fill in cannot be checked. When a confirmed write fails, Tables may or may not have saved it: '
		. 'check before repeating, since repeating a creation can make a second row.';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The rule of a malformed cells argument.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function cellsRule(): string {
		return Translator::t('1 to 100 objects {column_id, value}, each column once');
	}

	/**
	 * Refusal of a cell the target does not let the user write.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function columnRefused(): string {
		return Translator::t('These cells cannot be written here: use the columns tables_list_columns shows for this table or view, except read-only ones.');
	}

	/**
	 * Refusal of a value that does not fit its column.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function valueRefused(): string {
		return Translator::t('A value does not fit its column: use the type, options and limits tables_list_columns describes.');
	}

	/**
	 * Refusal of a creation without a mandatory column, or of a mandatory column emptied.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function mandatoryMissing(): string {
		return Translator::t('A mandatory column needs a value.');
	}

	/**
	 * Refusal of a relation cell whose target the user cannot read.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function relationRefused(): string {
		return Translator::t('This relation column cannot be written: its target is not readable for you.');
	}

	/**
	 * Refusal of a creation through a view whose hidden values cannot be checked.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function viewDefaultsRefused(): string {
		return Translator::t('This view does not allow creating rows with this tool.');
	}

	/**
	 * Refusal of a federated table or view.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function federated(): string {
		return Translator::t('Rows of a federated table or view cannot be written with this tool.');
	}

	/**
	 * Refusal when the session is not the caller.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function sessionMismatch(): string {
		return Translator::t('The row cannot be written in this context: the session does not belong to the authenticated user.');
	}

	/**
	 * A confirmed creation that Tables did not confirm.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function createUncertain(): string {
		return Translator::t('Tables did not confirm the new row: it may not have been saved, or may have been saved in part. Check the table or view in Tables before repeating, since repeating can make a second row.');
	}

	/**
	 * A confirmed update that Tables did not confirm.
	 *
	 * @param int $rowId the row
	 * @return string the message in the language of the current user
	 */
	public static function updateUncertain(int $rowId): string {
		return Translator::t('Tables did not confirm the change of row %s: it may not have been saved, or may have been saved in part. Read the row again with tables_list_rows if you can, before repeating.', [(string)$rowId]);
	}

	/**
	 * Note of every creation through a view.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function viewCreateNote(): string {
		return Translator::t('Tables may fill columns this view does not show so the row matches the view; the row may not appear in this view.');
	}

	/**
	 * Note of an update of a column the view's filter uses.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function leavesViewNote(): string {
		return Translator::t('This change may take the row out of this view.');
	}

	/**
	 * Note of a write on a column that must hold unique values.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function uniqueNote(): string {
		return Translator::t('Tables checks unique values when it saves.');
	}

	/**
	 * Note and confirmation request of a table owned by somebody else.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function shared(): string {
		return Translator::t('This table is shared with you: the change is seen by everybody it is shared with.');
	}

	/**
	 * Message of a creation plan.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function createPlan(): string {
		return Translator::t('Nothing was saved yet: this is the row Tables would create.');
	}

	/**
	 * Message of an update plan.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function updatePlan(): string {
		return Translator::t('Nothing was saved yet: these are the cells Tables would change.');
	}

	/**
	 * Note of a receipt where Tables returned another value than the plan expected.
	 *
	 * @return string the message in the language of the current user
	 */
	public static function differs(): string {
		return Translator::t('Tables returned a different value for at least one cell; the values it returned are shown.');
	}

	/**
	 * Heading of the target in a plan.
	 *
	 * @param string $target the target, already made inert
	 * @return string the line in the language of the current user
	 */
	public static function planTarget(string $target): string {
		return Translator::t('Target: %s', [$target]);
	}

	/**
	 * One cell of a plan.
	 *
	 * @param string $column the column, already made inert
	 * @param string $before the value before, already made inert
	 * @param string $after the value after, already made inert
	 * @return string the line in the language of the current user
	 */
	public static function planCell(string $column, string $before, string $after): string {
		return Translator::t('%1$s: %2$s → %3$s', [$column, $before, $after]);
	}

	/**
	 * Text shown for an empty value in a plan.
	 *
	 * @return string the text in the language of the current user
	 */
	public static function emptyValue(): string {
		return Translator::t('(empty)');
	}

	/**
	 * Text shown for a long value in a plan.
	 *
	 * @param string $start the start of the value, already made inert
	 * @param int $length its length in characters
	 * @return string the text in the language of the current user
	 */
	public static function longValue(string $start, int $length): string {
		return Translator::t('%1$s (%2$s characters)', [$start, (string)$length]);
	}
}
