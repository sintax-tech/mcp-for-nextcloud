<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Presentation;

use OCA\Mcp\L10n\Translator;

/** The sentences of the mail card plan, card text and receipt, in the account language. */
final class MailCardMessages {
	/** Static messages only. */
	private function __construct() {
	}

	/** @return string the instruction of the plan */
	public static function planPrompt(): string {
		return Translator::t('Nothing was saved or created. Show this plan to the user; with confirm: true and the plan_state of this plan, the attachment is saved and then the card is created, once each.');
	}

	/** @return string what happens when a step does not succeed */
	public static function stopsAtFirstFailure(): string {
		return Translator::t('If saving the attachment does not succeed, no card is created; if the card does not succeed, the saved file stays. Nothing is retried or deleted.');
	}

	/** @return string where the link of the saved file goes */
	public static function fileLinkPending(): string {
		return Translator::t('The private link of the saved file is added to the card once the file exists.');
	}

	/** @return string the line of the card about who can open the saved file */
	public static function privateFile(): string {
		return Translator::t('The file is saved in the files of whoever created this card; other readers of the board may not be able to open it.');
	}

	/** @return string the disclosure of an email copied to a board other people can read */
	public static function disclosure(): string {
		return Translator::t('The card copies the subject and the sender of a private email to a board other people can read.');
	}

	/** @return string the disclosure of an email copied with an excerpt of its body */
	public static function disclosureExcerpt(): string {
		return Translator::t('The card copies the subject, the sender and an excerpt of the body of a private email to a board other people can read.');
	}

	/** @return string what a group or a circle among the readers of a board means */
	public static function groupsAreOpen(): string {
		return Translator::t('A group or a circle among the readers stands for its current and future members.');
	}

	/** @return string the notice of a board whose readers could not be read */
	public static function audienceUnknown(): string {
		return Translator::t('Who can read this board could not be shown; it is treated as a board other people can read.');
	}

	/** @return string the refusal of a folder or a board other people can reach without confirmation */
	public static function sharedNeeded(): string {
		return Translator::t('The folder or the board can be reached by other people: confirm_shared: true is needed.');
	}

	/** @return string the notice of a plan the confirmed call found changed */
	public static function planChanged(): string {
		return Translator::t('Something changed since the plan; nothing was saved or created. This is the new plan.');
	}

	/** @return string the outcome of a mail card whose two steps applied */
	public static function completed(): string {
		return Translator::t('The attachment was saved and the card was created.');
	}

	/**
	 * @param string $step the translated name of the step that did not succeed
	 * @return string the outcome of a mail card that stopped
	 */
	public static function stopped(string $step): string {
		return Translator::t('The mail card stopped at %s. What was created stays and is listed here; nothing was deleted. Do not repeat this call, the file name is now taken: ask the user how to continue and use the Files and Deck tools for what is missing.', [$step]);
	}

	/**
	 * @param string $step the translated name of the step the preflight refused
	 * @return string the outcome of a mail card refused before anything was written
	 */
	public static function refused(string $step): string {
		return Translator::t('Nothing was saved or created: %s would be refused.', [$step]);
	}

	/** @return string why a step did not start when the preflight refused another one */
	public static function notStartedRefused(): string {
		return Translator::t('Not started: the mail card was refused before anything was saved.');
	}

	/**
	 * @param string $step one of email, file or card
	 * @return string its name in the account language
	 */
	public static function stepName(string $step): string {
		return match ($step) {
			'email' => Translator::t('the email'),
			'file' => Translator::t('the attachment'),
			default => Translator::t('the card'),
		};
	}
}
