<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls\Create;

use OCA\Mcp\L10n\Translator;

/** Fixed public warnings and tool descriptions; no upstream exception text. */
final class CreateMessages {
	/** English tool description. */
	public const TOOL = 'Create one private simple text or date poll owned by you, with 0 to 100 initial options. Requires the separate create grant. No shares, invitations, votes or comments. A plan and confirm are required; creation is not atomic and may remain partial.';
	/** Honest repeat/concurrency/effect warning used in both plan and receipt. */
	public const NOTE = 'Each confirmation may create another poll. Native activity, logs and notifications follow server settings. Creation and options are not atomic; failures can leave a poll or options behind. No automatic retry or rollback. Check Polls before repeating. Output limits do not provide a transaction or an exactly-once guarantee.';
	/**
	 * Localized stage-independent result explanation.
	 * @param bool $started whether a write might already have begun
	 * @return string fixed safe message
	 */
	public static function failure(bool $started): string {
		return $started ? Translator::t('Poll creation is uncertain; do not repeat automatically. Check Polls before trying again.')
			: Translator::t('Poll creation was refused before any write.');
	}
	/**
	 * Explains acknowledged partial progress without implying an uncertain pending sink.
	 * @return string fixed safe message
	 */
	public static function partial(): string {
		return Translator::t('Poll creation stopped after partial progress. Confirmed items are shown only while authorized. No automatic retry or rollback. Check Polls before repeating.');
	}
	/**
	 * Localizes public consequences without including submitted or upstream error text.
	 * @return list<string> fixed translated warnings
	 */
	public static function notes(): array {
		return [Translator::t('Each confirmation may create another poll. Native activity, logs and notifications follow server settings. Creation and options are not atomic; failures can leave a poll or options behind. No automatic retry or rollback. Check Polls before repeating. Output limits do not provide a transaction or an exactly-once guarantee.'),
			Translator::t('Defaults are predicted from the pinned native persistence contract, not from the returned object. Public readback is checked before options; an observable mismatch stops with an uncertain result. Description NULL and empty string, and unset timezone values, can be indistinguishable through native getters.')];
	}

}
