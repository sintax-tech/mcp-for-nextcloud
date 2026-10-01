<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\RedirectUriMatcher;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

final class ClientResolverTest extends TestCase {
    private InMemoryConfig $config;

    private function resolver(bool $fetcherMayBeCalled = false): ClientResolver {
        $this->config = new InMemoryConfig();
        $fetcher = $this->createMock(ClientMetadataFetcher::class);
        if ($fetcherMayBeCalled) {
            $fetcher->method('fetch')->willReturn(new ClientMetadata('https://claude.ai/c', 'Claude', ['https://claude.ai/cb']));
        } else {
            $fetcher->expects($this->never())->method('fetch');
        }
        return new ClientResolver($fetcher, $this->config->mock($this));
    }

    public function testEnabledNativeClientResolvesWithoutTheFetcher(): void {
        $resolver = $this->resolver();
        $this->config->app['mcp'][NativeClient::CONFIG_KEY] = '1';
        $client = $resolver->resolve(NativeClient::CLIENT_ID);
        $this->assertSame(NativeClient::CLIENT_ID, $client->clientId);
        $this->assertSame(NativeClient::NAME, $client->host());
        $this->assertNotSame('', $client->name);
        foreach (['http://localhost:53421/oauth/callback', 'http://127.0.0.1:8080/oauth/callback', 'http://[::1]:9/oauth/callback'] as $ok) {
            $this->assertTrue(RedirectUriMatcher::matches($ok, $client->redirectUris), $ok);
        }
        foreach (['https://localhost/oauth/callback', 'http://localhost/other', 'https://evil.example/oauth/callback', 'http://evil.example/oauth/callback'] as $bad) {
            $this->assertFalse(RedirectUriMatcher::matches($bad, $client->redirectUris), $bad);
        }
    }

    public function testNativeClientIsOffByDefaultAndWhenZero(): void {
        $resolver = $this->resolver();
        foreach ([null, '0'] as $value) {
            if ($value !== null) {
                $this->config->app['mcp'][NativeClient::CONFIG_KEY] = $value;
            }
            try {
                $resolver->resolve(NativeClient::CLIENT_ID);
                $this->fail('disabled native client must be refused');
            } catch (OAuthException $e) {
                $this->assertSame('invalid_client', $e->error);
                $this->assertFalse($e->redirectable);
            }
        }
    }

    public function testOtherIdsGoToTheFetcher(): void {
        $this->assertSame('Claude', $this->resolver(true)->resolve('https://claude.ai/c')->name);
    }
}
