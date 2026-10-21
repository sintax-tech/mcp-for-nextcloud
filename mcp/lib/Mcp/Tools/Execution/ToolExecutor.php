<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Execution;

use OCA\Mcp\Platform\Logging\ExceptionSummary;

use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;

use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\Validation\UnknownToolException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Confirmation\PlanRenderer;
use OCA\Mcp\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Executes a matched tool through confirmation and restricted audit policy. */
final class ToolExecutor {
    public function __construct(
        private ToolAvailability $availability,
        private LoggerInterface $logger,
    ) {}

    /** Executes the first matching definition; preserves validation, audit and catch boundaries. */
    public function call(ToolModule $module, array $definition, array $arguments, string $userId): array {
        $name = $definition['name'];
        // A restricted module records every attempt with its outcome, the refused ones included. A failing audit
        // is logged without the arguments and reported as false: the caller decides what that costs.
        $audit = function (string $outcome) use ($module, $name, $userId, &$arguments): bool {
            if (!$module instanceof RestrictedModule) {
                return true;
            }
            try {
                $module->audit($name, $userId, $outcome, $arguments);
                return true;
            } catch (\Throwable $e) {
                try {
                    $this->logger->error('MCP audit failed', ['app' => 'mcp', 'tool' => $name, 'outcome' => $outcome, 'exception_class' => $e::class]);
                } catch (\Throwable) {
                    // Even the contingency logger can fail; preserve the original outcome.
                }
                return false;
            }
        };
        if (!$this->availability->permits($module, $userId) || !$this->availability->allowed($definition, $userId)) {
            $audit(RestrictedModule::DENIED);
            throw new UnknownToolException();
        }
        $published = WriteGate::publish($definition);
        try {
            $arguments = ArgumentValidator::validate($published['inputSchema'], $arguments);
        } catch (InvalidArgumentException $e) {
            $audit(RestrictedModule::INVALID);
            throw $e;
        }
        try {
            if (WriteGate::isWrite($definition) && !WriteGate::confirmed($arguments)) {
                $plan = WriteGate::plan($module, $definition, $arguments, $userId);
                return ToolResult::structured(PlanRenderer::render($module, $name, $plan), $plan);
            }
            $result = $module->call($name, $arguments, $userId);
        } catch (InvalidArgumentException $e) {
            $audit(RestrictedModule::INVALID);
            throw $e;
        } catch (PlanChanged $e) {
            // Nothing was written: the person approved another state, so the answer is the new plan to approve.
            $plan = WriteGate::envelope($definition, $e->plan);
            return ToolResult::structured(PlanRenderer::render($module, $name, $plan), $plan);
        } catch (ToolFailure $e) {
            $audit(RestrictedModule::READ_ERROR);
            return ToolResult::error($e->getMessage());
        } catch (LockedException $e) {
            // A locked file is an expected refusal, never a server error, even from a module that did not map it.
            $audit(RestrictedModule::READ_ERROR);
            return ToolResult::error(NodeAccess::lockFailure($e)->getMessage());
        } catch (PolicyUnavailable $e) {
            $audit(RestrictedModule::READ_ERROR);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failed', ['app' => 'mcp', 'tool' => $name, 'exception_class' => $e::class, 'exception_message' => ExceptionSummary::loggable($e)]);
            $audit(RestrictedModule::READ_ERROR);
            return ToolResult::error(Translator::t('Unexpected error while accessing Nextcloud.'));
        }
        // A read that could not be audited hands no data out.
        if (!$audit(RestrictedModule::SUCCESS)) {
            return ToolResult::error(Translator::t('Unexpected error while accessing Nextcloud.'));
        }
        return $result;
    }
}
