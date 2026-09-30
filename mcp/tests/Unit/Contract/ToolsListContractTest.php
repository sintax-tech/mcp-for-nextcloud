<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\McpProtocol;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The exact tools/list JSON of every registered module, with every grant and app enabled, must be a
 * valid MCP tool list: clients such as claude.ai reject a list whose schemas are malformed.
 */
final class ToolsListContractTest extends TestCase {
    private const JSON_TYPES = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

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

    /** @return string tools/list response body exactly as the endpoint encodes it */
    private function toolsListJson(): string {
        $config = new InMemoryConfig();
        $policy = new GrantPolicy($config->mock($this));
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $policy->setGrant('alice', $module, $operation, true);
            }
        }
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $modules = array_map(fn (string $class) => $this->build($class), Application::MODULES);
        $protocol = new McpProtocol(new ToolRegistry($modules, $policy, $apps, $users, $this->createMock(LoggerInterface::class)), $this->createMock(LoggerInterface::class));
        $out = $protocol->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', McpProtocol::VERSION, 'alice');
        return json_encode($out['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function testEveryToolIsAValidMcpTool(): void {
        $tools = json_decode($this->toolsListJson(), false, 512, JSON_THROW_ON_ERROR)->result->tools;
        $this->assertGreaterThan(20, count($tools));
        $names = [];
        foreach ($tools as $tool) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $tool->name);
            $names[] = $tool->name;
            $this->assertIsString($tool->description);
            $this->assertNotSame('', trim($tool->description), $tool->name);
            $schema = $tool->inputSchema;
            $this->assertIsObject($schema, $tool->name);
            $this->assertSame('object', $schema->type, $tool->name);
            $this->assertIsObject($schema->properties, "$tool->name: properties must encode as {} not []");
            if (isset($schema->required)) {
                $this->assertIsArray($schema->required, $tool->name);
                $this->assertNotEmpty($schema->required, "$tool->name: required must be absent or non-empty");
                foreach ($schema->required as $required) {
                    $this->assertTrue(property_exists($schema->properties, $required), "$tool->name: required $required is not a property");
                }
            }
            foreach (get_object_vars($schema->properties) as $property => $rule) {
                $this->assertSchema("$tool->name.$property", $rule);
            }
        }
        $this->assertSame(count($names), count(array_unique($names)), 'tool names must be unique');
    }

    public function testInitializeEncodesCapabilitiesAsObjects(): void {
        $protocol = new McpProtocol(new ToolRegistry([], new GrantPolicy((new InMemoryConfig())->mock($this)), $this->createMock(IAppManager::class),
            $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class)), $this->createMock(LoggerInterface::class));
        $out = $protocol->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'Claude-User', 'version' => '1.0'],
        ]]), '', 'alice');
        $json = json_encode($out['body']);
        $this->assertStringContainsString('"capabilities":{"tools":{}}', $json);
        $result = json_decode($json)->result;
        $this->assertSame('2025-06-18', $result->protocolVersion);
        $this->assertIsString($result->serverInfo->name);
        $this->assertIsString($result->serverInfo->version);
    }

    /** Checks one property schema, recursing into array items and nested objects. */
    private function assertSchema(string $path, mixed $rule): void {
        $this->assertIsObject($rule, "$path must be a schema object");
        $types = is_array($rule->type ?? null) ? $rule->type : [$rule->type ?? null];
        foreach ($types as $type) {
            $this->assertContains($type, self::JSON_TYPES, "$path has invalid type");
        }
        if (property_exists($rule, 'default')) {
            $this->assertTrue($this->matchesType($rule->default, $types), "$path default does not match its type");
        }
        if (isset($rule->enum)) {
            $this->assertIsArray($rule->enum, $path);
            $this->assertNotEmpty($rule->enum, $path);
        }
        if (in_array('array', $types, true) && isset($rule->items)) {
            $this->assertSchema($path . "[]", $rule->items);
        }
        if (in_array('object', $types, true) && isset($rule->properties)) {
            $this->assertIsObject($rule->properties, "$path properties must encode as {}");
            foreach (get_object_vars($rule->properties) as $name => $nested) {
                $this->assertSchema("$path.$name", $nested);
            }
        }
    }

    /** @param list<string> $types */
    private function matchesType(mixed $value, array $types): bool {
        foreach ($types as $type) {
            $ok = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_object($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }
}
