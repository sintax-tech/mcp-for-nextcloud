<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Service\McpProtocol;
use PHPUnit\Framework\TestCase;

/** MCP 2026-07-28 (stateless) requests next to the legacy initialize flow on the same endpoint. */
final class McpModernProtocolTest extends TestCase {
    private const MODERN = '2026-07-28';

    /** @param array<string, mixed> $params */
    private function modern(string $method, array $params = [], array $headers = [], ?string $headerVersion = self::MODERN, int|string $id = 1): array {
        $params['_meta'] = ($params['_meta'] ?? []) + [
            'io.modelcontextprotocol/protocolVersion' => self::MODERN,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'Claude-User', 'version' => '1.0'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $raw = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        $out = McpProtocolTest::protocol($this)->handle($raw, (string)$headerVersion, 'alice', $headers + ['method' => $method]);
        return ['status' => $out['status'], 'json' => $out['body'] === null ? null : json_decode(json_encode($out['body']))];
    }

    public function testServerDiscoverWithTheModernHeader(): void {
        $out = $this->modern('server/discover');
        $this->assertSame(200, $out['status']);
        $result = $out['json']->result;
        $this->assertSame('complete', $result->resultType);
        $this->assertSame(McpProtocol::SUPPORTED_VERSIONS, $result->supportedVersions);
        $this->assertSame(self::MODERN, $result->supportedVersions[0]);
        $this->assertEquals(new \stdClass(), $result->capabilities->tools);
        // The prompt set never changes at runtime, so no listChanged notification is advertised.
        $this->assertFalse($result->capabilities->prompts->listChanged);
        $this->assertSame(['name' => 'nextcloud-mcp', 'version' => McpProtocol::SERVER_INFO['version']], (array)$result->_meta->{'io.modelcontextprotocol/serverInfo'});
        $this->assertSame([0, 'private'], [$result->ttlMs, $result->cacheScope]);
        $this->assertStringContainsString('"capabilities":{"tools":{},"prompts":{"listChanged":false},"resources":{}}', json_encode($out['json']));
    }

    public function testFullModernFlowWithoutInitialize(): void {
        $this->assertSame(200, $this->modern('server/discover')['status']);
        $list = $this->modern('tools/list');
        $this->assertSame(200, $list['status']);
        $this->assertSame('complete', $list['json']->result->resultType);
        $this->assertSame([0, 'private'], [$list['json']->result->ttlMs, $list['json']->result->cacheScope]);
        $this->assertSame('mcp_status', $list['json']->result->tools[0]->name);
        $call = $this->modern('tools/call', ['name' => 'mcp_status', 'arguments' => new \stdClass()], ['name' => 'mcp_status']);
        $this->assertSame(200, $call['status']);
        $this->assertSame('complete', $call['json']->result->resultType);
        $this->assertSame('MCP endpoint available', $call['json']->result->content[0]->text);
        $this->assertSame('nextcloud-mcp', $call['json']->result->_meta->{'io.modelcontextprotocol/serverInfo'}->name);
    }

    public function testLegacyFlowIsUnchanged(): void {
        $protocol = McpProtocolTest::protocol($this);
        $init = $protocol->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'c', 'version' => '1'],
        ]]), '', 'alice');
        $this->assertSame([200, '2025-06-18'], [$init['status'], $init['body']['result']['protocolVersion']]);
        $this->assertArrayNotHasKey('resultType', $init['body']['result']);
        $this->assertSame(202, $protocol->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}', '2025-06-18', 'alice')['status']);
        $list = $protocol->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}', '2025-06-18', 'alice');
        $this->assertSame(200, $list['status']);
        $this->assertArrayNotHasKey('resultType', $list['body']['result']);
        $this->assertArrayNotHasKey('ttlMs', $list['body']['result']);
    }

    public function testUnknownFutureVersionGetsTheModernErrorSoTheClientCanRetry(): void {
        $raw = json_encode(['jsonrpc' => '2.0', 'id' => 'x', 'method' => 'tools/list', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2099-01-01']]]);
        $out = McpProtocolTest::protocol($this)->handle($raw, '2099-01-01', 'alice', ['method' => 'tools/list']);
        $this->assertSame(400, $out['status']);
        $this->assertSame(['code' => -32022, 'message' => 'Unsupported protocol version', 'data' => ['supported' => McpProtocol::SUPPORTED_VERSIONS, 'requested' => '2099-01-01']], $out['body']['error']);
        $this->assertSame('x', $out['body']['id']);
    }

    public function testDiscoverAnswersLegacyOrUnversionedProbes(): void {
        foreach (['', '2025-06-18'] as $header) {
            $out = McpProtocolTest::protocol($this)->handle('{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{}}', $header, 'alice');
            $this->assertSame(200, $out['status'], $header);
            $this->assertSame(McpProtocol::SUPPORTED_VERSIONS, $out['body']['result']['supportedVersions']);
        }
    }

    public function testHeaderMismatchesAreRejectedWithMinus32020(): void {
        $version = $this->modern('tools/list', [], [], '2025-06-18');
        $this->assertSame([400, -32020], [$version['status'], $version['json']->error->code]);
        $method = $this->modern('tools/list', [], ['method' => 'tools/call']);
        $this->assertSame([400, -32020], [$method['status'], $method['json']->error->code]);
        $name = $this->modern('tools/call', ['name' => 'mcp_status', 'arguments' => new \stdClass()], ['name' => 'files_read']);
        $this->assertSame([400, -32020], [$name['status'], $name['json']->error->code]);
        $encoded = $this->modern('tools/call', ['name' => 'mcp_status', 'arguments' => new \stdClass()], ['name' => '=?base64?' . base64_encode('mcp_status') . '?=']);
        $this->assertSame(200, $encoded['status']);
    }

    public function testMissingMirroredHeadersAreTolerated(): void {
        $raw = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => self::MODERN]]]);
        $this->assertSame(200, McpProtocolTest::protocol($this)->handle($raw, self::MODERN, 'alice')['status']);
    }

    public function testModernUnknownMethodIs404AndPingIsGone(): void {
        foreach (['resources/list', 'ping'] as $method) {
            $out = $this->modern($method);
            $this->assertSame([404, -32601], [$out['status'], $out['json']->error->code], $method);
        }
    }

    public function testModernToolArgumentErrorsStayInvalidParams(): void {
        $out = $this->modern('tools/call', ['name' => 'files_read', 'arguments' => new \stdClass()], ['name' => 'files_read']);
        $this->assertSame([200, -32602], [$out['status'], $out['json']->error->code]);
    }
}
