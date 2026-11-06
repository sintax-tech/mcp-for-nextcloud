<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Confirmation\{PlanState, PlanChanged};
use OCA\Mcp\Mcp\Tools\Results\{ToolResult, ToolFailure};
use OCA\Mcp\Modules\Tables\Integration\{SchemaGateway, SchemaAction, TablesWriteCapabilities};
use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Stateless prepare/confirm/observe protocol shared by schema and sharing handlers. */
class SchemaFlow {
	/**
	 * @param SchemaGateway $gateway Native boundary.
	 * @param PlanState $states Existing stateless plan binding.
	 * @param GrantPolicy $grants Grants renewed on every phase.
	 * @param TablesSessionGuard $session Caller guard, including update_column.
	 * @param TablesWriteCapabilities $capabilities Fail-closed API profile.
	 * @param CapabilityRegistry $registry Request-scoped cached capability answers.
	 */
	public function __construct(private SchemaGateway $gateway, private PlanState $states, private GrantPolicy $grants,
		private TablesSessionGuard $session, private TablesWriteCapabilities $capabilities, private CapabilityRegistry $registry) {}
	/**
	 * @param SchemaAction $action Tool-specific action.
	 * @param array<string,mixed> $args Arguments.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Effect-free plan.
	 */
	public function preview(SchemaAction $action, array $args, string $uid): array {
		$p = $this->prepare($action, $args, $uid);
		return $this->plan($action, $p, $uid);
	}
	/**
	 * @param SchemaAction $action Tool-specific action.
	 * @param array<string,mixed> $args Arguments.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Confirmed effect or confirmation request.
	 */
	public function handle(SchemaAction $action, array $args, string $uid): array {
		if (($args['confirm'] ?? false) !== true) return ToolResult::json($this->preview($action, $args, $uid));
		PlanState::require($args);
		$p = $this->prepare($action, $args, $uid); $plan = $this->plan($action, $p, $uid);
		if (!PlanState::matches($plan['plan_state'], $args)) throw new PlanChanged($plan);
		if ($p['shared'] && ($args['confirm_shared'] ?? false) !== true) return ToolResult::json($plan + ['message' => SchemaMessages::shared()]);
		$returned = null; $failed = false;
		if (!$p['noop']) {
			try { $returned = $this->gateway->apply($action, $p, $uid); }
			catch (\Throwable) { $failed = true; }
		}
		try {
			$this->admit($action, $uid);
			$observed = $this->gateway->observe($action, $p, $returned, $uid);
		} catch (\Throwable) {
			return ToolResult::json(['status' => 'unverified', 'message' => SchemaMessages::uncertain()]);
		}
		$different = SchemaReceiptComparison::differences($p['after'], $observed);
		$matches = $different === [];
		return ToolResult::json(['status' => $matches ? 'observed' : 'different', 'differentFields' => $different, 'attemptFailed' => $failed, 'noop' => $p['noop'], 'observed' => $observed, 'notice' => \OCA\Mcp\Modules\Tables\Presentation\TablesMessages::UNTRUSTED_NOTICE]);
	}
	/**
	 * @param SchemaAction $action Tool-specific action.
	 * @param array<string,mixed> $args Arguments.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Revalidated preparation.
	 */
	private function prepare(SchemaAction $action, array $args, string $uid): array {
		$args = SchemaRequest::of($action->name(), $args); $this->admit($action, $uid);
		return $this->gateway->prepare($action, $args, $uid, $this->capabilities->profile() ?? throw new ToolFailure(SchemaMessages::incompatible()));
	}
	/**
	 * @param SchemaAction $action Tool-specific action.
	 * @param string $uid Caller.
	 * @return void
	 */
	private function admit(SchemaAction $action, string $uid): void {
		$share = $action->operation() === 'share';
		if (!$this->grants->granted($uid, 'tables', $share ? 'share' : 'schema')) throw new ToolFailure(CommonMessages::forbidden());
		$this->session->assertCaller($uid);
		if (!$this->registry->supports($share ? 'tables.native_sharing_api' : 'tables.native_schema_api')) throw new ToolFailure(SchemaMessages::incompatible());
	}
	/**
	 * @param SchemaAction $action Tool-specific action.
	 * @param array<string,mixed> $p Preparation.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Bounded public plan.
	 */
	private function plan(SchemaAction $action, array $p, string $uid): array {
		$intent = $p['arguments']; unset($intent['confirm'], $intent['confirm_shared'], $intent['plan_state']);
		$shown = ['intent' => $intent, 'version' => $p['version'], 'profile' => $p['profile'], 'shared' => $p['shared'],
			'before' => $p['before'], 'after' => $p['after'], 'preserved' => $p['preserved'] ?? [], 'views' => $p['views'] ?? [], 'rewrittenFields' => $p['rewrittenFields'] ?? []];
		if (strlen(json_encode($shown, JSON_THROW_ON_ERROR)) > 60000) SchemaRequest::invalid('patch');
		$notes = [SchemaMessages::notice(), ...$action->notes()];
		return ['requiresConfirmation' => true, 'action' => 'tables_' . $action->name(), 'target' => $intent,
			'before' => $p['before'], 'after' => $p['after'], 'views' => $p['views'] ?? [], 'rewrittenFields' => $p['rewrittenFields'] ?? [], 'shared' => $p['shared'], 'recoveryGuaranteed' => false, 'backupCreated' => false, 'noop' => $p['noop'], 'notes' => $notes,
			'notice' => \OCA\Mcp\Modules\Tables\Presentation\TablesMessages::UNTRUSTED_NOTICE,
			'plan_state' => $this->states->of('tables_' . $action->name(), $uid, $shown)];
	}
}
