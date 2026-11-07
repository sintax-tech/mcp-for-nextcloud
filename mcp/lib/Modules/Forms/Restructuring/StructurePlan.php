<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Editing\EditValues;
use OCA\Mcp\Modules\Forms\Presentation\FormsMessages;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Modules\Forms\Presentation\StructurePlanRenderer;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Builds the complete public plan of a structure operation and binds it, the snapshot and the effect to an authenticated
 * plan_state. A plan that does not fit the budget is refused whole; a shortened plan is never signed.
 */
class StructurePlan {
	/** Largest plan, in bytes of JSON and of rendered text. */
	public const MAX_BYTES = 131072;

	/** @param PlanState $states authenticated plan_state signer */
	public function __construct(private PlanState $states) {
	}

	/**
	 * @param StructureOperation $op operation
	 * @param array<string,mixed> $r validated request
	 * @param EditContext $c admitted native graph
	 * @param array<string,mixed> $s fresh snapshot
	 * @param array<string,mixed> $e predicted effect
	 * @param string $uid actor
	 * @param bool $changed whether this plan answers a changed state
	 * @return array<string,mixed> the plan, with plan_state
	 * @throws ToolFailure when the plan exceeds its budget
	 */
	public function of(StructureOperation $op, array $r, EditContext $c, array $s, array $e, string $uid, bool $changed = false): array {
		$plan = ['action' => $op->name(), 'target' => $e['target'] + ['formId' => $r['form_id'], 'title' => $s['header']['title']],
			'requested' => array_diff_key($r, ['form_id' => true]), 'before' => $e['before'], 'after' => $e['after'],
			'noChange' => $e['noop'], 'effects' => $e['effects'], 'irreversible' => $e['irreversible'], 'answers' => $e['answers'],
			'shared' => $s['shared'], 'nativeVersion' => $c->version,
			'lock' => ['nativeDurationSeconds' => 900, 'obtainedByPreview' => false], 'confirmation' => $e['confirmation'],
			'notes' => StructureMessages::planNotes(), 'notice' => FormsMessages::NOTICE];
		EditValues::json($plan);
		$plan[PlanState::ARGUMENT] = $this->states->of($op->name(), $uid, EditValues::canonical(['plan' => $plan, 'state' => $s, 'effect' => $e]));
		if ($changed) {
			$plan['message'] = StructureMessages::planChanged();
		}
		if (strlen(EditValues::json($plan)) > self::MAX_BYTES || strlen((new StructurePlanRenderer())->render($plan)) > self::MAX_BYTES) {
			throw new ToolFailure(StructureMessages::limit());
		}
		return $plan;
	}
}
