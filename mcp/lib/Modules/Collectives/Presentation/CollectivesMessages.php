<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * Every string of the Collectives module.
 *
 * Tool and parameter descriptions are read by the model, so they are fixed English constants. What the person can
 * see (refusals, plan notices, receipts) is a static method that translates an English source text through
 * {@see Translator}, in the language of the authenticated user.
 */
final class CollectivesMessages {
	/* Tool descriptions, exposed to MCP clients in tools/list. */
	public const TOOL_LIST_DESCRIPTION = 'Lists the collectives (Collectives app) the user is a member of, with what the user may do in each.';
	public const TOOL_PAGES_DESCRIPTION = 'Lists the pages of one collective, optionally only the subpages of one page.';
	public const TOOL_READ_DESCRIPTION = 'Reads the Markdown, etag and metadata of one page of a collective.';
	public const TOOL_SEARCH_DESCRIPTION = 'Searches the pages of one collective by title, or by content through the Collectives index.';
	public const TOOL_CREATE_DESCRIPTION = 'Creates an empty page under a parent page of a collective; edit it afterwards to add content.';
	public const TOOL_EDIT_DESCRIPTION = 'Changes the Markdown of one page with one of: append (add at the end), old/new (replace one '
		. 'unique passage) or content (rewrite it whole), confirmed with the etag and plan_state of the plan.';
	public const TOOL_MOVE_DESCRIPTION = 'Moves a page under another parent page of the same collective, renames it, or both.';

	/* Parameter descriptions. */
	public const PARAM_COLLECTIVE_ID = 'Id of the collective, as collectives_list returns it.';
	public const PARAM_PAGE_ID = 'Id of the page, as collectives_pages returns it.';
	public const PARAM_PARENT_FILTER = 'Only the subpages (at any depth) of this page; omit for every page.';
	public const PARAM_CURSOR = 'next_cursor of the previous answer, to read the next slice.';
	public const PARAM_LIMIT = 'Maximum number of items to return (1-100, default 50).';
	public const PARAM_QUERY = 'Text to find (1-200 characters).';
	public const PARAM_SCOPE = 'title (default) matches page titles; content uses the Collectives search index.';
	public const PARAM_CREATE_PARENT = 'Id of the parent page; 0 for the landing page of the collective.';
	public const PARAM_TITLE = 'Title of the page (1-200 characters).';
	public const PARAM_CONTENT = 'New complete Markdown; only to rewrite the whole page.';
	public const PARAM_APPEND = 'Markdown added at the end, on a new line.';
	public const PARAM_OLD = 'Passage to replace; must appear exactly once.';
	public const PARAM_NEW = 'Text that replaces old.';
	public const PARAM_ETAG = 'ETag read before; when it differs, nothing is changed.';
	public const PARAM_MOVE_PARENT = 'Id of the new parent page of the same collective; omit to keep the current parent. '
		. 'To move to the top level, give the id of the landing page.';
	public const PARAM_NEW_TITLE = 'New title; omit to keep the current one.';
	public const PARAM_INDEX = 'Position among the subpages of the new parent (0 = first, the default); only with a new parent.';

	/** Constant holder and static helpers only; never instantiated. */
	private function __construct() {
	}

	/** @return string Collectives missing, disabled or in a version this app does not support */
	public static function unavailable(): string {
		return Translator::t('Collectives is not available in a supported version.');
	}

	/** @return string the user's Collectives folder setting does not exist yet */
	public static function folderNotReady(): string {
		return Translator::t('Collectives is not set up for this account yet; open Collectives once in the browser, then try again.');
	}

	/**
	 * @param int $max limit in bytes
	 * @return string a page over the read or write limit
	 */
	public static function pageTooLarge(int $max): string {
		return Translator::t('Page exceeds the limit of %d bytes.', [$max]);
	}

	/** @return string a target that is not a Markdown page */
	public static function notMarkdown(): string {
		return Translator::t('Only Markdown pages (.md) of a collective can be written.');
	}

	/** @return string content that is not valid UTF-8 */
	public static function invalidUtf8(): string {
		return Translator::t('The page content must be valid UTF-8 text.');
	}

	/** @return string more than one of content, append and old/new */
	public static function editModesExclusive(): string {
		return Translator::t('Use only one of content, append or old/new.');
	}

	/** @return string a confirmed edit without the etag of its plan */
	public static function editNeedsEtag(): string {
		return Translator::t('Send the etag of the plan to confirm this edit.');
	}

	/**
	 * @param string $etag etag the page has now
	 * @return string the page changed after it was read
	 */
	public static function changedSinceRead(string $etag): string {
		return Translator::t('The page changed since it was read; nothing was written. Current etag: %s', [$etag]);
	}

	/** @return string a folder of pages without its index page */
	public static function indexMissing(): string {
		return Translator::t('A folder of this collective has pages but no Readme.md, and reading it would make Collectives create one; nothing was read or changed. Open the collective in Collectives once, then try again.');
	}

	/** @return string a move would carry something MCP may not change */
	public static function carriesHiddenContent(): string {
		return Translator::t('This page carries subpages or files you cannot change through MCP; nothing was moved.');
	}

	/** @return string moving the landing page */
	public static function rootNotMovable(): string {
		return Translator::t('The landing page of a collective cannot be moved.');
	}

	/** @return string moving a page under itself or a subpage */
	public static function cycle(): string {
		return Translator::t('A page cannot be moved under itself or one of its subpages.');
	}

	/** @return string index without a new parent */
	public static function indexNeedsParent(): string {
		return Translator::t('index only applies when the page moves to another parent; reordering within the same parent is not supported.');
	}

	/** @return string a content search the installed version cannot run */
	public static function contentSearchUnavailable(): string {
		return Translator::t('Content search is not available in this Collectives version; search by title instead.');
	}

	/** @return string a cursor that is not the one this listing gave */
	public static function invalidCursor(): string {
		return Translator::t('Invalid or expired cursor; start the listing again without it.');
	}

	/** @return string notice of every content search */
	public static function searchIndexNotice(): string {
		return Translator::t('Content search uses the Collectives index, which may lag behind recent changes or be unavailable; an empty result does not prove there is no match.');
	}

	/**
	 * @param int $budget most native results asked for
	 * @return string the native search may have stopped at its budget
	 */
	public static function searchCapped(int $budget): string {
		return Translator::t('The Collectives index returned its maximum of %d results; more pages may match.', [$budget]);
	}

	/** @return string generic heading of a Collectives plan */
	public static function planNotice(): string {
		return Translator::t('Nothing was changed. Show this plan to the user and repeat the same call with confirm: true and the plan_state only after they agree.');
	}

	/** @return string a leaf parent becomes a folder */
	public static function parentBecomesFolder(): string {
		return Translator::t('The parent page has no subpages yet: Collectives turns it into a folder with a Readme.md, so its file path changes (its id stays).');
	}

	/** @return string the title may get a suffix */
	public static function titleMayChange(): string {
		return Translator::t('If another page under the same parent has this title, Collectives picks a free file name.');
	}

	/** @return string native notifications */
	public static function membersNotified(): string {
		return Translator::t('Collectives may notify the members of the collective and update open browsers.');
	}

	/** @return string links by path */
	public static function linksByPath(): string {
		return Translator::t('Links to this page by id keep working; links written with its old path may break.');
	}

	/** @return string no coordination with an open editor */
	public static function noTextCoordination(): string {
		return Translator::t('If someone has this page open in the editor, their session may write over this change; there is no compare-and-swap with the collaborative editor.');
	}

	/** @return string nothing would change */
	public static function nothingChanges(): string {
		return Translator::t('Nothing would change: the page already has this parent and title.');
	}

	/** @return string receipt after an observed change */
	public static function applied(): string {
		return Translator::t('Change observed by reading the collective again.');
	}

	/** @return string receipt when the readback differs from the request */
	public static function partial(): string {
		return Translator::t('Collectives answered, but reading the collective again shows only part of the requested change.');
	}

	/** @return string receipt when the readback was not possible */
	public static function uncertain(): string {
		return Translator::t('The change was sent to Collectives but could not be read back; check the page before trying again. Nothing is retried automatically.');
	}
}
