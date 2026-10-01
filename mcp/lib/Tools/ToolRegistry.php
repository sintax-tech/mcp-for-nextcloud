<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\GrantPolicy;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Single policy point for tools/list and tools/call: grant and app availability are re-read on every
 * request, and no tool that changes something runs without `confirm: true`.
 *
 * The two decisions are here and not in the modules on purpose. A module that forgets the check cannot
 * write anything by accident, and a new module is gated by writing `operation: 'create'` and nothing else.
 */
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
     * Every tool carries the display metadata clients show instead of the technical name:
     * `title` (Tool.title) and `annotations` derived from the tool's grant operation.
     *
     * A writing tool is published with the `confirm` argument {@see WriteGate} adds, so the model reads the
     * rule in the schema of every write it may call; a read tool is published exactly as the module wrote it.
     *
     * @param string $userId authenticated user
     * @return list<array{name:string, title:string, description:string, inputSchema:array<string, mixed>, annotations:array<string, bool|string>}> tools the user may call now
     */
    public function list(string $userId): array {
        $tools = [];
        foreach ($this->modules as $module) {
            foreach ($module->definitions() as $definition) {
                if ($this->allowed($definition, $userId)) {
                    $published = WriteGate::publish($definition);
                    $tools[] = [
                        'name' => $published['name'],
                        'title' => ToolPresentation::title($published['name']),
                        'description' => $published['description'],
                        'inputSchema' => $published['inputSchema'],
                        'annotations' => ToolPresentation::annotations($published['name'], $published['operation'], ($published['destructiveHint'] ?? false) === true),
                    ];
                }
            }
        }
        return $tools;
    }

    /**
     * A write without `confirm: true` answers with its plan and never reaches the module; with it, the module
     * runs and every guard it holds — ACL, ETag, ownership — is checked again at that moment.
     *
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
                $published = WriteGate::publish($definition);
                $arguments = ArgumentValidator::validate($published['inputSchema'], $arguments);
                try {
                    if (WriteGate::isWrite($definition) && !WriteGate::confirmed($arguments)) {
                        return ToolResult::json(WriteGate::plan($module, $definition, $arguments, $userId));
                    }
                    return $module->call($name, $arguments, $userId);
                } catch (InvalidArgumentException $e) {
                    throw $e;
                } catch (ToolFailure $e) {
                    return ToolResult::error($e->getMessage());
                } catch (\Throwable $e) {
                    $this->logger->error('MCP tool failed', ['app' => 'mcp', 'tool' => $name, 'exception_class' => $e::class]);
                    return ToolResult::error(Translator::t('Unexpected error while accessing Nextcloud.'));
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
