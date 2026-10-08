<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Service\McpProtocol;
use PHPUnit\Framework\TestCase;

final class McpProtocolTest extends TestCase {
    private const V = McpProtocol::VERSION;

    private function call(array|string $message, string $version = self::V): array {
        $raw = is_string($message) ? $message : json_encode($message);
        return self::protocol($this)->handle($raw, $version, 'alice');
    }

    public function testInitializeNegotiatesSupportedVersion(): void {
        $out = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'c', 'version' => '1'],
        ]], '');
        $this->assertSame(200, $out['status']);
        $this->assertSame('2025-06-18', $out['body']['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $out['body']['result']['capabilities']);
        $this->assertSame('nextcloud-mcp', $out['body']['result']['serverInfo']['name']);
    }

    public function testInitializeWithoutClientInfoIsInvalidParams(): void {
        $out = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => self::V]], '');
        $this->assertSame([200, -32602], [$out['status'], $out['body']['error']['code']]);
    }

    public function testInitializedNotificationIs202WithoutBody(): void {
        $this->assertSame(['status' => 202, 'body' => null], $this->call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function testInitializeIgnoresTheVersionHeader(): void {
        $init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'Claude-User', 'version' => '1'],
        ]];
        foreach (['', '2025-11-25', '2099-01-01', 'garbage'] as $header) {
            $out = $this->call($init, $header);
            $this->assertSame(200, $out['status'], $header);
            $this->assertSame('2025-06-18', $out['body']['result']['protocolVersion']);
        }
    }

    public function testLaterRequestsAcceptKnownVersionsOrNoHeader(): void {
        $list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'];
        foreach (['', '2025-03-26', '2025-06-18', '2025-11-25'] as $header) {
            $this->assertSame(200, $this->call($list, $header)['status'], $header);
        }
        $this->assertSame(202, $this->call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], '2025-11-25')['status']);
    }

    public function testUnknownOrMalformedVersionIs400AndLoggedAtDebug(): void {
        $logged = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(function (string $message, array $context) use (&$logged): void { $logged[] = [$message, $context]; });
        $protocol = self::protocol($this, $logger);
        $list = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $old = $protocol->handle($list, '2024-11-05', 'alice');
        $this->assertSame([400, -32022, '2024-11-05'], [$old['status'], $old['body']['error']['code'], $old['body']['error']['data']['requested']]);
        $bad = $protocol->handle($list, 'Bearer secret-token', 'alice');
        $this->assertSame([400, 'malformed'], [$bad['status'], $bad['body']['error']['data']['requested']]);
        $this->assertStringNotContainsString('secret', json_encode($bad));
        $this->assertSame(400, $protocol->handle('{nope', '', 'alice')['status']);
        $this->assertSame([
            ['MCP request rejected: unsupported MCP-Protocol-Version', ['app' => 'mcp', 'method' => 'tools/list', 'version' => '2024-11-05']],
            ['MCP request rejected: unsupported MCP-Protocol-Version', ['app' => 'mcp', 'method' => 'tools/list', 'version' => 'malformed']],
            ['MCP request rejected: parse error', ['app' => 'mcp']],
        ], $logged);
        $this->assertStringNotContainsString('secret', json_encode($logged));
    }

    public function testAnyClientNotificationIsAcknowledged(): void {
        $this->assertSame(['status' => 202, 'body' => null], $this->call(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]]));
        $this->assertSame(400, $this->call(['jsonrpc' => '2.0', 'method' => 'tools/list'])['status']);
    }

    public function testToolsListExposesTheTwoBuiltInTools(): void {
        $tools = $this->call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['body']['result']['tools'];
        // No module is registered in this fixture: what is left are the two tools the server owns itself.
        $this->assertSame(['mcp_status', 'mcp_guide'], array_column($tools, 'name'));
        $this->assertTrue($tools[1]['annotations']['readOnlyHint']);
    }

    public function testToolsCallReturnsContentWithOrWithoutArguments(): void {
        foreach ([['name' => 'mcp_status'], ['name' => 'mcp_status', 'arguments' => new \stdClass()]] as $params) {
            $out = $this->call(['jsonrpc' => '2.0', 'id' => 'x', 'method' => 'tools/call', 'params' => $params]);
            $this->assertSame('x', $out['body']['id']);
            $this->assertSame('text', $out['body']['result']['content'][0]['type']);
        }
    }

    public function testUnknownToolOrArgumentsAreInvalidParams(): void {
        foreach ([['name' => 'files_read', 'arguments' => ['path' => '/']], ['name' => 'mcp_status', 'arguments' => ['a' => 1]]] as $params) {
            $out = $this->call(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => $params]);
            $this->assertSame([200, -32602], [$out['status'], $out['body']['error']['code']]);
            $this->assertArrayNotHasKey('result', $out['body']);
        }
    }

    public function testErrorCodes(): void {
        $this->assertSame([400, -32700], $this->codes($this->call('{nope')));
        $this->assertSame([400, -32600], $this->codes($this->call([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']])));
        $this->assertSame([400, -32600], $this->codes($this->call(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'])));
        $this->assertSame([400, -32600], $this->codes($this->call(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'])));
        $this->assertSame([200, -32601], $this->codes($this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'])));
        $this->assertSame([200, -32602], $this->codes($this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [1]])));
    }

    public function testErrorsDoNotLeakInternals(): void {
        $out = $this->call('{"jsonrpc":"2.0","id":1,"method":');
        $this->assertSame('Parse error', $out['body']['error']['message']);
        $this->assertArrayNotHasKey('data', $out['body']['error']);
    }

    /** Protocol with an empty module list: only the diagnostic tool exists. */
    public static function protocol(TestCase $test, ?\Psr\Log\LoggerInterface $logger = null): McpProtocol {
        return self::withModules($test, [], $logger);
    }

    /**
     * The error message is filtered before it leaves the server, so what a bad argument looks like to the
     * client is a question about this layer and not only about the validator. A camelCase name and a nested
     * path have to survive as themselves; anything shaped like server state has to be dropped.
     */
    public function testArgumentErrorsReachTheClientNamingTheField(): void {
        $module = new class implements \OCA\Mcp\Tools\ToolModule {
            public function definitions(): array {
                return [['name' => 'exemplo', 'description' => 'x', 'module' => 'files', 'operation' => 'read',
                    'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                        'boardId' => ['type' => 'string'],
                        'moves' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 200, 'items' => [
                            'type' => 'object', 'additionalProperties' => false,
                            'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string']],
                            'required' => ['from', 'to'],
                        ]],
                    ]]]];
            }
            public function call(string $name, array $arguments, string $userId): array {
                return \OCA\Mcp\Tools\ToolResult::json(['ok' => true]);
            }
        };
        $casos = [
            [['boardId' => 1], 'boardId: expected string'],
            [['moves' => [['from' => 987654, 'to' => '/b']]], 'moves[0].from: expected string'],
            [['secret-key' => 'secret-value'], 'arguments: unknown property; use only declared arguments'],
            [['moves' => [['from' => '/a', 'to' => '/b'], ['from' => '/a']]], 'moves[1].to: required argument'],
        ];
        foreach ($casos as [$arguments, $esperado]) {
            $out = $this->callWith($module, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'exemplo', 'arguments' => $arguments]]);
            $this->assertSame($esperado, $out['body']['error']['message'], json_encode($arguments));
            $this->assertArrayHasKey('field', $out['body']['error']['data']);
            $this->assertArrayHasKey('rule', $out['body']['error']['data']);
            foreach (['987654', 'secret-key', 'secret-value'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($out));
            }
        }
    }

    /**
     * The filter exists so a path, a file name or a fragment of server state cannot ride out inside an error
     * message. Anything outside the argument-name charset has to come back as the generic answer.
     */
    public function testServerStateNeverRidesOutInsideAnErrorMessage(): void {
        foreach (['/etc/passwd', '/home/pedro/Documentos/ata.md', 'a b', 'a;b', 'a\\b', "a'; DROP TABLE users;--", '<script>'] as $vazamento) {
            $out = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'mcp_status', 'arguments' => ['a' => 1]]]);
            // The registry only ever builds a message from declared keys, so the charset is exercised directly.
            $this->assertSame('Invalid arguments', $this->filtered('Invalid argument: ' . $vazamento), $vazamento);
            $this->assertSame([200, -32602], $this->codes($out));
        }
    }

    /** Semantic date failures retain the field and rule without disclosing input. */
    public function testInvalidDateHasStructuredDetails(): void {
        $module = new class implements \OCA\Mcp\Tools\ToolModule {
            public function definitions(): array {
                return [['name' => 'date_test', 'description' => 'x', 'module' => 'files', 'operation' => 'read',
                    'inputSchema' => ['properties' => ['start' => ['type' => 'string']]]]];
            }
            public function call(string $name, array $arguments, string $userId): array {
                (new \OCA\Mcp\Tools\Calendar\DateInput())->parse($arguments['start'], 'start');
                return [];
            }
        };
        $out = $this->callWith($module, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'date_test', 'arguments' => ['start' => '2026-10-01T17:00:00']]]);
        $this->assertSame(-32602, $out['body']['error']['code']);
        $this->assertSame('start', $out['body']['error']['data']['field']);
        $this->assertStringContainsString('Z or offset', $out['body']['error']['data']['rule']);
        $this->assertStringContainsString('start:', $out['body']['error']['message']);
        $this->assertStringNotContainsString('2026-10-01T17:00:00', json_encode($out));
    }

    /** @return array<string, mixed> the response to calling a one-tool module that runs $action */
    private function failing(string $tool, array $arguments, callable $action): array {
        $module = new class($tool, $action) implements \OCA\Mcp\Tools\ToolModule {
            public function __construct(private string $tool, private $action) {}
            public function definitions(): array {
                return [['name' => $this->tool, 'description' => 'x', 'module' => 'files', 'operation' => 'read',
                    'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['path' => ['type' => 'string']]]]];
            }
            public function call(string $name, array $arguments, string $userId): array {
                ($this->action)($arguments);
                return [];
            }
        };
        return $this->callWith($module, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
    }

    /** A path that leaves the user's folder is an argument error like any other: field, fixed rule, no value. */
    public function testPathTraversalAnswersWithTheFieldAndAFixedRule(): void {
        $out = $this->failing('ler', ['path' => '/../../etc/passwd'], static fn (array $a) => \OCA\Mcp\Tools\Common\PathGuard::normalize($a['path']));
        $this->assertSame(-32602, $out['body']['error']['code']);
        $this->assertSame('path', $out['body']['error']['data']['field']);
        $this->assertNotSame('', $out['body']['error']['data']['rule']);
        $this->assertStringStartsWith('path: ', $out['body']['error']['message']);
        $this->assertStringNotContainsString('passwd', json_encode($out));
        $this->assertStringNotContainsString('etc', json_encode($out));
    }

    /** A control character in a path is the same kind of error, with the same answer. */
    public function testAControlCharacterInAPathAnswersTheSameWay(): void {
        $out = $this->failing('ler', ['path' => "/a\0b"], static fn (array $a) => \OCA\Mcp\Tools\Common\PathGuard::normalize($a['path']));
        $this->assertSame('path', $out['body']['error']['data']['field']);
        $this->assertNotSame('', $out['body']['error']['data']['rule']);
    }

    /** A tool that does not exist keeps its message and now carries a rule too, without echoing the name asked for. */
    public function testAnUnknownToolAnswersWithARule(): void {
        $out = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'ferramenta_secreta_xyz', 'arguments' => []]]);
        $this->assertSame(-32602, $out['body']['error']['code']);
        $this->assertSame('Unknown tool', $out['body']['error']['message']);
        $this->assertSame('name', $out['body']['error']['data']['field']);
        $this->assertNotSame('', $out['body']['error']['data']['rule']);
        $this->assertStringNotContainsString('secreta', json_encode($out));
    }

    /** The unknown-property answer always has a non-empty field, so a client can point at something. */
    public function testAnUnknownPropertyNeverAnswersWithAnEmptyField(): void {
        $out = $this->failing('ler', ['bogus' => 1], static fn () => null);
        $this->assertSame('arguments', $out['body']['error']['data']['field']);
        $this->assertSame('arguments: unknown property; use only declared arguments', $out['body']['error']['message']);
    }

    /** What the client is told for a message the validator produced. */
    private function filtered(string $message): string {
        $method = (new \ReflectionMethod(McpProtocol::class, 'safeMessage'));
        $protocol = self::protocol($this);
        return $method->invoke($protocol, $message);
    }

    /** @param \OCA\Mcp\Tools\ToolModule $module the single tool this protocol exposes */
    private function callWith(\OCA\Mcp\Tools\ToolModule $module, array|string $message): array {
        return self::withModules($this, [$module])->handle(json_encode($message), self::V, 'alice');
    }

    /** @param list<\OCA\Mcp\Tools\ToolModule> $modules tools the protocol may expose */
    private static function withModules(TestCase $test, array $modules, ?\Psr\Log\LoggerInterface $logger = null): McpProtocol {
        $mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy((new InMemoryConfig())->mock($test), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        return new McpProtocol(new \OCA\Mcp\Tools\ToolRegistry($modules, $policy,
            $mock(\OCP\App\IAppManager::class), $mock(\OCP\IUserManager::class), $mock(\Psr\Log\LoggerInterface::class)),
            new \OCA\Mcp\Service\PromptCatalog(), $policy, $logger ?? $mock(\Psr\Log\LoggerInterface::class));
    }

    private function codes(array $out): array {
        return [$out['status'], $out['body']['error']['code']];
    }
}
