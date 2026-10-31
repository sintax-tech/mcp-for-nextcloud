<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Creation;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/** Binds the complete admitted structure, native capability, defaults and constant warnings to confirmation. */
final class CreateFormPlan {
	/** Receives the existing stateless HMAC mechanism.
	 * @param PlanState $states Instance-secret-bound plan states.
	 */
	public function __construct(private PlanState $states) {
	}
	/** Builds a complete plan without native creation, generated IDs, locks or stored approvals.
	 * @param array<string,mixed> $request Normalized complete structure.
	 * @param CreateFormContext $context Admitted current native graph.
	 * @param string $uid Authenticated actor.
	 * @param bool $changed Whether confirmation found a different plan.
	 * @return array<string,mixed> Full inert structured plan.
	 */
	public function of(array $request, CreateFormContext $context, string $uid, bool $changed = false): array {
		$plan = ['action' => CreateFormTools::TOOL, 'before' => null, 'after' => $request, 'nativeVersion' => $context->version,
			'defaults' => ['private' => true, 'state' => 0, 'expires' => 0, 'isAnonymous' => false, 'submitMultiple' => false, 'allowEditSubmissions' => false, 'showExpiration' => false],
			'notes' => [CreateFormMessages::warning($context->version), CreateFormMessages::sequence()], 'notice' => FormsMessages::NOTICE];
		$plan[PlanState::ARGUMENT] = $this->states->of(CreateFormTools::TOOL, $uid, $plan);
		if ($changed) { $plan['warnings'] = [['message' => CreateFormMessages::planChanged($context->version)]]; }
		if (strlen(json_encode($plan, JSON_THROW_ON_ERROR)) > 1048576 || strlen((new CreateFormPlanRenderer())->render($plan) ?? '') > 1048576) {
			throw new ArgumentValidationException('Creation plan exceeds budget', 'arguments', 'exceeds the complete creation response budget');
		}
		return $plan;
	}
}
