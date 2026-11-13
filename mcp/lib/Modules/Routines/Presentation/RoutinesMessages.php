<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Presentation;

use OCA\Mcp\L10n\Translator;

/** The sentences of the meeting plan and receipt, in the account language. */
final class RoutinesMessages {
	/** Static messages only. */
	private function __construct() {
	}

	/** @return string the instruction of the plan */
	public static function planPrompt(): string {
		return Translator::t('Nothing was created. Show this plan to the user; with confirm: true the three steps run once, in this order.');
	}

	/** @return string where the link of the room goes */
	public static function linkPending(): string {
		return Translator::t('The link of the new room becomes the location of the event once the room exists.');
	}

	/** @return string what happens when a step does not succeed */
	public static function stopsAtFirstFailure(): string {
		return Translator::t('The meeting stops at the first step that does not succeed; what was created stays and nothing is retried or deleted.');
	}

	/** @return string the refusal of an event in a calendar of somebody else without confirmation */
	public static function sharedNeeded(): string {
		return Translator::t('The calendar belongs to somebody else: the event needs confirm_shared: true.');
	}

	/** @return string the outcome of a meeting whose three steps applied */
	public static function completed(): string {
		return Translator::t('The room, its agenda and the event were created.');
	}

	/**
	 * @param string $step the translated name of the step that did not succeed
	 * @return string the outcome of a meeting that stopped
	 */
	public static function stopped(string $step): string {
		return Translator::t('The meeting stopped at %s. What was created stays and is listed here; nothing was deleted. Do not repeat this call, it would create another room: ask the user how to continue and plan only the missing steps with the Talk and Calendar tools.', [$step]);
	}

	/**
	 * @param string $step the translated name of the step the preflight refused
	 * @return string the outcome of a meeting refused before anything was written
	 */
	public static function refused(string $step): string {
		return Translator::t('Nothing was created: %s would be refused.', [$step]);
	}

	/** @return string why a step did not start after an earlier one did not succeed */
	public static function notStarted(): string {
		return Translator::t('Not started: an earlier step did not succeed.');
	}

	/** @return string why a step did not start when the preflight refused another one */
	public static function notStartedRefused(): string {
		return Translator::t('Not started: the meeting was refused before anything was created.');
	}

	/** @return string why the event did not start when the room has no link */
	public static function eventWithoutLink(): string {
		return Translator::t('Not started: the link of the room could not be built, and the event would have no room to point to.');
	}

	/**
	 * @param string $step one of room, agenda or event
	 * @return string its name in the account language
	 */
	public static function stepName(string $step): string {
		return match ($step) {
			'room' => Translator::t('the room'),
			'agenda' => Translator::t('the agenda'),
			default => Translator::t('the event'),
		};
	}
}
