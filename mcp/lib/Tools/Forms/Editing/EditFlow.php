<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Editing;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;

/** Coordinates inert preparation, authenticated confirmation and exactly one native point edit. */
class EditFlow {
	/** Receives only focused editing collaborators.
	 * @param EditNativeFactory $factory Native graph/admission.
	 * @param EditState $states Authorized active snapshot/effect.
	 * @param EditPlan $plans Complete plan builder.
	 * @param EditWriter $writer One native PATCH and readback.
	 */
	public function __construct(private EditNativeFactory $factory, private EditState $states, private EditPlan $plans, private EditWriter $writer) { }
	/** Produces a zero-write preview, never acquiring a native lock.
	 * @param array<string,mixed> $args Submitted request.
	 * @param string $uid Actor.
	 * @param bool $option Option versus question tool.
	 * @return array<string,mixed> Complete public plan.
	 */
	public function preview(array $args, string $uid, bool $option): array {
		[$r,$c,$s,$e] = $this->prepare($args, $uid, $option); return $this->plans->of($r, $c, $s, $e, $uid);
	}
	/** Requires central confirmation, matched state and explicit shared admission before the writer.
	 * @param array<string,mixed> $args Validated request.
	 * @param string $uid Actor.
	 * @param bool $option Option tool.
	 * @return array<string,mixed> MCP result with honest observed receipt.
	 */
	public function handle(array $args, string $uid, bool $option): array {
		if (($args['confirm'] ?? null) !== true) { throw new ToolFailure(EditMessages::refused()); }
		PlanState::require($args); [$r,$c,$s,$e] = $this->prepare($args, $uid, $option); $plan = $this->plans->of($r, $c, $s, $e, $uid);
		if (!PlanState::matches($plan['plan_state'], $args)) { throw new PlanChanged($this->plans->of($r, $c, $s, $e, $uid, true)); }
		$shared = EditSharedGuard::confirm($plan, $args); if ($shared !== null) { return $shared; }
		$receipt = $this->writer->write($c, $r, $s, $e, $plan, $uid);
		return ToolResult::json($receipt + ['notice' => FormsMessages::NOTICE]) + (in_array($receipt['status'], ['applied','not_attempted'], true) ? [] : ['isError' => true]);
	}
	/** Rejects malformed requests before native admission and never turns backend failures into empty state.
	 * @param array<string,mixed> $args Request.
	 * @param string $uid Actor.
	 * @param bool $option Tool kind.
	 * @return array{array<string,mixed>,EditContext,array<string,mixed>,array<string,mixed>} Inert prepared operation.
	 */
	private function prepare(array $args, string $uid, bool $option): array {
		$r = EditRequest::of($args, $option);
		try { $c = $this->factory->open($uid); $s = $this->states->read($c, $r, $uid); $e = $this->states->effect($c, $r, $s); return [$r,$c,$s,$e]; }
		catch (ArgumentValidationException|ToolFailure $error) { throw $error; }
		catch (\Throwable $error) { throw new ToolFailure(EditMessages::refused(), 0, $error); }
	}
}
