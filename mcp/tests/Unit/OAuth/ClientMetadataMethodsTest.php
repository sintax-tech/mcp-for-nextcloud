<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

final class ClientMetadataMethodsTest extends TestCase {
    private const CLAUDE = 'https://claude.ai/oauth/mcp-client-metadata';
    private const CHATGPT = 'https://chatgpt.com/oauth/abc123/client.json';

    private InMemoryConfig $config;
    private ?string $cached = null;
    private int $downloads = 0;

    /** @param array<string,mixed> $document */
    private function fetcher(array $document): ClientMetadataFetcher {
        $this->config = new InMemoryConfig();
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeader')->willReturn('');
        $response->method('getBody')->willReturnCallback(function () use ($document) {
            $stream = fopen('php://memory', 'w+');
            fwrite($stream, json_encode($document));
            rewind($stream);
            return $stream;
        });
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(function () use ($response) {
            $this->downloads++;
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn () => $this->cached);
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);
        return new ClientMetadataFetcher($clients, $this->config->mock($this), $factory);
    }

    /** @param array<string,mixed> $extra */
    private function chatgpt(array $extra): array {
        return $extra + ['client_id' => self::CHATGPT, 'client_name' => 'ChatGPT',
            'redirect_uris' => ['https://chatgpt.com/connector/oauth/abc123']];
    }

    private function assertRefused(ClientMetadataFetcher $fetcher, string $id): void {
        try {
            $fetcher->fetch($id);
            $this->fail('client must be refused');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
        }
    }

    public function testRealChatGptDocumentIsAccepted(): void {
        $fetcher = $this->fetcher($this->chatgpt([
            'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
        ]));
        $this->assertSame(self::CHATGPT, $fetcher->fetch(self::CHATGPT)->clientId);
    }

    public function testClaudeDocumentWithSingularNoneIsStillAccepted(): void {
        $fetcher = $this->fetcher(['client_id' => self::CLAUDE, 'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'none']);
        $this->assertSame(self::CLAUDE, $fetcher->fetch(self::CLAUDE)->clientId);
    }

    public function testSingularPrivateKeyJwtWithoutPluralIsRefused(): void {
        $this->assertRefused($this->fetcher($this->chatgpt(['token_endpoint_auth_method' => 'private_key_jwt'])), self::CHATGPT);
    }

    public function testPluralListMustBeNonEmptyStringsContainingNone(): void {
        foreach ([['private_key_jwt'], [], [1], ['none', 1], 'none'] as $plural) {
            $this->assertRefused($this->fetcher($this->chatgpt([
                'token_endpoint_auth_method' => 'none',
                'token_endpoint_auth_methods_supported' => $plural,
            ])), self::CHATGPT);
        }
    }

    public function testChatGptHostIsAllowedByDefaultAndLookalikesAreNot(): void {
        $fetcher = $this->fetcher($this->chatgpt(['token_endpoint_auth_methods_supported' => ['none']]));
        $this->assertSame(self::CHATGPT, $fetcher->fetch(self::CHATGPT)->clientId);
        $this->assertSame('claude.ai,chatgpt.com', ClientMetadataFetcher::DEFAULT_HOSTS);
        $this->assertRefused($fetcher, 'https://evil.chatgpt.com.attacker.tld/oauth/x/client.json');
        $this->assertRefused($fetcher, 'https://chatgpt.com.attacker.tld/oauth/x/client.json');
    }

    /**
     * The document each of these ids would download is valid and names the very id asked for, so the only
     * thing standing between the id and a download is the allowlist: a match by suffix or substring would
     * let a host the administrator never listed be fetched.
     *
     * @return array<string, array{string}>
     */
    public static function lookalikeHostsProvider(): array {
        return [
            'allowed name inside a longer domain' => ['https://chatgpt.com.attacker.tld/oauth/x/client.json'],
            'subdomain chain below an attacker domain' => ['https://evil.chatgpt.com.attacker.tld/oauth/x/client.json'],
            'suffix of the allowed name glued to a prefix' => ['https://evilchatgpt.com/oauth/x/client.json'],
            'allowed name as a label of another host' => ['https://notclaude.ai/oauth/x/client.json'],
            'subdomain of an allowed host' => ['https://sub.claude.ai/oauth/x/client.json'],
            'unrelated host' => ['https://attacker.tld/client.json'],
            'allowed host only in the userinfo' => ['https://claude.ai@attacker.tld/oauth/x/client.json'],
            'allowed host only in the path' => ['https://attacker.tld/claude.ai/client.json'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lookalikeHostsProvider')]
    public function testAHostOutsideTheAllowlistIsRefusedWithoutAnyDownload(string $id): void {
        $fetcher = $this->fetcher(['client_id' => $id, 'client_name' => 'Attacker',
            'redirect_uris' => ['https://attacker.tld/cb'], 'token_endpoint_auth_method' => 'none']);

        $this->assertRefused($fetcher, $id);
        $this->assertSame(0, $this->downloads, 'the allowlist refuses before the network is touched');
    }

    /** @return array<string, array{string}> */
    public static function malformedIdsProvider(): array {
        return [
            'plain http' => ['http://claude.ai/oauth/x/client.json'],
            'userinfo with credentials' => ['https://user:pass@claude.ai/oauth/x/client.json'],
            'fragment' => ['https://claude.ai/oauth/x/client.json#frag'],
            'empty path' => ['https://claude.ai'],
            'only a slash' => ['https://claude.ai/'],
            'not a URL' => ['claude.ai'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedIdsProvider')]
    public function testAMalformedIdOnAnAllowedHostIsRefusedWithoutAnyDownload(string $id): void {
        $fetcher = $this->fetcher(['client_id' => $id, 'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/cb'], 'token_endpoint_auth_method' => 'none']);

        $this->assertRefused($fetcher, $id);
        $this->assertSame(0, $this->downloads);
    }

    public function testEmptyHostConfigUsesDefaultsForClaudeAndChatGpt(): void {
        foreach (['', " , , \t "] as $raw) {
            foreach ([self::CLAUDE, self::CHATGPT] as $id) {
                $fetcher = $this->fetcher(['client_id' => $id, 'client_name' => 'Public client',
                    'redirect_uris' => ['https://claude.ai/callback'], 'token_endpoint_auth_method' => 'none']);
                $this->config->app['mcp'][ClientMetadataFetcher::HOSTS_KEY] = $raw;
                $this->assertSame($id, $fetcher->fetch($id)->clientId);
                $this->assertRefused($fetcher, 'https://attacker.tld/client.json');
            }
        }
    }

    public function testHostRemovedFromTheKeyBlocksEvenWithACachedDocument(): void {
        $document = $this->chatgpt(['token_endpoint_auth_methods_supported' => ['none']]);
        $fetcher = $this->fetcher($document);
        $this->cached = json_encode($document);
        $this->config->app['mcp'][ClientMetadataFetcher::HOSTS_KEY] = 'claude.ai';
        $this->assertRefused($fetcher, self::CHATGPT);
        $this->assertSame(0, $this->downloads);
    }
}
