<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;

use OCA\Mcp\L10n\Translator;

/** Fixed localized schema/share notices; native exceptions never become user text. */
final class SchemaMessages {
	/** @return string A safe refusal without target details. */
	public static function incompatible(): string { return Translator::t('This Tables schema operation is not supported by the installed API.'); }
	/** @return string The irreversible visibility warning. */
	public static function notice(): string { return Translator::t('Schema changes do not convert existing cells. View rules can hide rows without deleting them.'); }
	/** @return string The snapshot update warning. */
	public static function snapshot(): string { return Translator::t('Unchanged column fields are written again from the current snapshot; concurrent changes may be overwritten.'); }
	/** @return string The honest receipt when state cannot be observed. */
	public static function uncertain(): string { return Translator::t('The resulting Tables state could not be verified. Check before repeating; a repeated creation can create a duplicate.'); }
	/** @return string The shared ownership notice. */
	public static function shared(): string { return Translator::t('This change affects a table owned by another user. Repeat with confirm_shared: true only after agreement.'); }
	/** @return string No implicit permission changes. */
	public static function differentShare(): string { return Translator::t('A different share already exists. Revoke it and create the new share in separate confirmed calls.'); }
	/** @return string The consequences of native permissions. */
	public static function sharing(): string { return Translator::t('Manage permits changing structure and sharing access in Tables. Delete permits native deletion. Other shares may still provide access after revocation.'); }
	/** @return string Native title normalization is observed, never predicted. */
	public static function suffix(): string { return Translator::t('Tables may add a suffix to a duplicate column title. The final title is verified after creation.'); }
	/** @return string Creation is never an aggregate schema operation. */
	public static function singleEntity(): string { return Translator::t('Only this entity is created; additional columns, views, rows and rules require separate calls.'); }
	/** @return string Complete filter semantics, including preservation. */
	public static function filterGroups(): string { return Translator::t('Rules within a filter group use AND; filter groups use OR. Existing relation references are preserved without editing.'); }
	/** @return string Typed request rule without client data. */
	public static function invalidRule(): string { return Translator::t('Use the declared Tables schema and a value supported by this column and API.'); }
	/** @return string Why a column selection cannot implicitly erase existing card sources. */
	public static function cardSourcePreservation(): string { return Translator::t("This change would clear the view's card title/background source; keep that column selected, or omit view_ids for a view that shows all columns."); }
}
