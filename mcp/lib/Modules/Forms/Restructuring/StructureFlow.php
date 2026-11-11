<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Editing\EditSharedGuard;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Presentation\FormsMessages;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;

/**
 * Coordinates every structure tool: inert preview, strict confirmation with plan_state and confirm_shared, then the writer.
 *
 * The preview never calls a native mutator, never takes the native lock and never reads submissions or Files.
 */
class StructureFlow {
	/**
	 * @param EditNativeFactory $factory admission and native graph
	 * @param StructureState $states fresh snapshots
	 * @param StructurePlan $plans plan builder
	 * @param StructureWriter $writer single native call and receipt
	 */
	public function __construct(private EditNativeFactory $factory, private StructureState $states, private StructurePlan $plans,
		private StructureWriter $writer) {
	}

	/**
	 * @param StructureOperation $op operation
	 * @param array<string,mixed> $args submitted arguments
	 * @param string $uid actor
	 * @return array<string,mixed> complete plan, with plan_state
	 */
	public function preview(StructureOperation $op, array $args, string $uid): array {
		[$r, $c, $s, $e] = $this->prepare($op, $args, $uid);
		return $this->plans->of($op, $r, $c, $s, $e, $uid);
	}

	/**
	 * @param StructureOperation $op operation
	 * @param array<string,mixed> $args submitted arguments, with confirm and plan_state
	 * @param string $uid actor
	 * @return array<string,mixed> MCP result with the receipt, or the shared-confirmation request
	 * @throws PlanChanged when the state changed since the plan
	 */
	public function handle(StructureOperation $op, array $args, string $uid): array {
		if (($args['confirm'] ?? null) !== true) {
			throw new ToolFailure(StructureMessages::refused());
		}
		PlanState::require($args);
		[$r, $c, $s, $e] = $this->prepare($op, $args, $uid);
		$plan = $this->plans->of($op, $r, $c, $s, $e, $uid);
		if (!PlanState::matches($plan[PlanState::ARGUMENT], $args)) {
			throw new PlanChanged($this->plans->of($op, $r, $c, $s, $e, $uid, true));
		}
		$shared = EditSharedGuard::confirm($plan, $args);
		if ($shared !== null) {
			return $shared;
		}
		$receipt = $this->writer->write($op, $c, $r, $s, $e, $plan, $uid);
		return ToolResult::json($receipt + ['notice' => FormsMessages::NOTICE])
			+ (in_array($receipt['status'], ['applied', 'not_attempted'], true) ? [] : ['isError' => true]);
	}

	/**
	 * @param StructureOperation $op operation
	 * @param array<string,mixed> $args submitted arguments
	 * @param string $uid actor
	 * @return array{0: array<string,mixed>, 1: EditContext, 2: array<string,mixed>, 3: array<string,mixed>} request, graph, snapshot, effect
	 */
	private function prepare(StructureOperation $op, array $args, string $uid): array {
		$r = $op->request($args);
		try {
			$c = $this->factory->open($uid, [StructureMessages::class, 'unsupportedVersion']);
			$s = $this->states->read($c, $r['form_id'], $uid);
			return [$r, $c, $s, $op->effect($c, $r, $s)];
		} catch (ArgumentValidationException|ToolFailure $error) {
			throw $error;
		} catch (\Throwable $error) {
			throw new ToolFailure(StructureMessages::refused(), 0, $error);
		}
	}
}
