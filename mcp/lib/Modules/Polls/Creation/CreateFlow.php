<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Creation;

use OCA\Mcp\Mcp\{Confirmation\PlanState,Confirmation\PlanChanged,Tools\Validation\ArgumentValidationException,Tools\Results\ToolFailure};
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Modules\Polls\Presentation\CreateMessages;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use Psr\Log\LoggerInterface;

/** Covers preflight, sinks, readbacks and final cleanup with honest effect classification. */
class CreateFlow {
	/**
	 * Receives independent native gates, pure predictions, plan and writer.
	 * @param CreateNative $native native mechanism/guards
	 * @param CreateNormalizer $normalizer disposable predictions
	 * @param CreatePlan $plans stateless MAC and preview
	 * @param CreateWriter $writer native stage writer
	 * @param LoggerInterface $logger exception-class-only diagnostics
	 */
	public function __construct(private CreateNative $native, private CreateNormalizer $normalizer,
		private CreatePlan $plans, private CreateWriter $writer, private LoggerInterface $logger) {
	}
	/**
	 * Makes a pure preview or a confirmed attempt; no sink can run without both confirm and matching plan.
	 * @param array<string,mixed> $args tool input
	 * @param string $uid explicit authenticated actor
	 * @param bool $preview whether the registry asked for a plan
	 * @return array<string,mixed> structured plan or whitelist receipt
	 * @throws ArgumentValidationException for malformed arguments
	 * @throws PlanChanged for another observed preflight/effect
	 * @throws ToolFailure for refused preflight, before any write
	 */
	public function run(array $args, string $uid, bool $preview): array {
		$request = CreateRequest::of($args);
		if (!$preview) {
			if (($args['confirm'] ?? null) !== true) { throw new ToolFailure(CreateMessages::failure(false)); }
			PlanState::require($args);
		}
		$attempt = new CreateAttempt(); $session = null; $failure = null; $result = null; $cleanupFailed = false;
		try {
			[$session,$version,$nine] = $this->native->open($uid);
			$session->cleanSession(); $this->native->check($session,$uid);
			$effect = $this->normalizer->predict($request,$nine);
			$this->native->check($session,$uid);
			$plan = $this->plans->of($request,$effect,$uid,$version,$nine);
			if ($preview) { $result = $plan; }
			elseif (!PlanState::matches($plan[PlanState::ARGUMENT],$args)) {
				throw new PlanChanged($this->plans->of($request,$effect,$uid,$version,$nine,true));
			} else {
				$this->writer->write($request,$effect,$session,$uid,$nine,$attempt);
				$result = $attempt->result('created',count($request['options']));
			}
		} catch (\Throwable $error) {
			$failure = $error;
		} finally {
			if ($session !== null) {
				try { $session->cleanSession(); }
				catch (\Throwable $error) { $cleanupFailed = true; $failure = $error; }
			}
		}
		if ($failure !== null) {
			if (!$attempt->started) {
				if (!$cleanupFailed && ($failure instanceof PlanChanged || $failure instanceof ArgumentValidationException)) { throw $failure; }
				throw new ToolFailure(CreateMessages::failure(false));
			}
			try { $this->logger->error('MCP Polls create interrupted at {stage}: {exception_class} (code {exception_code})', ['app'=>'mcp','stage'=>$attempt->stage,'exception_class'=>$failure::class, 'exception_code' => ExceptionDetails::code($failure)]); }
			catch (\Throwable) { /* Diagnostics never replace the effect classification. */ }
			$disclose = false;
			if ($session !== null) {
				try { $this->native->receiptAccess($session,$uid);
					if (isset($attempt->receipt['pollId'])) { $this->native->receiptReadback($attempt->receipt['pollId'],$request,$session,$uid,$nine); }
					$disclose = true; } catch (\Throwable) { /* No receipt data on revocation/divergence. */ }
				finally {
					try { $session->cleanSession(); }
					catch (\Throwable) { $cleanupFailed = true; $disclose = false; }
				}
			}
			return $attempt->result($attempt->pending || $cleanupFailed ? 'uncertain' : 'partial',count($request['options']),$disclose);
		}
		return $result ?? throw PollsRefusal::unavailable();
	}
}
