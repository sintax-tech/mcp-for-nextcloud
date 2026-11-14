<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesMessages;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use Psr\Log\LoggerInterface;

/**
 * The error boundary of a routine step that runs after an earlier step wrote: whatever the port throws becomes the
 * outcome of that step, so the receipt with what exists always reaches the user and nothing suggests calling again.
 * A refusal the port raises before its sink (its own refusal, an argument, a policy it cannot read) means the step
 * wrote nothing and is failed; anything else may come after the write and is uncertain. Only the class and code of
 * the exception are logged.
 */
final class RoutineStepGuard {
	/** Static boundary only. */
	private function __construct() {
	}

	/**
	 * @param LoggerInterface $logger receives the class and code of an exception the step threw, never its message
	 * @param array<string, string|int> $ids identifiers already known for this step
	 * @param \Closure(): WorkflowStep $run the step
	 * @return WorkflowStep what the port observed, or the outcome of its exception
	 */
	public static function run(LoggerInterface $logger, array $ids, \Closure $run): WorkflowStep {
		try {
			return $run();
		} catch (\Throwable $e) {
			$logger->warning('MCP routine step failed: {exception_detail}', ['app' => 'mcp', 'exception_detail' => ToolRegistry::loggable($e)]);
			return match (true) {
				$e instanceof ToolFailure => WorkflowStep::failed($e->getMessage()),
				$e instanceof PolicyUnavailable && !$e->uncertain => WorkflowStep::failed(RoutinesMessages::stepPolicyUnavailable()),
				$e instanceof \InvalidArgumentException => WorkflowStep::failed(RoutinesMessages::stepRefused()),
				default => WorkflowStep::uncertain(WorkflowMessages::uncertain(), $ids),
			};
		}
	}
}
