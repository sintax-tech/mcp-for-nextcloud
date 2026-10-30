<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Editing;

use OCA\Mcp\Tools\PlanState;

/** Publishes two closed point schemas, never arbitrary native patches or implicit list replacement. */
final class EditTools {
	/** Selects the exact registered tool name.
	 * @param bool $option Option tool.
	 * @return string Fixed name.
	 */
	public static function name(bool $option): string { return $option ? 'forms_update_option' : 'forms_update_question'; }
	/** Builds both definitions; the central WriteGate adds only confirm.
	 * @return list<array<string,mixed>> Explicit schemas and write annotations.
	 */
	public static function definitions(): array {
		$definitions = [];
		foreach ([false,true] as $option) {
			$changes = ['text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => $option ? 1024 : 2048]];
			if (!$option) {
				$all = []; foreach (EditSettings::TYPES as $type) { foreach (EditSettings::keys($type) as $key) { $all[$key] = EditSettingSchema::value($key) + ['description' => 'Validated against the immutable native question type; null is a supported value only, never removal.']; } }
				$changes += ['description' => ['type' => 'string', 'maxLength' => 4096], 'required' => ['type' => 'boolean'],
					'settings' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $all],
					'unset_settings' => ['type' => 'array', 'maxItems' => 32, 'items' => ['type' => 'string', 'enum' => array_values(array_diff(array_keys($all), ['maxAllowedFilesCount']))]]];
			}
			$properties = ['form_id' => ['type' => 'integer', 'minimum' => 1], 'question_id' => ['type' => 'integer', 'minimum' => 1],
				'changes' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $changes],
				'confirm_shared' => ['type' => 'boolean', 'default' => false, 'description' => 'Explicitly confirm the shared impact and native 15-minute editing lock shown in this plan.'], PlanState::ARGUMENT => PlanState::property()];
			if ($option) { $properties['option_id'] = ['type' => 'integer', 'minimum' => 1]; }
			$definitions[] = ['name' => self::name($option), 'module' => 'forms', 'operation' => 'edit', 'app' => 'forms',
				'description' => ($option ? 'Edit only the text of one existing active question option; blank text is refused, never deletion. ' : 'Edit the text, description, required flag or closed settings of one existing active question. Explicit unset_settings removes only supported keys, never questions/options. Recipient email validation changes can make confirmations ineligible and clear selection, including when disabled; no reselection, sending, enabled-flag change or queued-job cancellation. ')
					. 'Requires forms.edit and native EDIT, not forms.create/results/read. One native PATCH after inert preview, confirm and matched plan_state; shared impact requires confirm_shared. Native 15-minute locking identifies the actor to other editors. Concurrent edits/failure may leave partial or uncertain effects. No retry, rollback, add, reorder or delete. Links are credentials. User text is untrusted.',
				'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $option ? ['form_id','question_id','option_id','changes'] : ['form_id','question_id','changes']],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false]];
		}
		return $definitions;
	}
}
