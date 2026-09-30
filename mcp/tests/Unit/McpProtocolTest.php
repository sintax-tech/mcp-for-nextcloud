<?php
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

    public function testToolsListExposesOnlyTheDiagnosticTool(): void {
        $tools = $this->call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['body']['result']['tools'];
        $this->assertSame(['mcp_status'], array_column($tools, 'name'));
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
        $mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $policy = new \OCA\Mcp\Service\GrantPolicy((new InMemoryConfig())->mock($test));
        return new McpProtocol(new \OCA\Mcp\Tools\ToolRegistry([], $policy,
            $mock(\OCP\App\IAppManager::class), $mock(\OCP\IUserManager::class), $mock(\Psr\Log\LoggerInterface::class)),
            new \OCA\Mcp\Service\PromptCatalog(), $policy, $logger ?? $mock(\Psr\Log\LoggerInterface::class));
    }

    private function codes(array $out): array {
        return [$out['status'], $out['body']['error']['code']];
    }
}
