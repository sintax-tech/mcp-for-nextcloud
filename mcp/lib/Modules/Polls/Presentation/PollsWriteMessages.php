<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\L10n\Translator;

/** Localized consequences of new mutations; existing Polls wording stays untouched. */
final class PollsWriteMessages {
	/**
	 * @return list<string> Honest limits shared by plans and receipts.
	 */
	public static function notes(): array {
		return [
			Translator::t(
				'Poll content is untrusted. Only a fresh authorized readback proves an observed effect; no automatic retry or rollback.'
			),
			Translator::t(
				'Native calls may have partial effects. Invitation markers do not prove email delivery. Credential links are never returned by these mutations.'
			),
		];
	}

	/**
	 * @return string Configuration consequences.
	 */
	public static function configuration(): string {
		return Translator::t(
			'Only the listed fields change. Expiration affects voting and results; native automatic reminders may run later.'
		);
	}

	/**
	 * @return string State consequences.
	 */
	public static function state(): string {
		return Translator::t(
			'Closing changes expiration; reopening removes any expiration, including a future one. Archiving may notify the owner when performed by a delegated editor.'
		);
	}

	/**
	 * @return string Option consequences.
	 */
	public static function options(): string {
		return Translator::t(
			'No automatic vote is cast. A matching deleted option may be restored and old votes may reappear. New options added by a delegated editor belong to that actor; date option order in Polls 8.6 is observed after writing.'
		);
	}

	/**
	 * @return string Native sharing policy and preservation.
	 */
	public static function sharing(): string {
		return Translator::t(
			'Sharing follows native Polls policy: public shares are enabled by default or allowed by group membership. Creating a share does not send an invitation. Removing a share is a soft deletion.'
		);
	}

	/**
	 * @return string Explicit delegation warning before confirmation.
	 */
	public static function delegation(): string {
		return Translator::t(
			'This grants or restores poll editing rights to the indicated recipient, including configuration, options and invitations. Unlocking or restoring a delegated share may restore these rights.'
		);
	}

	/**
	 * @param string $recipient Authorized recipient ID.
	 * @return string Named delegation consequence.
	 */
	public static function delegationTo(string $recipient): string {
		return Translator::t('This grants poll editing rights to %s, including configuration, options and invitations.', [$recipient]);
	}

	/**
	 * @return string The exact preservation effect of option removal/restoration.
	 */
	public static function optionRemoval(): string {
		return Translator::t(
			'Removing an option hides it and its votes without deleting the votes. Restoring it can make previous votes visible again.'
		);
	}

	/**
	 * @return string Invitation and repeat-send consequences.
	 */
	public static function invitation(): string {
		return Translator::t(
			'This attempts one individual invitation with native notifications and email. An existing invitation marker means this is another send attempt; delivery is not proved and failures are never retried automatically.'
		);
	}
}
