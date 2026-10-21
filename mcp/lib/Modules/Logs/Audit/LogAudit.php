<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Audit;

use OCA\Mcp\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Modules\Logs\Reading\LogFilter;
use OCA\Mcp\Modules\Logs\Privacy\LogRedactor;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Records every call of the logs tools: the core's audit event, which the admin_audit app writes to the audit log
 * when it is enabled, and a line in the app's own log. A read and a refused or failed call have different messages,
 * so a denial is never recorded as a read of the log.
 */
class LogAudit {
    private const AUDITED_ARGUMENTS = ['min_level', 'app', 'user', 'since', 'until', 'contains', 'req_id', 'offset', 'limit'];
    /** Message of a read; admin_audit fills the placeholders with the parameters, in order. */
    public const READ = 'MCP logs read by %s: tool=%s filters=%s';
    /** Message of a call that read nothing: denied, invalid or failed. */
    public const REFUSED = 'MCP logs request %s for %s: tool=%s filters=%s';

    public function __construct(private IEventDispatcher $dispatcher, private LoggerInterface $logger) {}

    /**
     * Records a call with its outcome, as the registry reports it. The arguments are untrusted: only the known filters,
     * offset and limit are kept, scalars only, each text cut.
     *
     * @param string $tool technical name of the tool called
     * @param string $userId authenticated user
     * @param string $outcome one of the RestrictedModule outcomes
     * @param array<string, mixed> $arguments arguments of the call
     */
    public function recordCall(string $tool, string $userId, string $outcome, array $arguments): void {
        $filters = [];
        foreach (self::AUDITED_ARGUMENTS as $key) {
            $value = $arguments[$key] ?? null;
            if (is_int($value)) {
                $filters[$key] = $value;
            } elseif (is_string($value)) {
                // What the reader searched for may be a file name: the audit log never carries that text.
                $filters[$key] = $key === 'contains' ? LogRedactor::HIDDEN : LogRedactor::text($value, LogFilter::MAX_CONTAINS);
            }
        }
        $this->record($userId, LogRedactor::text($tool, 64), $outcome, $filters);
    }

    /**
     * @param string $userId account that called the tool
     * @param string $tool technical name of the tool
     * @param string $outcome one of the RestrictedModule outcomes
     * @param array<string, int|string> $filters filters, offset and limit of the call, already bounded
     */
    private function record(string $userId, string $tool, string $outcome, array $filters): void {
        $encoded = json_encode((object)$filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $parameters = ['user' => $userId, 'tool' => $tool, 'filters' => $encoded];
        if ($outcome === RestrictedModule::SUCCESS) {
            $this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(self::READ, $parameters));
            $this->logger->info('MCP logs read', ['app' => 'mcp', 'outcome' => $outcome] + $parameters);
            return;
        }
        $this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(self::REFUSED, ['outcome' => $outcome] + $parameters));
        $context = ['app' => 'mcp', 'outcome' => $outcome] + $parameters;
        $outcome === RestrictedModule::DENIED
            ? $this->logger->warning('MCP logs request denied', $context)
            : $this->logger->info('MCP logs request ' . $outcome, $context);
    }
}
