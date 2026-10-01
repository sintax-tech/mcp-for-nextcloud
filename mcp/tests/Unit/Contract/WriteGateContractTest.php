<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\ArgumentValidator;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolPresentation;
use OCA\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Tools\ToolResult;
use OCA\Mcp\Tools\WriteGate;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WriteGateContractTest extends TestCase {
    /** Builds a concrete app class with real app collaborators and mocks for everything else. */
    private function build(string $class): object {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $args = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($name === null || $type->isBuiltin()) {
                $args[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : ($name === 'string' ? 'mcp' : null);
                continue;
            }
            $reflection = new \ReflectionClass($name);
            $args[] = str_starts_with($name, 'OCA\\Mcp\\') && $reflection->isInstantiable() ? $this->build($name) : $this->createMock($name);
        }
        return new $class(...$args);
    }

    private function registry(array $modules): ToolRegistry {
        $config = new InMemoryConfig();
        $policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $policy->setGrant('alice', $module, $operation, true);
            }
        }
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));

        return new ToolRegistry($modules, $policy, $apps, $users, $this->createMock(LoggerInterface::class));
    }

    /**
     * Synthesizes minimal arguments satisfying required properties in the schema.
     */
    private function dummyArguments(array $schema): array {
        $args = [];
        $required = (array)($schema['required'] ?? []);
        $properties = (array)($schema['properties'] ?? []);
        foreach ($required as $propName) {
            if ($propName === WriteGate::CONFIRM) {
                continue;
            }
            $prop = (array)($properties[$propName] ?? []);
            $args[$propName] = $this->dummyValue($prop);
        }
        return $args;
    }

    private function dummyValue(array $rule): mixed {
        if (isset($rule['enum']) && is_array($rule['enum']) && $rule['enum'] !== []) {
            return $rule['enum'][0];
        }
        $type = $rule['type'] ?? 'string';
        if (is_array($type)) {
            $type = $type[0];
        }
        return match ($type) {
            'integer', 'number' => $rule['minimum'] ?? 1,
            'boolean' => false,
            'array' => ($rule['minItems'] ?? 0) > 0 && isset($rule['items']) ? [$this->dummyValue((array)$rule['items'])] : [],
            'object' => $this->dummyArguments($rule),
            default => substr(str_pad('dummy', max(5, (int)($rule['minLength'] ?? 0)), 'x'), 0, (int)($rule['maxLength'] ?? PHP_INT_MAX)),
        };
    }

    public function testEveryWritingToolAcrossAllRegisteredModulesIsGatedWithConsistentSchemaAndAnnotations(): void {
        $modules = array_map(fn (string $class) => $this->build($class), Application::MODULES);
        $registry = $this->registry($modules);
        $listed = array_column($registry->list('alice'), null, 'name');

        $totalChecked = 0;
        $writesChecked = 0;

        foreach ($modules as $module) {
            $this->assertInstanceOf(ToolModule::class, $module);
            foreach ($module->definitions() as $definition) {
                $totalChecked++;
                $name = $definition['name'];
                $isWrite = WriteGate::isWrite($definition);

                if (!$isWrite) {
                    $this->assertSame('read', $definition['operation'], "$name is marked as non-write but has operation {$definition['operation']}");
                    continue;
                }

                $writesChecked++;
                $this->assertArrayHasKey($name, $listed, "Writing tool $name must be listed");
                $listedTool = $listed[$name];
                $properties = (array)($listedTool['inputSchema']['properties'] ?? []);

                // 1. Confirm property in schema
                $this->assertArrayHasKey(WriteGate::CONFIRM, $properties, "$name must have 'confirm' in properties");
                $confirm = $properties[WriteGate::CONFIRM];
                $this->assertSame('boolean', $confirm['type'], "$name: confirm must be boolean");
                $this->assertSame(CommonMessages::confirmParameter(), $confirm['description'], "$name: confirm must have standard message");
                $this->assertArrayNotHasKey('const', $confirm, "$name: confirm must not have const");
                $this->assertNotContains(WriteGate::CONFIRM, $listedTool['inputSchema']['required'] ?? [], "$name: confirm must never be required");

                // 2. Annotations
                $annotations = $listedTool['annotations'];
                $this->assertFalse($annotations['readOnlyHint'], "$name: readOnlyHint must be false for write tools");
                $this->assertFalse($annotations['openWorldHint'], "$name: openWorldHint must always be false");
                $expectedDestructive = in_array($definition['operation'], ['delete', 'restore', 'transfer', 'move', 'edit', 'replace', 'reply', 'attach', 'quote'], true)
                    || in_array($name, ['talk_create_group'], true)
                    || ($definition['destructiveHint'] ?? false) === true;
                $this->assertSame($expectedDestructive, $annotations['destructiveHint'], "$name: destructiveHint mismatch");
                $this->assertSame($definition['operation'] === 'restore', $annotations['idempotentHint'], "$name: idempotentHint mismatch");
            }
        }

        $this->assertGreaterThan(20, $totalChecked, 'Total tools should be greater than 20');
        $this->assertGreaterThan(10, $writesChecked, 'Write tools should be greater than 10');
    }

    /**
     * Every module of the app writes something, so every one describes its own plan and none falls back to the
     * generic one. Read from the class, because Calendar only lists its writes after the selftest.
     */
    public function testContactsAndTasksWritesAreRegisteredWithCorrectGrantOperations(): void {
        $definitions=[];
        foreach(Application::MODULES as $class) {
            foreach($this->build($class)->definitions() as $definition) { $definitions[$definition['name']]=$definition; }
        }
        foreach([
            'contacts_create_contact'=>'create','contacts_edit_contact'=>'edit','contacts_delete_contact'=>'delete',
            'tasks_create_task'=>'create','tasks_edit_task'=>'edit','tasks_complete_task'=>'edit','tasks_delete_task'=>'delete',
        ] as $name=>$operation) {
            self::assertArrayHasKey($name,$definitions);
            self::assertSame($operation,$definitions[$name]['operation']);
            self::assertTrue(WriteGate::isWrite($definitions[$name]));
        }
    }

    public function testEveryModuleDescribesItsOwnPlan(): void {
        foreach (Application::MODULES as $moduleClass) {
            $this->assertTrue(is_subclass_of($moduleClass, PreviewsWrites::class), "$moduleClass must implement PreviewsWrites");
        }
    }

    public function testCallingEveryWritingToolWithoutConfirmNeverExecutesAndReturnsRequiresConfirmation(): void {
        foreach (Application::MODULES as $moduleClass) {
            $realModule = $this->build($moduleClass);
            $this->assertInstanceOf(ToolModule::class, $realModule);

            foreach ($realModule->definitions() as $definition) {
                if (!WriteGate::isWrite($definition)) {
                    continue;
                }

                $name = $definition['name'];
                $calledSpy = false;

                // Create a spy wrapper for the module that intercepts call()
                if ($realModule instanceof PreviewsWrites) {
                    $spyModule = new class($realModule, $calledSpy) implements ToolModule, PreviewsWrites {
                        public function __construct(private ToolModule $inner, private bool &$called) {}
                        public function definitions(): array { return $this->inner->definitions(); }
                        public function call(string $name, array $arguments, string $userId): array {
                            $this->called = true;
                            return $this->inner->call($name, $arguments, $userId);
                        }
                        public function preview(string $name, array $arguments, string $userId): array {
                            /** @var PreviewsWrites $inner */
                            $inner = $this->inner;
                            try {
                                return $inner->preview($name, $arguments, $userId);
                            } catch (\Throwable) {
                                return ['action' => $name, 'previewed' => true];
                            }
                        }
                    };
                } else {
                    $spyModule = new class($realModule, $calledSpy) implements ToolModule {
                        public function __construct(private ToolModule $inner, private bool &$called) {}
                        public function definitions(): array { return $this->inner->definitions(); }
                        public function call(string $name, array $arguments, string $userId): array {
                            $this->called = true;
                            return $this->inner->call($name, $arguments, $userId);
                        }
                    };
                }

                $registry = $this->registry([$spyModule]);
                $published = WriteGate::publish($definition);
                $dummyArgs = $this->dummyArguments($published['inputSchema']);

                // Call without confirm
                $result = $registry->call($name, $dummyArgs, 'alice');
                $this->assertFalse($calledSpy, "Tool $name must NOT invoke module call() without confirm: true");
                $this->assertArrayNotHasKey('isError', $result, "Tool $name plan must not be an error");

                $body = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertTrue($body['requiresConfirmation'] ?? false, "Tool $name response must contain requiresConfirmation: true");
                $this->assertSame($name, $body['tool'] ?? $body['action'] ?? null, "Tool $name response must identify the tool");

                // Call with confirm => false
                $resultFalse = $registry->call($name, $dummyArgs + ['confirm' => false], 'alice');
                $this->assertFalse($calledSpy, "Tool $name must NOT invoke module call() with confirm: false");
                $bodyFalse = json_decode($resultFalse['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertTrue($bodyFalse['requiresConfirmation'] ?? false, "Tool $name response with confirm: false must contain requiresConfirmation: true");
            }
        }
    }

    public function testFictitiousWritingToolAddedToRegistryIsBlockedAutomatically(): void {
        $executed = false;
        $fakeModule = new class($executed) implements ToolModule {
            public function __construct(public bool &$executed) {}

            public function definitions(): array {
                return [[
                    'name' => 'fake_write_tool',
                    'description' => 'A fictitious write tool to test automatic gating',
                    'module' => 'files',
                    'operation' => 'create',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'item' => ['type' => 'string'],
                        ],
                        'required' => ['item'],
                        'additionalProperties' => false,
                    ],
                ]];
            }

            public function call(string $name, array $arguments, string $userId): array {
                $this->executed = true;
                return ToolResult::json(['created' => $arguments['item']]);
            }
        };

        $registry = $this->registry([$fakeModule]);

        // 1. Check listed schema and annotations
        $listed = array_column($registry->list('alice'), null, 'name');
        $this->assertArrayHasKey('fake_write_tool', $listed);
        $tool = $listed['fake_write_tool'];
        $this->assertArrayHasKey('confirm', $tool['inputSchema']['properties']);
        $this->assertSame('boolean', $tool['inputSchema']['properties']['confirm']['type']);
        $this->assertNotContains('confirm', $tool['inputSchema']['required'] ?? []);
        $this->assertFalse($tool['annotations']['readOnlyHint']);

        // 2. Call without confirm: should NOT execute and should return plan
        $result = $registry->call('fake_write_tool', ['item' => 'test-item'], 'alice');
        $this->assertFalse($executed, 'Execution must not happen without confirm: true');
        $body = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($body['requiresConfirmation']);
        $this->assertSame('fake_write_tool', $body['tool']);
        $this->assertSame('test-item', $body['arguments']['item']);
        $this->assertSame(CommonMessages::planNothingChanged(), $body['message']);

        // 3. Call with confirm: false: should NOT execute
        $resultFalse = $registry->call('fake_write_tool', ['item' => 'test-item', 'confirm' => false], 'alice');
        $this->assertFalse($executed, 'Execution must not happen with confirm: false');
        $bodyFalse = json_decode($resultFalse['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($bodyFalse['requiresConfirmation']);

        // 4. Call with confirm: true: should execute!
        $resultConfirmed = $registry->call('fake_write_tool', ['item' => 'test-item', 'confirm' => true], 'alice');
        $this->assertTrue($executed, 'Execution MUST happen with confirm: true');
        $bodyConfirmed = json_decode($resultConfirmed['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('test-item', $bodyConfirmed['created']);
    }
}
