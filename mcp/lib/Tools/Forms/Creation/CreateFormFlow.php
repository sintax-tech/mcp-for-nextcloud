<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Creation;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;

/** Repeats inert preflight at confirmation and hands only an approved new-object request to the writer. */
class CreateFormFlow {
	/** Receives focused admission, plan and native sequence collaborators.
	 * @param FormsCreateNativeFactory $factory Native graph and authorization.
	 * @param CreateFormPlan $plans Complete plans with existing HMAC.
	 * @param CreateFormWriter $writer Native sequence with honest partial receipts.
	 */
	public function __construct(private FormsCreateNativeFactory $factory, private CreateFormPlan $plans, private CreateFormWriter $writer) {
	}
	/** Prepares a creation without any native write.
	 * @param array<string,mixed> $arguments Submitted creation structure.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> Complete plan.
	 */
	public function preview(array $arguments, string $uid): array {
		[$request, $context] = $this->prepare($arguments, $uid);
		return $this->plans->of($request, $context, $uid);
	}
	/** Executes a confirmed request only when the fresh complete plan still matches.
	 * @param array<string,mixed> $arguments Validated arguments, confirm and plan_state.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> Untrusted receipt and explicit status.
	 * @throws PlanChanged Before S0 when the approved plan differs.
	 */
	public function handle(array $arguments, string $uid): array {
		if (($arguments['confirm'] ?? null) !== true) { throw new ToolFailure(CreateFormMessages::refused()); }
		PlanState::require($arguments);
		[$request, $context] = $this->prepare($arguments, $uid);
		$plan = $this->plans->of($request, $context, $uid);
		if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) { throw new PlanChanged($this->plans->of($request, $context, $uid, true)); }
		$receipt = $this->writer->write($context, $request, $uid);
		return ToolResult::json($receipt + ['notice' => FormsMessages::NOTICE]) + ($receipt['status'] === 'complete' ? [] : ['isError' => true]);
	}
	/** Validates all questions/settings before the first native sink, under the current creation policy.
	 * @param array<string,mixed> $arguments Submitted schema.
	 * @param string $uid Authenticated actor.
	 * @return array{array<string,mixed>,CreateFormContext} Inert admitted request and graph.
	 */
	private function prepare(array $arguments, string $uid): array {
		$request = CreateFormRequest::of($arguments);
		$context = $this->factory->open($uid);
		foreach ($request['questions'] as $index => $q) {
			try { $valid = $context->service->areExtraSettingsValid($q['settings'], $q['type']); }
			catch (\Throwable $error) { throw new ToolFailure(CreateFormMessages::refused(), 0, $error); }
			if ($valid !== true) { throw new ArgumentValidationException('Invalid native settings', 'questions.' . $index, 'must match native Forms settings'); }
		}
		return [$request, $context];
	}
}
