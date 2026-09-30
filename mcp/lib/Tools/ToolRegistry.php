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
    /** @param list<ToolModule> $modules */
    public function __construct(
        private array $modules,
        private GrantPolicy $policy,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {}

    /** @return list<array{name:string,description:string,inputSchema:array}> */
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

    /** @throws InvalidArgumentException for unknown, hidden or invalid calls (JSON-RPC -32602) */
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
