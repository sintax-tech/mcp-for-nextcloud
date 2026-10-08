<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Records every read of the server log: the core's audit event, which the admin_audit app writes to the audit log
 * when it is enabled, and an info line in the app's own log.
 */
class LogAudit {
    /** Message of the audit event; admin_audit fills the placeholders with the parameters, in order. */
    public const MESSAGE = 'MCP logs read by %s: tool=%s filters=%s';

    public function __construct(private IEventDispatcher $dispatcher, private LoggerInterface $logger) {}

    /**
     * @param string $userId account that read the log
     * @param string $tool technical name of the tool
     * @param array<string, int|string> $filters filters, offset and limit of the call
     */
    public function record(string $userId, string $tool, array $filters): void {
        $parameters = [
            'user' => $userId,
            'tool' => $tool,
            'filters' => json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        ];
        $this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(self::MESSAGE, $parameters));
        $this->logger->info('MCP logs read', ['app' => 'mcp'] + $parameters);
    }
}
