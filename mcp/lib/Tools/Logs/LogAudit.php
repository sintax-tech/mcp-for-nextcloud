<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

use OCA\Mcp\Tools\RestrictedModule;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Records every call of the logs tools: the core's audit event, which the admin_audit app writes to the audit log
 * when it is enabled, and a line in the app's own log. A read and a refused or failed call have different messages,
 * so a denial is never recorded as a read of the log.
 */
class LogAudit {
    /** Message of a read; admin_audit fills the placeholders with the parameters, in order. */
    public const READ = 'MCP logs read by %s: tool=%s filters=%s';
    /** Message of a call that read nothing: denied, invalid or failed. */
    public const REFUSED = 'MCP logs request %s for %s: tool=%s filters=%s';

    public function __construct(private IEventDispatcher $dispatcher, private LoggerInterface $logger) {}

    /**
     * @param string $userId account that called the tool
     * @param string $tool technical name of the tool
     * @param string $outcome one of the RestrictedModule outcomes
     * @param array<string, int|string> $filters filters, offset and limit of the call, already bounded
     */
    public function record(string $userId, string $tool, string $outcome, array $filters): void {
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
