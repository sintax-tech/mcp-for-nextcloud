<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

use OCA\Mcp\L10n\Translator;

/** Texts of the Forms structure tools: guide note, refusals, plan effects and receipt notes. */
final class StructureMessages {
	/** Guide note, appended after the existing Forms notes. */
	public const GUIDE = 'Structure tools also use forms.edit and native EDIT: add questions (at the end or at a position) and options, reorder the complete set of questions or of one option partition, delete a question (logically, answers orphaned, no restore) or an option (permanently), and change a question type (refused when the installed Forms version disallows it; never to datetime or grid; settings are validated by us, answers and options are not converted). Each makes one native call after an inert preview and explicit confirmation with plan_state; shared forms require confirm_shared. Deleting a file question lets Forms queue a job that may delete its upload folders; files are not inspected. Read receipts; concurrent edits or failures can leave partial or uncertain effects.';

	/** Static texts only: prevents instantiation. */
	private function __construct() {
	}

	/** @return string generic refusal, without detail */
	public static function refused(): string {
		return Translator::t('The Forms structure change is unavailable or no longer permitted. No automatic retry was made.');
	}

	/** Names an installed release whose structure contract has not been proved.
	 * @param string $version Installed Forms version.
	 * @return string Version-specific refusal.
	 */
	public static function unsupportedVersion(string $version): string {
		return Translator::t('Structure changes are not available for the installed Forms version (%s).', [$version]);
	}

	/** Refuses a type change before entering the native controller or acquiring its lock.
	 * @param string $version Installed Forms version.
	 * @return string Refusal stating that no change was attempted.
	 */
	public static function typeChangeUnavailable(string $version): string {
		return Translator::t('Forms %s does not allow changing a question type. No change was attempted.', [$version]);
	}

	/** @return string refusal for an archived form */
	public static function archived(): string {
		return Translator::t('This form is archived and cannot be modified. No change was attempted.');
	}

	/** @return string refusal for a form locked by another editor */
	public static function locked(): string {
		return Translator::t('This form is currently being edited by another user. No change was attempted; try again later.');
	}

	/** @return string refusal for a structure or plan over its limit */
	public static function limit(): string {
		return Translator::t('The form structure or the complete plan exceeds its limit. No change was attempted.');
	}

	/** @return string notice of a plan rebuilt on a changed state */
	public static function planChanged(): string {
		return Translator::t('The form changed since the plan. Review this new plan before confirming again.');
	}

	/** @return string refusal of grid as a type-change target */
	public static function gridTarget(): string {
		return Translator::t('A question cannot be changed to grid in one native update. Create a new grid question with forms_add_question and grid_type instead.');
	}

	/**
	 * @param list<string> $keys current settings the target type does not accept
	 * @return string refusal asking for explicit final settings
	 */
	public static function settingsRequired(array $keys): string {
		return Translator::t('The current settings are not valid for the target type (%s). Send the complete final settings, or settings: {} to clear them; nothing is removed silently.', [implode(', ', $keys)]);
	}

	/** @return string refusal when native rejects the final settings for the current type */
	public static function settingsCurrentType(): string {
		return Translator::t('Forms validates settings against the current type before changing it, and rejects these. Adjust the settings first with forms_update_question, or send settings: {} and configure them after the change.');
	}

	/** @return string refusal of settings sent with the question's current type, which this tool never applies */
	public static function sameTypeSettings(): string {
		return Translator::t('The question already has this type, so nothing would change and these settings would not be applied. Change settings with forms_update_question.');
	}

	/** @return list<string> notes of every structure plan */
	public static function planNotes(): array {
		return [Translator::t('A successful change reserves or renews the native editing lock for 15 minutes. Other authorized editors can see your editing identity and may be blocked during that period. This preview did not take the lock, and the operation does not unlock the form.'),
			Translator::t('Concurrent edits or a failure can leave an incomplete or uncertain change. Native locks are not atomic. There is no automatic retry or rollback; review the receipt before trying again.')];
	}

	/** @return list<string> notes of every structure receipt */
	public static function receiptNotes(): array {
		return [...self::planNotes(), Translator::t('The receipt reports authorized observations after the attempt, not proof of every intermediate event. This operation does not send emails, change the confirmation flag, reselect a recipient question or cancel queued jobs.')];
	}

	/** @return string effect of adding a question */
	public static function addQuestionNoOptions(): string {
		return Translator::t('The new question has no options. Dropdown, multiple choice, ranking and grid questions cannot be answered until options are added with forms_add_option.');
	}

	/**
	 * @param int $count questions moved down
	 * @return string effect of inserting at a position
	 */
	public static function questionsShift(int $count): string {
		return Translator::t('%s existing questions move down one position.', [(string)$count]);
	}

	/** @return string effect of adding options */
	public static function optionsAppended(): string {
		return Translator::t('The options are appended at the end of the partition, in the given order. Existing options and answers are not changed.');
	}

	/** @return string warning about legacy untyped options */
	public static function legacyOptions(): string {
		return Translator::t('This question has older options without a type. Forms orders new options only among typed choices, so positions can tie; use forms_reorder_options afterwards if needed.');
	}

	/** @return string effect of a reorder */
	public static function reorderNotAtomic(): string {
		return Translator::t('Forms writes the new order item by item, without a transaction. A failure can leave a partial order.');
	}

	/** @return string effect of deleting a question */
	public static function questionSoftDelete(): string {
		return Translator::t('The question is deleted logically: it disappears from the form and later questions move up. There is no restore through MCP or the API used.');
	}

	/** @return string effect on existing answers */
	public static function answersOrphaned(): string {
		return Translator::t('Answers may exist. They are not counted or read here; they stay stored but orphaned, and results or exports may no longer show them as before.');
	}

	/** @return string effect of deleting a file question */
	public static function uploadFoldersJob(): string {
		return Translator::t('This is a file question. Forms queues a background job that may delete its upload folders asynchronously. Files is not inspected, completion is not confirmed and the files may not be recoverable.');
	}

	/** @return string effect of deleting an option */
	public static function optionHardDelete(): string {
		return Translator::t('The option is deleted permanently and cannot be restored.');
	}

	/** @return string native renumbering after deleting an option */
	public static function optionsRenumbered(): string {
		return Translator::t('Forms then renumbers every remaining option of the question from 1, across grid rows and columns.');
	}

	/** @return string warning when the last option is deleted */
	public static function questionWithoutOptions(): string {
		return Translator::t('The question will have no options left and cannot be answered until options are added.');
	}

	/** @return string effect of a type change on answers */
	public static function answersNotConverted(): string {
		return Translator::t('Existing answers are not converted and may be interpreted differently under the new type.');
	}

	/** @return string effect of a type change on options the new type does not use */
	public static function optionsHidden(): string {
		return Translator::t('The new type does not use options. Existing options stay stored but are not shown.');
	}

	/** @return string effect of a type change on options the new type uses */
	public static function optionsKept(): string {
		return Translator::t('Existing options are kept with their IDs and order.');
	}

	/** @return string effect of changing a file question */
	public static function uploadsUntouched(): string {
		return Translator::t('Files already uploaded to this question are not touched and no cleanup job is queued.');
	}
}
