<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Creation;

use OCA\Mcp\L10n\Translator;

/** Defines fixed creation messages without native exception or Files metadata. */
final class CreateFormMessages {
	public const GUIDE = 'forms_create_form requires forms.create and native creation permission. It creates a private form and its initial questions/options only in Forms 5.3.0, 5.3.7, 5.4.0 or 5.4.1. Preview is inert and confirmation repeats admission. It never edits caller-supplied IDs, publishes, shares, submits or sends mail. Native title synchronization may affect Files in 5.4.1; detected collisions stop after the empty form was created, and concurrent native effects remain possible. No extra Files grant or lock, rollback, retry or deduplication is implied.';
	/** Localizes the constant native-effects warning shown in every plan.
	 * @return string Warning without observed folder data.
	 */
	public static function warning(string $version): string {
		if ($version === '5.4.1') {
			return Translator::t('Creation follows the installed Forms app’s native behavior. In Forms %s, setting the title can rename or merge matching upload folders and remove empty folders with the new form ID prefix. Detected matches stop the title step, leaving a partially created form. Concurrent changes can still trigger native folder changes; these checks do not guarantee that Files remain unchanged.', [$version]);
		}
		return Translator::t('Creation follows the installed Forms app’s native behavior (Forms %s). Setting the title does not rename or merge upload folders in this version. Creation can still leave a partial form. These checks do not guarantee that Files remain unchanged.', [$version]);
	}
	/** Warning of a confirmed create whose plan no longer matches, naming the installed Forms version.
	 * @param string $version Installed Forms release the new plan was built on.
	 * @return string Message in the language of the current user.
	 */
	public static function planChanged(string $version): string {
		return Translator::t('The creation plan changed. Review the new plan before confirming. nativeVersion: %s', [$version]);
	}
	/** Describes the non-atomic sequence and recovery limits.
	 * @return string Fixed sequence note.
	 */
	public static function sequence(): string { return Translator::t('Creation is a sequence of native writes, not a transaction. Failures may leave a partial form. No automatic retry, rollback or deduplication is performed. Links are credentials.'); }
	/** Refuses unavailable creation context or native permission without private details.
	 * @return string Safe refusal.
	 */
	public static function refused(): string { return Translator::t('Form creation is not available for this user or installed Forms version.'); }
	/** Describes a stopped sequence without claiming that an unobserved write did not occur.
	 * @return string Safe partial-result explanation.
	 */
	public static function stopped(): string { return Translator::t('Form creation stopped. Only the observed Forms changes in this receipt are confirmed; the interrupted stage may be incomplete. Do not retry automatically.'); }
	/** Explains a pre-title refusal after the empty form was observed.
	 * @return string Partial-result explanation without a Files existence oracle.
	 */
	public static function precheck(): string { return Translator::t('The title safety check could not admit the next stage. An empty form was observed before this stage; this call did not apply its title or initial questions. Its current state may have changed.'); }
	/** Names the native throttle outcome without exposing OCS headers or claiming zero earlier effects.
	 * @return string Safe limit explanation.
	 */
	public static function throttled(): string { return Translator::t('The native Forms attempt limit was reached. Earlier observed changes remain; the interrupted stage may be incomplete. Do not retry automatically.'); }
}
