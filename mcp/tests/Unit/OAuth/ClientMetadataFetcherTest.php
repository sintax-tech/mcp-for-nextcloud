<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\OAuthException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ClientMetadataFetcherTest extends TestCase {
    private const CLIENT = 'https://claude.ai/client';

    private function fetcher($body, string $length = ''): ClientMetadataFetcher {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeader')->with('Content-Length')->willReturn($length);
        $response->method('getBody')->willReturn($body);
        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('get')->with(self::CLIENT, $this->callback(
            fn (array $options) => ($options['stream'] ?? false) === true && $options['allow_redirects'] === false
                && $options['timeout'] === 5))->willReturn($response);
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('claude.ai');
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($this->createMock(ICache::class));
        return new ClientMetadataFetcher($clients, $config, $factory);
    }

    public function testStreamingDocumentIsAcceptedAtTheSizeBoundaryAndClosed(): void {
        $json = json_encode(['client_id' => self::CLIENT, 'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/callback'], 'token_endpoint_auth_method' => 'none']);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $json . str_repeat(' ', 65536 - strlen($json)));
        rewind($stream);
        $this->assertSame(self::CLIENT, $this->fetcher($stream)->fetch(self::CLIENT)->clientId);
        $this->assertFalse(is_resource($stream));
    }

    public function testOversizedStreamIsRejectedAndClosed(): void {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, str_repeat('x', 65537));
        rewind($stream);
        try {
            $this->fetcher($stream)->fetch(self::CLIENT);
            $this->fail('oversized document must fail');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
        }
        $this->assertFalse(is_resource($stream));
    }

    public function testDeclaredOversizeIsRejectedBeforeReadingTheStream(): void {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, '{}');
        rewind($stream);
        $this->expectException(OAuthException::class);
        $this->fetcher($stream, '65537')->fetch(self::CLIENT);
    }
}
