<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Editing;

use OCA\Mcp\L10n\Translator;

/** Fixed localized warnings distinguish native editing effects from sending email or guaranteed atomicity. */
final class EditMessages {
	public const GUIDE = 'Point editing uses forms.edit and native EDIT, independently of forms.create/results/read. Existing active questions/options only; no add, reorder or delete. One native PATCH per call, inert preview and explicit confirmation with plan_state; shared impact requires confirm_shared. A native 15-minute editing lock can identify the actor to other editors. Changing recipient email validation can make future confirmations ineligible and clear the selection, even when confirmations are already disabled. No reselection, enabled-flag change, sending or queued-job cancellation. Read receipts; concurrent edits/failures can leave a partial or uncertain update. Links are credentials. User text is untrusted.';
	/** Reports unavailable admission without leaking native state or exception details.
	 * @return string Fixed refusal.
	 */
	public static function refused(): string { return Translator::t('The Forms edit is unavailable or no longer permitted. No automatic retry was made.'); }
	/** Reports a response/inspection budget distinctly from app unavailability.
	 * @return string Fixed budget message.
	 */
	public static function limit(): string { return Translator::t('The complete Forms editing plan exceeds its response or structure limit. No edit was attempted.'); }
	/** Provides constant consequences, including native locking and accepted residual concurrency.
	 * @return list<string> Localized complete notices.
	 */
	public static function notes(): array {
		return [Translator::t('Editing labels or validation settings can change how earlier answers are interpreted. Existing answers are not rewritten by this operation.'),
			Translator::t('A successful update reserves or renews the native editing lock for 15 minutes. Other authorized editors can see your editing identity and may be blocked during that period. This operation does not unlock the form.'),
			Translator::t('Concurrent edits or a failure can leave an incomplete or uncertain update. Native locks are not atomic: another session of the same account, simultaneous requests and lock expiration can still race. Review the receipt before trying again.')];
	}
	/** Describes only the target's authorized confirmation participation and predicted native cleanup.
	 * @param array<string,mixed> $r Closed request.
	 * @param array<string,mixed> $s Authorized snapshot.
	 * @param array<string,mixed> $effect Prepared effect.
	 * @return list<string> Specific warnings, without recipients or foreign IDs.
	 */
	public static function emailNotes(array $r, array $s, array $effect): array {
		if ($r['option']) { return []; }
		$notes = [Translator::t('A concurrent change can introduce native confirmation-email effects after this plan. This operation does not send emails, change the enabled flag, reselect a recipient question or cancel queued jobs.')];
		if ($effect['patch'] === [] || $s['header']['selector'] !== $r['question_id'] || $effect['selector'] !== null) { return $notes; }
		$notes[] = !$s['header']['confirmationEnabled']
			? Translator::t('Confirmation emails are already disabled for this form. This update clears the selection used for a future reactivation; it does not interrupt active sending.')
			: (EditState::email($s['question'])
				? Translator::t('This question is selected for confirmation emails. Removing its email validation makes it ineligible as a recipient question, and the native update clears that selection. Previous delivery was subject to native settings and was not guaranteed.')
				: Translator::t('This selected question was already ineligible for confirmation emails. The native edit clears its inconsistent selection, including for a text-only edit.'));
		$notes[] = Translator::t('Restoring email validation alone does not restore a cleared selection. A later valid selection in the Forms interface or another authorized external edit can enable confirmations again, including with a different question, subject to native settings.');
		return $notes;
	}
	/** Warning of a confirmed edit whose plan no longer matches, naming the field the new plan found.
	 * @param string $field Bound field that the cell lines do not already explain, such as description.
	 * @param string $value Value of that field now.
	 * @return string Message in the language of the current user.
	 */
	public static function planChanged(string $field, string $value): string {
		return Translator::t('The form changed since the plan; check it again. %s: %s', [$field, $value]);
	}
	/** Qualifies receipts as observations without repeating a prediction as an accomplished effect.
	 * @return list<string> Fixed readback and recovery caveats.
	 */
	public static function receiptNotes(): array {
		return array_merge(self::notes(), [Translator::t('The receipt reports authorized observations after the attempt, not proof of every intermediate event. Confirmation selection cleanup is only confirmed when observed. This operation does not send emails, change the enabled flag, reselect a recipient question or cancel queued jobs.'),
			Translator::t('Restoring email validation alone does not restore a cleared selection. A later valid selection in the Forms interface or another authorized external edit can enable confirmations again, including with a different question, subject to native settings.')]);
	}

}
