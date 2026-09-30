<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\McpProtocol;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\ToolPresentation;
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
    private function toolsListJson(bool $optionalAppsEnabled = true): string {
        return $this->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', McpProtocol::VERSION, '{}', $optionalAppsEnabled);
    }

    /** @return string initialize result body of the legacy era */
    private function initializeJson(bool $optionalAppsEnabled = true): string {
        return $this->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'Claude-User', 'version' => '1.0'],
        ]]), '', '{"method":"","name":""}', $optionalAppsEnabled);
    }

    /** @return string server/discover result body of the modern era */
    private function discoverJson(): string {
        return $this->handle('{"jsonrpc":"2.0","id":"discover-1","method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28"}}}',
            McpProtocol::MODERN_VERSION, '{"method":"server/discover","name":""}');
    }

    /**
     * Runs one request against the real modules with every grant granted and every app enabled.
     *
     * @return string response body exactly as the endpoint encodes it
     */
    private function handle(string $request, string $version, string $headers = '{}', bool $optionalAppsEnabled = true): string {
        $config = new InMemoryConfig();
        $policy = new GrantPolicy($config->mock($this));
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $policy->setGrant('alice', $module, $operation, true);
            }
        }
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn($optionalAppsEnabled);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $modules = array_map(fn (string $class) => $this->build($class), Application::MODULES);
        $protocol = new McpProtocol(new ToolRegistry($modules, $policy, $apps, $users, $this->createMock(LoggerInterface::class)), $this->createMock(LoggerInterface::class));
        $out = $protocol->handle($request, $version, 'alice', json_decode($headers, true));
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

    public function testAppInitializesAndListsFilesWhenOptionalAppsAreUnavailable(): void {
        $tools = json_decode($this->toolsListJson(false), false, 512, JSON_THROW_ON_ERROR)->result->tools;

        $this->assertNotEmpty($tools);
        $names = array_column($tools, 'name');
        $this->assertContains('files_list', $names);
        $this->assertContains('mcp_status', $names);
        $this->assertSame([], array_values(array_filter($names, static fn (string $name) => preg_match('/^(notes|calendar|deck|talk)_/', $name) === 1)));
        $initialize = json_decode($this->initializeJson(false), false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(ToolPresentation::INSTRUCTIONS, $initialize->result->instructions);
    }

    /**
     * Clients show Tool.title and annotations.title instead of the technical name, so every tool must
     * carry both, and the hints must be present and consistent (a read tool is idempotent, a
     * destructive operation is flagged, and no tool reaches outside the caller's Nextcloud).
     */
    public function testEveryToolHasAFriendlyTitleAndValidAnnotations(): void {
        $tools = json_decode($this->toolsListJson(), false, 512, JSON_THROW_ON_ERROR)->result->tools;
        foreach ($tools as $tool) {
            $this->assertIsString($tool->title ?? null, "$tool->name: title must be a string");
            $this->assertNotSame('', trim($tool->title), "$tool->name: title must not be empty");
            $this->assertNotSame($tool->name, $tool->title, "$tool->name: title must not repeat the technical name");
            $this->assertSame($tool->title, $tool->annotations->title ?? null, "$tool->name: annotations.title must match title");
            foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint) {
                $this->assertIsBool($tool->annotations->$hint ?? null, "$tool->name: annotations.$hint must be a boolean");
            }
            $this->assertFalse($tool->annotations->openWorldHint, "$tool->name: tools stay inside the caller's Nextcloud");
            if ($tool->annotations->readOnlyHint) {
                $this->assertTrue($tool->annotations->idempotentHint, "$tool->name: a read-only tool is idempotent");
                $this->assertFalse($tool->annotations->destructiveHint, "$tool->name: a read-only tool cannot be destructive");
            }
        }
    }

    /**
     * The title map is the source of truth for the display layer; the humanized fallback only keeps a
     * new tool from breaking at runtime, so a missing entry must fail here instead.
     */
    public function testEveryRegisteredToolIsInTheTitleMap(): void {
        $tools = json_decode($this->toolsListJson(), false, 512, JSON_THROW_ON_ERROR)->result->tools;
        $missing = [];
        foreach ($tools as $tool) {
            if (ToolPresentation::title($tool->name) === ToolPresentation::humanized($tool->name)) {
                $missing[] = $tool->name;
            }
        }
        $this->assertSame([], $missing, 'these tools are missing a friendly title in ToolPresentation');
    }

    public function testInitializeSendsDisplayInstructions(): void {
        $result = json_decode($this->initializeJson())->result;
        $this->assertSame(ToolPresentation::INSTRUCTIONS, $result->instructions);
        $discover = json_decode($this->discoverJson())->result;
        $this->assertSame(ToolPresentation::INSTRUCTIONS, $discover->instructions);
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
