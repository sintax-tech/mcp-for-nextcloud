<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/** Single policy point for tools/list and tools/call: grant and app availability are re-read on every request. */
class ToolRegistry {
    /** @param list<ToolModule> $modules explicit module list, in tools/list order */
    public function __construct(
        private array $modules,
        private GrantPolicy $policy,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string $userId authenticated user
     * @return list<array{name:string, description:string, inputSchema:array<string, mixed>}> tools the user may call now
     */
    public function list(string $userId): array {
        $tools = [];
        foreach ($this->modules as $module) {
            foreach ($module->definitions() as $definition) {
                if ($this->allowed($definition, $userId)) {
                    $tools[] = [
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'inputSchema' => $definition['inputSchema'],
                    ];
                }
            }
        }
        return $tools;
    }

    /**
     * @param string $name tool name
     * @param array<string, mixed> $arguments raw arguments from tools/call
     * @param string $userId authenticated user
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP tool result
     * @throws InvalidArgumentException for unknown, hidden or invalid calls (JSON-RPC -32602)
     */
    public function call(string $name, array $arguments, string $userId): array {
        foreach ($this->modules as $module) {
            foreach ($module->definitions() as $definition) {
                if ($definition['name'] !== $name) {
                    continue;
                }
                if (!$this->allowed($definition, $userId)) {
                    break 2;
                }
                $arguments = ArgumentValidator::validate($definition['inputSchema'], $arguments);
                try {
                    return $module->call($name, $arguments, $userId);
                } catch (InvalidArgumentException $e) {
                    throw $e;
                } catch (ToolFailure $e) {
                    return ToolResult::error($e->getMessage());
                } catch (\Throwable $e) {
                    $this->logger->error('MCP tool failed', ['app' => 'mcp', 'tool' => $name, 'exception_class' => $e::class]);
                    return ToolResult::error('Erro inesperado ao acessar o Nextcloud.');
                }
            }
        }
        throw new InvalidArgumentException('Unknown tool');
    }

    /** @param array{module:string, operation:string, app?:string} $definition */
    private function allowed(array $definition, string $userId): bool {
        try {
            if (!$this->policy->granted($userId, $definition['module'], $definition['operation'])) {
                return false;
            }
        } catch (InvalidArgumentException) {
            return false;
        }
        if (isset($definition['app'])) {
            $user = $this->userManager->get($userId);
            return $user !== null && $this->appManager->isEnabledForUser($definition['app'], $user);
        }
        return true;
    }
}
