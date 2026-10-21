<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use InvalidArgumentException;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\RedirectUriMatcher;
use OCA\Mcp\OAuth\Connections\ConnectionList;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomClientTest extends TestCase {
    private const URI = 'https://harness.example.com/oauth/callback';
    private const A = 'aaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbb';

    private ClientsTable $clients;

    private function resolver(): ClientResolver {
        $this->clients = new ClientsTable($this);
        $fetcher = $this->createMock(ClientMetadataFetcher::class);
        $fetcher->expects($this->never())->method('fetch');
        return new ClientResolver($fetcher, $this->clients->mapper);
    }

    /** @param list<array<string, mixed>> $clients */
    private function store(array $clients): void {
        $this->clients->custom($clients);
    }

    /** @return array{id:string, name:string, enabled:bool, redirectUris:list<string>} */
    private static function client(string $id, string $name, bool $enabled = true, array $uris = [self::URI]): array {
        return ['id' => $id, 'name' => $name, 'enabled' => $enabled, 'redirectUris' => $uris];
    }

    private function assertInvalidClient(ClientResolver $resolver, string $clientId): void {
        try {
            $resolver->resolve($clientId);
            $this->fail('the client must be refused: ' . $clientId);
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
            $this->assertFalse($e->redirectable);
        }
    }

    public function testNoClientIsAcceptedUntilOneIsAdded(): void {
        $resolver = $this->resolver();
        $this->assertInvalidClient($resolver, CustomClient::clientId(self::A));
        $this->assertInvalidClient($resolver, 'nextcloud-mcp-custom');
        $this->assertSame([], CustomClient::clients($this->clients->mapper));
    }

    /**
     * A stored row that does not validate (unreadable URIs, a generation that is not a canonical positive counter, as an
     * overflow or a manual edit would leave) fails closed: the client is refused and never read with a default gen.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidRows(): array {
        return [
            'unreadable URIs' => [['redirect_uris' => 'not json']],
            'gen above BIGINT' => [['gen' => '9223372036854775808']],
            'gen zero' => [['gen' => '0']],
            'gen negative' => [['gen' => '-1']],
            'gen as float' => [['gen' => '9.2233720368548E+18']],
            'enabled not 0 or 1' => [['enabled' => 2]],
        ];
    }

    #[DataProvider('invalidRows')]
    public function testAStoredRowThatDoesNotValidateRefusesTheClient(array $columns): void {
        $resolver = $this->resolver();
        $this->store([self::client(self::A, 'QwenPaw')]);
        $this->clients->db->dbal->update(\OCA\Mcp\Db\OAuthClientsMapper::TABLE, $columns, ['client_id' => CustomClient::clientId(self::A)]);
        $this->assertInvalidClient($resolver, CustomClient::clientId(self::A));
        $this->assertFalse(CustomClient::active($this->clients->mapper, CustomClient::clientId(self::A)));
        $this->assertFalse(CustomClient::admits($this->clients->mapper, CustomClient::clientId(self::A), null, null));
        $this->assertTrue(ClientResolver::builtInDisabled($this->clients->mapper, CustomClient::clientId(self::A)));
        $this->expectException(\UnexpectedValueException::class);
        CustomClient::clients($this->clients->mapper);
    }

    /** R3 of the re-reading: the generation is a canonical BIGINT that never wraps nor falls back to 1. */
    public function testTheGenerationAdvancesByDecimalCarryAndAnExhaustedOneRefuses(): void {
        $id = CustomClient::clientId(self::A);
        $next = [self::client(self::A, 'QwenPaw', false)];
        $this->assertSame('10', CustomClient::stamped($next, [self::client(self::A, 'QwenPaw') + ['gen' => '9']], [$id])[0]['gen']);
        $this->assertSame('9223372036854775807', CustomClient::stamped($next, [self::client(self::A, 'QwenPaw') + ['gen' => '9223372036854775806']], [$id])[0]['gen']);
        $this->assertSame('9223372036854775807', CustomClient::stamped($next, [self::client(self::A, 'QwenPaw') + ['gen' => '9223372036854775807']], [])[0]['gen'],
            'a client that is not withdrawn keeps its generation');
        $this->expectException(\OCA\Mcp\Access\Policy\PolicyUnavailable::class);
        CustomClient::stamped($next, [self::client(self::A, 'QwenPaw') + ['gen' => '9223372036854775807']], [$id]);
    }

    public function testEachClientResolvesWithItsNameAndOnlyItsOwnUris(): void {
        $resolver = $this->resolver();
        $this->store([self::client(self::A, 'QwenPaw'), self::client(self::B, 'Other', true, ['https://other.example.org/cb?tenant=a'])]);
        $client = $resolver->resolve('nextcloud-mcp-custom-' . self::A);
        $this->assertSame('nextcloud-mcp-custom-' . self::A, $client->clientId);
        $this->assertSame('QwenPaw', $client->name);
        $this->assertSame('QwenPaw', $client->host());
        $this->assertTrue(RedirectUriMatcher::matches(self::URI, $client->redirectUris));
        foreach ([self::URI . '/', self::URI . '?x=1', 'https://harness.example.com/oauth/other', 'https://harness.example.com:8443/oauth/callback',
            'http://harness.example.com/oauth/callback', 'https://evil.example/oauth/callback', 'https://other.example.org/cb?tenant=a',
            'https://harness.example.com.evil.example/oauth/callback'] as $bad) {
            $this->assertFalse(RedirectUriMatcher::matches($bad, $client->redirectUris), $bad);
        }
        $this->assertSame('Other', $resolver->resolve(CustomClient::clientId(self::B))->name);
    }

    public function testADisabledOrUnknownClientIsRefusedAndTheOthersStillWork(): void {
        $resolver = $this->resolver();
        $this->store([self::client(self::A, 'QwenPaw', false), self::client(self::B, 'Other')]);
        $this->assertInvalidClient($resolver, CustomClient::clientId(self::A));
        $this->assertInvalidClient($resolver, CustomClient::clientId('cccccccccccc'));
        $this->assertInvalidClient($resolver, CustomClient::clientId(self::B) . 'x');
        $this->assertSame('Other', $resolver->resolve(CustomClient::clientId(self::B))->name);
    }

    public function testSelfHostedClientsDoNotOpenTheNativeOne(): void {
        $resolver = $this->resolver();
        $this->store([self::client(self::A, 'QwenPaw')]);
        $this->assertInvalidClient($resolver, NativeClient::CLIENT_ID);
    }

    public function testBuiltInDisabledCoversTheNativeClientAndEverySelfHostedIdThatIsNotActive(): void {
        $this->resolver();
        $this->store([self::client(self::A, 'QwenPaw'), self::client(self::B, 'Other', false)]);
        $this->assertFalse(ClientResolver::builtInDisabled($this->clients->mapper, CustomClient::clientId(self::A)));
        $this->assertTrue(ClientResolver::builtInDisabled($this->clients->mapper, CustomClient::clientId(self::B)));
        $this->assertTrue(ClientResolver::builtInDisabled($this->clients->mapper, CustomClient::clientId('cccccccccccc')));
        $this->assertTrue(ClientResolver::builtInDisabled($this->clients->mapper, 'nextcloud-mcp-custom'));
        $this->assertTrue(ClientResolver::builtInDisabled($this->clients->mapper, NativeClient::CLIENT_ID));
        $this->assertFalse(ClientResolver::builtInDisabled($this->clients->mapper, 'https://claude.ai/c'));
    }

    public function testValidationGivesNewClientsARandomIdAndKeepsStoredOnes(): void {
        $current = [self::client(self::A, 'QwenPaw')];
        $clients = CustomClient::validate([
            ['id' => self::A, 'name' => '  QwenPaw 2 ', 'enabled' => false, 'redirectUris' => ['  ' . self::URI . "\t", self::URI]],
            ['name' => 'Other', 'enabled' => true, 'redirectUris' => ['https://Other.Example.org/CB?a=1&b=2', 'http://127.0.0.1:8080/cb']],
            ['id' => null, 'name' => 'Third', 'enabled' => true, 'redirectUris' => ['http://localhost/cb']],
        ], $current);
        $this->assertSame(self::client(self::A, 'QwenPaw 2', false), $clients[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $clients[1]['id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $clients[2]['id']);
        $this->assertNotSame($clients[1]['id'], $clients[2]['id']);
        $this->assertSame(['https://Other.Example.org/CB?a=1&b=2', 'http://127.0.0.1:8080/cb'], $clients[1]['redirectUris']);
        $this->assertSame([], CustomClient::validate([], $current));
        $max = array_map(fn (int $i) => ['name' => 'c' . $i, 'enabled' => true, 'redirectUris' => [self::URI]], range(1, CustomClient::MAX_CLIENTS));
        $this->assertCount(CustomClient::MAX_CLIENTS, CustomClient::validate($max, []));
        $this->assertCount(CustomClient::MAX_URIS, CustomClient::validate([['name' => 'n', 'enabled' => true,
            'redirectUris' => array_map(fn (int $i) => self::URI . $i, range(1, CustomClient::MAX_URIS))]], [])[0]['redirectUris']);
        $this->assertSame(str_repeat('é', CustomClient::MAX_NAME), CustomClient::validate([['name' => str_repeat('é', CustomClient::MAX_NAME),
            'enabled' => true, 'redirectUris' => [self::URI]]], [])[0]['name']);
    }

    /** @return array<string, array{0:mixed}> */
    public static function invalidClients(): array {
        $ok = ['name' => 'QwenPaw', 'enabled' => true, 'redirectUris' => [self::URI]];
        return [
            'not a list' => [['a' => $ok]],
            'a string' => ['QwenPaw'],
            'entry not an object' => [['QwenPaw']],
            'too many' => [array_map(fn (int $i) => ['name' => 'c' . $i] + $ok, range(0, CustomClient::MAX_CLIENTS))],
            'id not stored' => [[['id' => 'cccccccccccc'] + $ok]],
            'id not a string' => [[['id' => 12] + $ok]],
            'id repeated' => [[['id' => self::A] + $ok, ['id' => self::A, 'name' => 'Other'] + $ok]],
            'no name' => [[['enabled' => true, 'redirectUris' => [self::URI]]]],
            'blank name' => [[['name' => '   '] + $ok]],
            'name too long' => [[['name' => str_repeat('a', CustomClient::MAX_NAME + 1)] + $ok]],
            'name with a newline' => [[['name' => "Qwen\nPaw"] + $ok]],
            'name with a control character' => [[['name' => "Qwen\x07Paw"] + $ok]],
            'name not UTF-8' => [[['name' => "Qwen\xffPaw"] + $ok]],
            'name not a string' => [[['name' => 42] + $ok]],
            'same name ignoring case' => [[$ok, ['name' => 'qwenPAW'] + $ok]],
            'enabled missing' => [[['name' => 'QwenPaw', 'redirectUris' => [self::URI]]]],
            'enabled not a boolean' => [[['enabled' => 1] + $ok]],
            'no redirect URI' => [[['redirectUris' => []] + $ok]],
            'redirect URIs not a list' => [[['redirectUris' => self::URI] + $ok]],
            'bad redirect URI' => [[['redirectUris' => ['http://harness.example.com/cb']] + $ok]],
        ];
    }

    #[DataProvider('invalidClients')]
    public function testValidationRejectsClients(mixed $value): void {
        $this->expectException(InvalidArgumentException::class);
        CustomClient::validate($value, [self::client(self::A, 'Stored')]);
    }

    /** @return array<string, array{0:mixed}> */
    public static function invalidUris(): array {
        return [
            'not a list' => [['a' => self::URI]],
            'a string' => [self::URI],
            'null entry' => [[null]],
            'empty entry' => [['  ']],
            'too many' => [array_map(fn (int $i) => self::URI . $i, range(0, CustomClient::MAX_URIS))],
            'too long' => [['https://harness.example.com/' . str_repeat('a', CustomClient::MAX_LENGTH)]],
            // 1,021 characters but 2,043 bytes: the limit is in bytes, so it always fits the 2000-long column.
            'short in characters, long in bytes' => [['https://harness.example.com/' . str_repeat('é', 1000)]],
            'http on a public host' => [['http://harness.example.com/cb']],
            'relative' => [['/oauth/callback']],
            'no host' => [['https:///cb']],
            'other scheme' => [['javascript://harness.example.com/%0aalert(1)']],
            'custom scheme' => [['com.example.app:/cb']],
            'uppercase scheme' => [['HTTPS://harness.example.com/cb']],
            'userinfo' => [['https://user@harness.example.com/cb']],
            'userinfo with password' => [['https://user:pw@harness.example.com/cb']],
            'fragment' => [['https://harness.example.com/cb#x']],
            'wildcard host' => [['https://*.example.com/cb']],
            'wildcard path' => [['https://harness.example.com/*']],
            'inner space' => [['https://harness.example.com/a b']],
            'newline' => [["https://harness.example.com/cb\nhttps://evil.example/cb"]],
            'backslash' => [['https://harness.example.com\\@evil.example/cb']],
        ];
    }

    public function testTheUriLimitCountsBytesAndTheLimitItselfFits(): void {
        $base = 'https://harness.example.com/';
        $atLimit = $base . str_repeat('a', CustomClient::MAX_LENGTH - strlen($base));
        $this->assertSame([$atLimit], CustomClient::validateUris([$atLimit]));
        $this->assertSame(CustomClient::MAX_LENGTH, strlen($atLimit));
        $multibyte = $base . str_repeat('é', (int)((CustomClient::MAX_LENGTH - strlen($base)) / 2));
        $this->assertLessThanOrEqual(CustomClient::MAX_LENGTH, strlen($multibyte));
        $this->assertSame([$multibyte], CustomClient::validateUris([$multibyte]));
    }

    #[DataProvider('invalidUris')]
    public function testValidationRejectsRedirectUris(mixed $value): void {
        $this->expectException(InvalidArgumentException::class);
        CustomClient::validateUris($value);
    }

    public function testWithdrawnListsClientsThatWereDeletedTurnedOffOrLostAUri(): void {
        $before = [
            self::client(self::A, 'Renamed', true, [self::URI]),
            self::client(self::B, 'Off'),
            self::client('cccccccccccc', 'Gone'),
            self::client('dddddddddddd', 'Lost a URI', true, [self::URI, 'https://two.example/cb']),
            self::client('eeeeeeeeeeee', 'Gained a URI'),
            self::client('ffffffffffff', 'Turned on', false),
        ];
        $after = [
            self::client(self::A, 'Renamed again', true, [self::URI]),
            self::client(self::B, 'Off', false),
            self::client('dddddddddddd', 'Lost a URI', true, ['https://two.example/cb']),
            self::client('eeeeeeeeeeee', 'Gained a URI', true, ['https://two.example/cb', self::URI]),
            self::client('ffffffffffff', 'Turned on'),
            self::client('111111111111', 'New'),
        ];
        $this->assertSame([CustomClient::clientId(self::B), CustomClient::clientId('cccccccccccc'), CustomClient::clientId('dddddddddddd')],
            CustomClient::withdrawn($before, $after));
    }

    public function testConnectionListNamesSelfHostedClients(): void {
        $id = CustomClient::clientId(self::A);
        $this->assertSame(['kind' => 'custom', 'host' => '', 'id' => $id, 'name' => 'QwenPaw'], ConnectionList::client($id, [$id => 'QwenPaw']));
        $this->assertSame(['kind' => 'custom', 'host' => '', 'id' => $id, 'name' => ''], ConnectionList::client($id));
    }
}
