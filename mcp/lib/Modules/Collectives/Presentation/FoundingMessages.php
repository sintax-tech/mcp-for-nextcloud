<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * Every string of creating a collective. Descriptions read by the model are fixed English constants; what the person
 * sees is translated through {@see Translator}, like {@see CollectivesMessages}.
 */
final class FoundingMessages {
	public const TOOL_DESCRIPTION = 'Creates a new collective (Collectives app) with its own new team, owned by the user, and its '
		. 'landing page. Returns a plan first; confirm it with confirm: true and the plan_state.';
	public const PARAM_NAME = 'Name of the new collective (3-127 bytes after the spaces and characters Collectives removes); '
		. 'it must not be the name of a collective or team the user already sees.';
	public const PARAM_EMOJI = 'One emoji shown with the collective; omit for none.';

	/** Constant holder and static helpers only; never instantiated. */
	private function __construct() {
	}

	/** @return string guest accounts may not create collectives */
	public static function guestsCannotCreate(): string {
		return Translator::t('Guest accounts cannot create collectives.');
	}

	/** @return string the actor has no Teams identity yet */
	public static function teamsNotReady(): string {
		return Translator::t('Teams is not set up for this account yet; open Collectives or Teams once in the browser, then try again.');
	}

	/** @return string control characters in the name */
	public static function nameControlCharacters(): string {
		return Translator::t('The name may only use plain spaces between words: no tabs, line breaks or other control characters.');
	}

	/** @return string four-byte characters the database cannot keep */
	public static function nameFourBytes(): string {
		return Translator::t('The database of this server cannot keep emoji or other four-byte characters in the name; remove them or use the emoji parameter.');
	}

	/** @return string nothing would remain of the name */
	public static function nameEmpty(): string {
		return Translator::t('Nothing would remain of this name after Collectives removes the characters it does not allow in names.');
	}

	/**
	 * @param string $stored what would be stored after one pass
	 * @return string the name would change again when stored
	 */
	public static function nameUnstable(string $stored): string {
		return Translator::t('This name would change again when Collectives stores it; send “%s” instead.', [$stored]);
	}

	/**
	 * @param int $min shortest name in bytes
	 * @param int $max longest name in bytes
	 * @return string the name is out of bounds
	 */
	public static function nameLength(int $min, int $max): string {
		return Translator::t('The name must have between %d and %d bytes.', [$min, $max]);
	}

	/** @return string the emoji is not one Collectives accepts */
	public static function invalidEmoji(): string {
		return Translator::t('The emoji must be one single emoji; omit it to create the collective without one.');
	}

	/**
	 * @param string $name the name
	 * @return string a collective or team the actor sees has this name
	 */
	public static function nameTaken(string $name): string {
		return Translator::t('A collective or team you can see is already called “%s”; nothing was created. Choose another name.', [$name]);
	}

	/**
	 * @param string $name the name as stored
	 * @return string the name is stored differently than given
	 */
	public static function storedAs(string $name): string {
		return Translator::t('Collectives stores the name as “%s”.', [$name]);
	}

	/** @return string what is created */
	public static function createsTeam(): string {
		return Translator::t('Collectives creates a new team with you as its owner and only member, and marks it as managed by Collectives, using an internal privilege of Teams, as it does in the browser.');
	}

	/** @return string the folder and landing page */
	public static function createsFolder(): string {
		return Translator::t('Collectives creates the folder of the collective with a welcome page, Readme.md, in your language.');
	}

	/** @return string nobody is invited */
	public static function nobodyInvited(): string {
		return Translator::t('Nobody else is added, invited or sent an email.');
	}

	/**
	 * @param string $name the name
	 * @return string the declared adoption race (decision D6)
	 */
	public static function adoptionPossible(string $name): string {
		return Translator::t('If, between this confirmation and the creation, a team called “%s” appears that you administer, Collectives creates the collective for that team instead: its current members get access to the collective and the team becomes managed by Collectives. The name is checked again right before.', [$name]);
	}

	/** @return string adoption may stay unknown after a failure */
	public static function adoptionUnknowable(): string {
		return Translator::t('If the creation fails halfway, it may not be possible to tell whether an existing team was used.');
	}

	/** @return string Teams may suffix the display name */
	public static function nameMaySuffix(): string {
		return Translator::t('If another team, even one you cannot see, already uses this display name, Teams adds “ (2)” to it.');
	}

	/** @return string Teams on Nextcloud 35 may add a team folder */
	public static function teamFolderPossible(): string {
		return Translator::t('On this server Teams may also create a team folder for the new team, depending on how the administrator set up team folders.');
	}

	/** @return string receipt: an existing team was used */
	public static function adopted(): string {
		return Translator::t('Collectives created the collective for an existing team instead of a new one; its members have access. Check its members in Collectives.');
	}

	/** @return string receipt: something is missing */
	public static function incomplete(): string {
		return Translator::t('The collective exists, but reading it back did not find everything Collectives creates; nothing was repaired. Open it in Collectives to finish.');
	}

	/** @return string receipt: the native call failed */
	public static function failedHalfway(): string {
		return Translator::t('Collectives failed while creating the collective; what it left behind is listed, nothing was repaired or retried, and whether an existing team was used cannot be told.');
	}

	/** @return string receipt: the native call failed and nothing new was found */
	public static function failedNothingSeen(): string {
		return Translator::t('Collectives failed and no new collective or team with this name was found; nothing is retried. Check Collectives before trying again.');
	}
}
