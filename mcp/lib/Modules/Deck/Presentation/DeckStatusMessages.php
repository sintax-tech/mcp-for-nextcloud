<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;

/** Focused status wording; no administration text or grant is added. */
final class DeckStatusMessages {
	public const DONE_DESCRIPTION = 'Sets a Deck card completed or open through its native service. Requires existing deck.edit and native EDIT. Preview first; confirm:true must repeat plan_state and confirm_shared:true for a received board. done:true uses the server completion time; done:false clears it and does not return the card to its previous list. On Deck 1.19 completion can automatically move to the done column shown in the plan. An already completed card is a no-op, never a retry of a partial move. Unarchive an archived card first. The final check-to-write race and partial native failures remain possible; read before retrying. Does not archive the card.';
	public const ARCHIVED_DESCRIPTION = 'Sets a Deck card archived or active through its native service, preserving completion. Requires existing deck.edit and native EDIT. Preview first; confirm:true must repeat plan_state and confirm_shared:true for a received board. Archive removes the card from active lists without deleting it. Unarchive is explicit. An already requested state is a no-op. Archived boards and deleted cards are refused. Completing and archiving require separate confirmed calls; there is no atomic combined operation. The final check-to-write race remains possible; read before retrying.';
	public const DONE_PARAMETER = 'true completes the card at the native server time; false reopens it without restoring its previous list';
	public const ARCHIVED_PARAMETER = 'true archives the card without completing or deleting it; false unarchives it without reopening it';

	/** @return string Refusal after native authorization of an inactive source. */
	public static function inactiveBoard(): string {
		return Translator::t('This board is archived or deleted. Change its state in Deck before changing card status.');
	}
	/** @return string Explicit MCP policy, more restrictive than native done on an archived card. */
	public static function archivedCard(): string {
		return Translator::t('Unarchive the card before completing or reopening it.');
	}
	/** @param string $field done or archived. @param bool $value Desired state. @return string Localized intent. */
	public static function intention(string $field, bool $value): string {
		if ($field === 'done') {
			return $value ? Translator::t('Complete the card at confirmation time (UTC).') : Translator::t('Reopen the card without returning it to its previous list.');
		}
		return $value ? Translator::t('Archive the card without completing or deleting it.') : Translator::t('Unarchive the card without changing its completion.');
	}
	/** @return string Warning for a completed card outside its current done destination. */
	public static function noMove(): string {
		return Translator::t('The card is already completed. This tool does not move completed cards or retry a partial move.');
	}
	/** @return string Honest preview and native concurrency limitation. */
	public static function limitations(): string {
		return Translator::t('Nothing was changed. Confirm the shown state before writing. Native permissions and the plan are checked again, but another client can still change the card or done column between the final check and the native write. Completing and archiving are separate operations.');
	}
	/** @return string Partial completion must not advertise success or invite a retry. */
	public static function partialMove(): string {
		return Translator::t('Completion is observed, but the planned automatic move is not confirmed. Read the card in Deck. This tool does not retry the move or undo the completion.');
	}
	/** @return string No configured unique native destination. */
	public static function noDestination(): string {
		return Translator::t('No unique done column is configured; this completion will not move the card.');
	}
	/** @return string State is already as requested. */
	public static function unchanged(): string {
		return Translator::t('The requested state is already set. Nothing will be changed.');
	}
}
