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
    /** Longest exception message written to the server log. */
    private const LOG_MESSAGE_LIMIT = 300;

    private ?ToolGuide $guide = null;

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
     * The guide is the first tool of the list: it is how the model finds out about the others, so a client
     * that only shows the beginning of the list still shows it.
     *
     * @param string $userId authenticated user
     * @return list<array{name:string, title:string, description:string, inputSchema:array<string, mixed>, annotations:array<string, bool|string>}> tools the user may call now
     */
    public function list(string $userId): array {
        $definitions = $this->definitions($userId);
        array_unshift($definitions, $this->present($this->guide()->definition()));
        return array_map(
            // tools/list carries only what a client needs to show and validate the tool; the grant module,
            // the operation and the app stay inside the server, for the guide.
            static fn (array $definition): array => [
                'name' => $definition['name'],
                'title' => $definition['title'],
                'description' => $definition['description'],
                'inputSchema' => $definition['inputSchema'],
                'annotations' => $definition['annotations'],
            ],
            $definitions,
        );
    }

    /**
     * The same tools tools/list answers, with the fields the guide needs to explain them: the grant module
     * and operation, the app the tool needs, the title and the annotations.
     *
     * Reading the processed definitions rather than the raw ones is what keeps the guide honest: whatever
     * the registry did to a schema before answering tools/list is what the guide describes.
     *
     * @param string $userId authenticated user
     * @return list<array<string, mixed>> one entry per tool the user may call now, guide excluded
     */
    public function definitions(string $userId): array {
        $tools = [];
        foreach ($this->modules as $module) {
            foreach ($module->definitions() as $definition) {
                if ($this->allowed($definition, $userId)) {
                    $tools[] = $this->present($definition);
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
        if ($name === ToolGuide::TOOL) {
            return $this->describe(
                ArgumentValidator::validate($this->guide()->definition()['inputSchema'], $arguments),
                $userId,
            );
        }
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
                        $plan = WriteGate::plan($module, $definition, $arguments, $userId);
                        return ToolResult::structured(PlanRenderer::render($module, $name, $plan), $plan);
                    }
                    return $module->call($name, $arguments, $userId);
                } catch (InvalidArgumentException $e) {
                    throw $e;
                } catch (ToolFailure $e) {
                    return ToolResult::error($e->getMessage());
                } catch (\Throwable $e) {
                    $this->logger->error('MCP tool failed', ['app' => 'mcp', 'tool' => $name, 'exception_class' => $e::class, 'exception_message' => self::loggable($e)]);
                    return ToolResult::error(Translator::t('Unexpected error while accessing Nextcloud.'));
                }
            }
        }
        throw new InvalidArgumentException('Unknown tool');
    }

    /**
     * The message of an unexpected exception for the server log only: no arguments, no trace, file-system paths
     * replaced and the text cut, so the log explains the failure without carrying user data. The client never
     * sees it.
     *
     * @param \Throwable $e the unexpected exception
     * @return string at most {@see self::LOG_MESSAGE_LIMIT} characters
     */
    public static function loggable(\Throwable $e): string {
        $message = preg_replace('#(?:/[\w.@~+-]+){2,}/?#u', '[path]', $e->getMessage()) ?? '';
        return mb_substr($message, 0, self::LOG_MESSAGE_LIMIT);
    }

    /**
     * The guide tool reads the definitions of this user like any other read, so it goes through the same
     * permission filter and reports a module or a tool the user cannot see as a readable refusal.
     *
     * @param array<string, mixed> $arguments validated arguments of the guide call
     * @param string $userId authenticated user
     * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>, isError?: bool}
     */
    private function describe(array $arguments, string $userId): array {
        try {
            return $this->guide()->result($this->definitions($userId), $arguments);
        } catch (ToolFailure $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * The definition as the client sees it: {@see WriteGate::publish()} runs first, so tools/list and the
     * guide both describe the `confirm` argument of every write.
     *
     * @param array<string, mixed> $definition a tool definition of a module, or of the guide itself
     * @return array{name:string, title:string, description:string, inputSchema:array<string, mixed>, annotations:array<string, bool|string>, module:string, operation:string, app?:string}
     */
    private function present(array $definition): array {
        $definition = WriteGate::publish($definition);
        return [
            'name' => $definition['name'],
            'title' => ToolPresentation::title($definition['name']),
            'description' => $definition['description'],
            'inputSchema' => $definition['inputSchema'],
            'annotations' => ToolPresentation::annotations($definition['name'], $definition['operation'], ($definition['destructiveHint'] ?? false) === true),
            'module' => $definition['module'],
            'operation' => $definition['operation'],
        ] + (isset($definition['app']) ? ['app' => $definition['app']] : []);
    }

    /**
     * The guide is built once with the module list it reads the behaviour notes from. Public so the resource
     * registry renders mcp://guide from the very same guide the mcp_guide tool returns.
     *
     * @return ToolGuide the shared guide for this registry's modules
     */
    public function guide(): ToolGuide {
        return $this->guide ??= new ToolGuide($this->modules);
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
