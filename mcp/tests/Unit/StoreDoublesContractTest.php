<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\Files\InMemoryBatchStore;
use OCA\Mcp\Tools\Files\Batch;
use OCA\Mcp\Tools\Files\BatchStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A double is only worth what it copies. The stores are doubled in most tests of the app, so a double that
 * grows a method the real class lacks, forgets one it has, or answers differently makes those tests pass over
 * code that does not exist or does not behave that way. This is how a missing method of the batch store
 * reached production: the double had it, the class did not.
 *
 * Both halves are checked: the public surface of each double against its real class, and the same
 * scenario run on the double and on the real store over {@see FakeDatabase}.
 */
final class StoreDoublesContractTest extends TestCase {
    /** @return array<string, array{class-string, class-string}> double => real class */
    public static function pairs(): array {
        return [
            'OAuthStore' => [InMemoryOAuthStore::class, OAuthStore::class],
            'CheckoutTokenStore' => [InMemoryCheckoutTokenStore::class, CheckoutTokenStore::class],
            'BatchStore' => [InMemoryBatchStore::class, BatchStore::class],
        ];
    }

    /**
     * @param class-string $class
     * @param bool $declaredHere whether to keep only the methods the class itself declares
     * @return list<string> public method names except the constructor
     */
    private static function methodsOf(string $class, bool $declaredHere): array {
        $names = [];
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || ($declaredHere && $method->getDeclaringClass()->getName() !== $class)) {
                continue;
            }
            $names[] = $method->getName();
        }
        sort($names);
        return $names;
    }

    /**
     * Methods a double may have beyond the real class: ways for a test to look at the rows or to play another
     * client, never something the code under test calls. {@see self::testNoProductionCodeCallsATestOnlyHelper()}
     * guards that last part.
     *
     * @var array<class-string, list<string>>
     */
    private const TEST_ONLY = [InMemoryCheckoutTokenStore::class => ['ofKind', 'moveEtag']];

    /** @param class-string $double @param class-string $real */
    #[DataProvider('pairs')]
    public function testTheDoubleHasNoPublicMethodTheRealClassLacks(string $double, string $real): void {
        $extra = array_values(array_diff(self::methodsOf($double, true), self::methodsOf($real, false), self::TEST_ONLY[$double] ?? []));

        $this->assertSame([], $extra, "$double offers methods that $real does not have");
    }

    public function testNoProductionCodeCallsATestOnlyHelper(): void {
        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source .= $file->getExtension() === 'php' ? (string)file_get_contents($file->getPathname()) : '';
        }
        $this->assertNotSame('', $source);
        foreach (self::TEST_ONLY as $helpers) {
            foreach ($helpers as $helper) {
                $this->assertDoesNotMatchRegularExpression('/->' . $helper . '\s*\(/', $source, "lib/ calls $helper()");
                $this->assertFalse(method_exists(CheckoutTokenStore::class, $helper), "$helper() is no longer test-only");
            }
        }
    }

    /** @param class-string $double @param class-string $real */
    #[DataProvider('pairs')]
    public function testTheDoubleReplacesEveryPublicMethodOfTheRealClass(string $double, string $real): void {
        $missing = array_values(array_diff(self::methodsOf($real, true), self::methodsOf($double, true)));

        $this->assertSame([], $missing, "$double inherits the SQL of $real for these methods, which it has no database for");
    }

    /** @param class-string $double @param class-string $real */
    #[DataProvider('pairs')]
    public function testTheDoubleKeepsTheSignaturesOfTheRealClass(string $double, string $real): void {
        foreach (self::methodsOf($real, true) as $name) {
            $a = new \ReflectionMethod($real, $name);
            $b = new \ReflectionMethod($double, $name);
            $this->assertSame((string)$a->getReturnType(), (string)$b->getReturnType(), "$name return type");
            $this->assertSame(
                array_map(static fn (\ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName(), $a->getParameters()),
                array_map(static fn (\ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName(), $b->getParameters()),
                "$name parameters",
            );
        }
    }

    /** @return array<string, mixed> */
    private static function codeRow(string $hash, string $uid): array {
        return ['code_hash' => $hash, 'user_id' => $uid, 'client_id' => 'client-a', 'redirect_uri' => 'https://claude.ai/cb',
            'code_challenge' => 'ch', 'resource' => 'r', 'scope' => 'mcp', 'expires_at' => 1000];
    }

    /** @return array<string, mixed> */
    private static function grantRow(string $uid, string $client, string $access, string $refresh, int $created): array {
        return ['user_id' => $uid, 'client_id' => $client, 'resource' => 'r', 'scope' => 'mcp', 'access_hash' => $access,
            'access_expires' => $created + 3600, 'refresh_hash' => $refresh, 'refresh_expires' => 5000, 'created_at' => $created];
    }

    /** One run of the OAuth store through its public surface, as a log that has no ids in it. */
    private static function oauthScenario(OAuthStore $store): array {
        $log = [];
        $store->insertCode(self::codeRow('h1', 'alice'), 100);
        $store->insertCode(self::codeRow('h2', 'bob'), 100);
        $log['code once'] = $store->consumeCode('h1')['user_id'] ?? null;
        $log['code twice'] = $store->consumeCode('h1');
        $log['code unknown'] = $store->consumeCode('zz');

        $store->insertToken(self::grantRow('alice', 'client-a', 'a1', 'r1', 10), 100);
        $store->insertToken(self::grantRow('bob', 'client-a', 'a2', 'r2', 20), 100);
        $store->insertToken(self::grantRow('alice', 'client-b', 'a3', 'r3', 30), 100);
        $log['by access'] = $store->findByAccess('a1')['user_id'] ?? null;
        $log['by refresh'] = $store->findByRefresh('r3')['client_id'] ?? null;
        $log['refresh is not an access hash'] = $store->findByAccess('r1');
        $first = (int)$store->findByRefresh('r1')['id'];
        $second = (int)$store->findByRefresh('r2')['id'];

        $log['rotate'] = $store->rotate($first, 'r1', 'a1n', 4000, 'r1n', 6000);
        $log['replay of the old refresh'] = $store->rotate($first, 'r1', 'a1x', 4000, 'r1x', 6000);
        $log['rotate with the wrong grant id'] = $store->rotate($second, 'r1n', 'a', 1, 'r', 2);
        $log['new refresh found'] = $store->findByRefresh('r1n')['user_id'] ?? null;
        $log['old refresh gone'] = $store->findByRefresh('r1');
        $store->revokeReusedRefresh('r1', 'client-b', 100);
        $log['replay by another client revokes nothing'] = $store->countGrants(100);
        $store->revokeReusedRefresh('r1', 'client-a', 100);
        $log['replay by the owner revokes the pair'] = array_map(
            static fn (array $g): array => [$g['user_id'], $g['client_id']], $store->listGrants(null, '', 50, 0, 100));

        $log['delete grant of another user'] = $store->deleteGrant($second, 'alice');
        $log['delete grant of the owner'] = $store->deleteGrant($second, 'bob');
        $log['delete it again'] = $store->deleteGrant($second, null);
        $store->insertToken(self::grantRow('carol', 'client-c', 'a4', 'r4', 40), 100);
        $store->insertToken(self::grantRow('carol', 'client-a', 'a5', 'r5', 50), 100);
        $store->deleteForClient('client-c');
        $log['after deleteForClient'] = array_map(static fn (array $g): array => [$g['user_id'], $g['client_id']], $store->listGrants(null, '', 50, 0, 100));
        $store->deleteForUser('carol');
        $log['after deleteForUser'] = array_map(static fn (array $g): array => [$g['user_id'], $g['client_id']], $store->listGrants(null, '', 50, 0, 100));
        $log['search'] = array_map(static fn (array $g): string => $g['user_id'], $store->listGrants(null, 'ALI', 50, 0, 100));
        $log['expired are not live'] = $store->countGrants(5001);
        $store->deleteAll();
        $log['after deleteAll'] = [$store->countGrants(0), $store->consumeCode('h2')];
        return $log;
    }

    public function testTheOAuthDoubleAnswersLikeTheRealStore(): void {
        $db = new FakeDatabase($this, ['mcp_oauth_codes' => [], 'mcp_oauth_tokens' => [], 'mcp_oauth_spent' => []],
            ['mcp_oauth_codes', 'mcp_oauth_tokens']);

        $this->assertEquals(self::oauthScenario(new OAuthStore($db->connection())), self::oauthScenario(new InMemoryOAuthStore()));
    }

    /** One run of the checkout store, as a log that has no ids in it. */
    private static function checkoutScenario(CheckoutTokenStore $store): array {
        $row = static fn (string $hash, string $uid, int $expires): array => ['token_hash' => $hash, 'kind' => CheckoutToken::KIND_UPLOAD,
            'user_id' => $uid, 'file_id' => 7, 'path' => '/a.md', 'etag' => 'e', 'scope' => 'personal', 'shared_confirmed' => 0,
            'created_at' => 100, 'expires_at' => $expires];
        $log = [];
        $store->insert($row('h1', 'alice', 300), 100);
        $store->insert($row('h2', 'bob', 300), 100);
        $store->insert($row('h3', 'alice', 150), 100);
        $log['unknown'] = $store->find('zz');
        $log['found'] = [$store->find('h1')->userId, $store->find('h1')->used];
        $one = $store->find('h1');
        $log['spend'] = $store->consume($one, 200);
        $log['spend again'] = $store->consume($one, 201);
        $log['used after'] = $store->find('h1')->used;
        $log['expired exactly now'] = $store->consume($store->find('h3'), 150);
        $log['expired before now'] = $store->consume($store->find('h3'), 151);
        $log['unspent one is untouched'] = $store->find('h2')->used;
        $store->insert($row('h4', 'dave', 900), 160);
        $log['purged on insert'] = $store->find('h3');
        $store->deleteForUser('alice');
        $log['alice gone'] = $store->find('h1');
        $log['bob stays'] = $store->find('h2')?->userId;
        return $log;
    }

    public function testTheCheckoutDoubleAnswersLikeTheRealStore(): void {
        $db = new FakeDatabase($this, ['mcp_checkout_tokens' => []], ['mcp_checkout_tokens'], ['mcp_checkout_tokens' => ['used_at' => null]]);

        $this->assertEquals(self::checkoutScenario(new CheckoutTokenStore($db->connection())), self::checkoutScenario(new InMemoryCheckoutTokenStore()));
    }

    /** One run of the batch store, as a log that has no ids in it: owner, undo once, the narrowing of a stopped undo, purge. */
    private static function batchScenario(BatchStore $store): array {
        $moves = [['from' => '/a.md', 'to' => '/B/a.md', 'toId' => 7], ['from' => '/c.md', 'to' => '/B/c.md', 'toId' => 9]];
        $batch = static fn (string $uid, int $at): Batch => new Batch(null, $uid, $at, $moves, ['/B'], null);
        $log = [];
        $alice = $store->insert($batch('alice', 1000));
        $bob = $store->insert($batch('bob', 1000));
        $log['found by the owner'] = $store->find($alice, 'alice')?->moves;
        $log['not by another'] = $store->find($alice, 'bob');
        $store->keepRemaining($alice, 'bob', []);
        $log['another cannot narrow it'] = $store->find($alice, 'alice')?->moves;
        $store->keepRemaining($alice, 'alice', [$moves[0]]);
        $log['narrowed'] = [$store->find($alice, 'alice')?->moves, $store->find($alice, 'alice')?->dirs];
        $log['undone by another'] = $store->markUndone($alice, 'bob', 2000);
        $log['undone'] = $store->markUndone($alice, 'alice', 2000);
        $log['undone twice'] = $store->markUndone($alice, 'alice', 2001);
        $store->keepRemaining($alice, 'alice', []);
        $log['an undone batch is not narrowed'] = [$store->find($alice, 'alice')?->moves, $store->find($alice, 'alice')?->undoneAt];
        $store->insert($batch('carol', 1000 + BatchStore::LIFETIME_SECONDS + 1));
        $log['purged past its lifetime'] = [$store->find($alice, 'alice'), $store->find($bob, 'bob')];
        $store->deleteForUser('carol');
        return $log;
    }

    /**
     * The batch double against the production store on SQLite: unlike the two above, its SQL runs against the table the
     * migration creates ({@see SqliteDatabase}), so a column the store names and the migration does not is caught too.
     */
    public function testTheBatchDoubleAnswersLikeTheRealStore(): void {
        $db = new SqliteDatabase($this, [new \OCA\Mcp\Migration\Version000800Date20261002000000()]);

        $this->assertEquals(self::batchScenario(new BatchStore($db->connection())),
            self::batchScenario(new InMemoryBatchStore($this->createMock(\OCP\IDBConnection::class))));
    }
}
