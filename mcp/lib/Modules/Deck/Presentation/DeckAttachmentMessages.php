<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;

/** Keeps the existing Deck attachment text unchanged. */
final class DeckAttachmentMessages {
	/**
	 * Name an archived source only after the caller can already see that card or board.
	 *
	 * @return string Localized file association message.
	 */
	public static function fileSourceInactive(): string {
		return Translator::t('An archived card or board cannot gain or lose a file association.');
	}

	/**
	 * Name a folder only when every alias is a folder the caller can already see.
	 *
	 * @return string Localized file association message.
	 */
	public static function fileFolderRefused(): string {
		return Translator::t('Folders cannot be linked or unlinked here. Choose a file.');
	}

	/**
	 * Keep a partial or uncertain outcome from being read as the whole request or as human approval.
	 *
	 * The sentence does not deny a step that was already verified.
	 *
	 * @return string Localized sentence appended to plan-changed and uncertain outcomes.
	 */
	public static function doesNotProveTheWholeRequest(): string {
		return Translator::t('This message does not prove that the whole request finished, and it is not a record of human approval.');
	}

	/** @return string Localized file association message. */
	public static function filePlanChanged(): string {
		return Translator::t('The file association state changed. Request a new plan before confirming.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/** @return string Localized file association message. */
	public static function fileUnconfirmed(): string {
		return Translator::t('The file association result could not be confirmed. Check the Deck interface; do not repeat the unlink automatically.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/** @return string Localized file association message. */
	public static function fileUnlinkObserved(): string {
		return Translator::t('The association was absent after a native failure. Another actor may have removed it; this does not prove which operation removed it.')
			. ' ' . \OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::doesNotProveTheWholeRequest();
	}

	/** @return string Localized file association message. */
	public static function fileUnlinkWarning(): string {
		return Translator::t('The original file is preserved. Unlinking revokes this association for all current and future card members, including when another member created it. Independent shares may still provide access. This removes only a native file share, never a legacy upload or folder.');
	}

	/** @return string Exactly the native denied/missing public refusal; no hidden-association marker. */
	public static function fileUnavailable(): string {
		return \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorNotFoundOrForbidden();
	}

	/** Constant and translated-message holder. */
	private function __construct() {
	}
}
