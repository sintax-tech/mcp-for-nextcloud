<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Presentation;

use OCA\Mcp\L10n\Translator;

/** Translated texts of the absence tools: refusals, plan notices and the receipt error. */
final class AvailabilityMessages {
	/** Static texts only. */
	private function __construct() {}
	/** @return string absence feature turned off by the administrator */
	public static function turnedOff(): string { return Translator::t('Absence settings are turned off on this Nextcloud server.'); }
	/** @return string native read or write path unavailable */
	public static function unavailable(): string { return Translator::t('Your absence settings are unavailable.'); }
	/** @return string write grant revoked between the plan and the write */
	public static function notAllowed(): string { return Translator::t('Changing your absence through MCP is not allowed for your account.'); }
	/** @return string unknown replacement, the native 404 */
	public static function replacementNotFound(): string { return Translator::t('The replacement user was not found.'); }
	/** @return string stored replacement that no longer exists; never dropped silently */
	public static function replacementGone(): string {
		return Translator::t('The replacement user of your current absence no longer exists. Send replacement_user_id with another user, or null to remove the replacement.');
	}
	/** @return string display name the native column cannot hold */
	public static function replacementNameTooLong(): string { return Translator::t('The display name of this replacement user is longer than Nextcloud can store for an absence.'); }
	/** @return string advice of every plan */
	public static function planNotice(): string {
		return Translator::t('Nothing was changed. Show this plan to the user and repeat the same call with confirm: true and the plan_state only after they agree.');
	}
	/** @return string warning of a plan rebuilt because the state changed */
	public static function planChanged(): string { return Translator::t('Your absence or its replacement changed since the plan. Review this new plan before confirming again.'); }
	/** @return string effect on the calendar of a new absence */
	public static function calendarCreated(): string { return Translator::t('Nextcloud adds an out-of-office event for these days to your personal calendar.'); }
	/** @return string effect on the calendar of a changed absence */
	public static function calendarUpdated(): string { return Translator::t('Nextcloud updates the out-of-office event of this absence in your personal calendar.'); }
	/** @return string background jobs apply the absence later */
	public static function jobsApply(): string {
		return Translator::t('Nextcloud background jobs start the absence on the first day and end it after the last day; apps such as your user status react then.');
	}
	/** @return string native quirk when an earlier job time is not replaced */
	public static function staleJob(): string {
		return Translator::t('A start or end job already scheduled for the previous days may still run at its earlier time; Nextcloud only reschedules times that are still ahead.');
	}
	/** @return string replacement removed by this change */
	public static function replacementRemoved(): string { return Translator::t('The current replacement user is removed from your absence.'); }
	/** @return string this tool never clears an absence */
	public static function noClear(): string { return Translator::t('This tool cannot remove an absence; it only creates or changes one.'); }
	/** @return string the native call failed after the confirmation */
	public static function writeFailed(): string {
		return Translator::t('Nextcloud did not complete the absence change; nothing is retried. The absence below is what is stored now.');
	}
	/**
	 * @param string $first first day
	 * @param string $last last day
	 * @return string one-line plan of a new absence
	 */
	public static function planCreate(string $first, string $last): string { return Translator::t('Set your absence from **%s** to **%s**.', [$first, $last]); }
	/**
	 * @param string $first first day
	 * @param string $last last day
	 * @return string one-line plan of a changed absence
	 */
	public static function planUpdate(string $first, string $last): string { return Translator::t('Change your absence to **%s** – **%s**.', [$first, $last]); }
}
