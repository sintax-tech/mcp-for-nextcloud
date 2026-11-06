<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

use OCA\Mcp\Modules\Forms\Editing\EditContext;
/**
 * One Forms structure operation: its closed request, its predicted effect, its single native call and its readback check.
 *
 * The effect is a pure function of the request and of a fresh StructureState snapshot, so the plan, the plan_state and the
 * pre-call revalidation all recompute it. It holds: noop, target, before, after, effects (notes), irreversible, answers,
 * selector (expected confirmation-email selection), confirmation (plan block or null) and expected (internal, for observe).
 */
interface StructureOperation {
	/** @return string technical tool name */
	public function name(): string;

	/**
	 * @param array<string,mixed> $args submitted arguments, confirmation keys included
	 * @return array<string,mixed> closed, validated request with an integer form_id
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException on any invalid argument
	 */
	public function request(array $args): array;

	/**
	 * @param EditContext $c admitted native graph
	 * @param array<string,mixed> $r validated request
	 * @param array<string,mixed> $s fresh snapshot
	 * @return array<string,mixed> predicted effect
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure|\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException when refused
	 */
	public function effect(EditContext $c, array $r, array $s): array;

	/**
	 * Performs exactly one native ApiController call.
	 *
	 * @param EditContext $c admitted native graph
	 * @param array<string,mixed> $r validated request
	 * @param array<string,mixed> $e predicted effect
	 * @return mixed native response
	 */
	public function call(EditContext $c, array $r, array $e): mixed;

	/**
	 * @param mixed $response native response
	 * @param array<string,mixed> $r validated request
	 * @return bool whether the response has the documented native shape for this request
	 */
	public function responseValid(mixed $response, array $r): bool;

	/**
	 * @param EditContext $c native graph, already re-admitted
	 * @param array<string,mixed> $r validated request
	 * @param array<string,mixed> $e predicted effect
	 * @param array<string,mixed> $after fresh snapshot after the attempt
	 * @param mixed $response native response
	 * @return array{observed: mixed, complete: bool, changed: bool, extra?: array<string,mixed>} observed effect
	 */
	public function observe(EditContext $c, array $r, array $e, array $after, mixed $response): array;
}
