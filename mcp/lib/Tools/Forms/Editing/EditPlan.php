<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Editing;

use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\Forms\FormsMessages;

/** Binds the complete point edit, relevant private guard state and warnings without publishing other users or selectors. */
class EditPlan {
	/** Receives the existing stateless authenticated plan mechanism.
	 * @param PlanState $states Instance-bound HMAC.
	 */
	public function __construct(private PlanState $states) { }
	/** Builds the full inert public plan and binds internal admission without exposing it.
	 * @param array<string,mixed> $r Closed request.
	 * @param EditContext $c Exact native graph.
	 * @param array<string,mixed> $s Authorized state.
	 * @param array<string,mixed> $e Prepared effect.
	 * @param string $uid Actor.
	 * @param bool $changed Whether a confirmed call found this instead of the approved plan.
	 * @return array<string,mixed> Complete plan.
	 */
	public function of(array $r, EditContext $c, array $s, array $e, string $uid, bool $changed = false): array {
		$before = []; $after = []; $target = $r['option'] ? $s['option'] : $s['question'];
		foreach ($r['changes'] as $key => $value) {
			if ($key === 'unset_settings') { continue; }
			$native = match ($key) { 'required' => 'isRequired', 'settings' => 'extraSettings', default => $key };
			$before[$key] = $target[$native]; $after[$key] = $e['expected'][$native];
		}
		if (($r['changes']['unset_settings'] ?? []) !== [] && !array_key_exists('settings', $before)) { $before['settings'] = $target['extraSettings']; $after['settings'] = $e['expected']['extraSettings']; }
		$plan = ['action' => EditTools::name($r['option']), 'target' => ['formId' => $r['form_id'], 'questionId' => $r['question_id'], 'title' => $s['header']['title'],
			'type' => $s['question']['type'], 'order' => $target['order']] + ($r['option'] ? ['optionId' => $r['option_id'], 'category' => $target['optionType']] : []),
			'before' => $before, 'after' => $after, 'requested' => $r['changes'], 'settingEffects' => EditSettingEffects::of($r), 'shared' => $s['shared'], 'nativeVersion' => $c->version,
			'confirmation' => $r['option'] ? null : ['selected_for_confirmation' => $s['header']['selector'] === $r['question_id'], 'confirmation_enabled_on_form' => $s['header']['confirmationEnabled'], 'confirmation_allowed_by_app' => $s['header']['confirmationAllowedByApp'],
				'eligible_recipient_question' => EditState::email($s['question']), 'selection_clear_predicted' => $e['patch'] !== [] && $s['header']['selector'] === $r['question_id'] && $e['selector'] === null],
			'notes' => array_merge(EditMessages::notes(), EditMessages::emailNotes($r, $s, $e)), 'notice' => FormsMessages::NOTICE];
		EditValues::json($plan);
		$plan[PlanState::ARGUMENT] = $this->states->of(EditTools::name($r['option']), $uid, EditValues::canonical(['plan' => $plan, 'guard' => $s, 'effect' => $e]));
		if ($changed) { $plan += EditPlanNotice::fields($r, $s); }
		if (strlen(EditValues::json($plan)) > 131072 || strlen((new EditPlanRenderer())->render($plan)) > 131072) { throw new \OCA\Mcp\Tools\ToolFailure(EditMessages::limit()); }
		return $plan;
	}
}
