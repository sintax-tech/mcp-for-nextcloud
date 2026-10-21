<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\ResourceScope\ScopeCleanup;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\EventProbe;
use OCP\Files\IRootFolder;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\UserDeletedEvent;

/**
 * The account cleanup of the resource scope (contracts B.3, F3, validation S12) against the real server: the real
 * deletion events of a real account reach the app's listener, which removes the scope the account had when its deletion
 * began and nothing saved after it. The scope rows are written the way a save writes them (the writer's transaction, policy_rev = the
 * revision of the commit), without the native-resource validation of the admin route, which is not under test here.
 * The job is diagnostic only: with the real mapper, directory and app config it never removes a scope, it counts the
 * scopes of absent accounts and keeps those of accounts that exist.
 */
final class ScopeCleanupEventsTest extends IntegrationTestCase {
    /** @var list<string> accounts created by a test, deleted at the end */
    private array $created = [];
    /** @var list<array{string, callable}> listeners added by a test */
    private array $listeners = [];
    /** @var list<array{string, string}> uid and module of scope rows a test may leave behind */
    private array $touched = [];

    protected function tearDown(): void {
        foreach ($this->listeners as [$event, $listener]) {
            Server::get(IEventDispatcher::class)->removeListener($event, $listener);
        }
        foreach ($this->touched as [$uid, $module]) {
            $this->replace($uid, $module, null);
        }
        if ($this->created !== []) {
            // Destructive provisioning calls require the real admin password, not an app password.
            $password = getenv('NEXTCLOUD_ADMIN_PASSWORD');
            $this->assertIsString($password, 'the disposable server supplies its admin password');
            $this->assertNotSame('', $password);
            $headers = ['Authorization: ' . Credential::basic('admin', $password)->authorization(), 'OCS-APIRequest: true'];
            foreach ($this->created as $uid) {
                // A rolled-back deletion can leave this CLI's user cache saying absent. Use a fresh core request.
                $url = Http::BASE . '/ocs/v2.php/cloud/users/' . rawurlencode($uid) . '?format=json';
                $deleted = Http::request('DELETE', $url, $headers);
                $this->assertContains($deleted['status'], [200, 404], 'native OCS account cleanup: ' . $deleted['body']);
                $this->assertSame(404, Http::request('GET', $url, $headers)['status'], 'the fixture account is absent after OCS cleanup');
            }
        }
        Server::get(IAppConfig::class)->deleteKey('mcp', ScopeCleanup::CURSOR_KEY);
        parent::tearDown();
    }

    /** Saves (or, with null, clears) one scope exactly as the writer's setResourceScope() stores it. */
    private function replace(string $uid, string $module, ?array $items): string {
        $revision = '';
        Server::get(PolicyTransaction::class)->runInternal([], static fn () => null,
            function ($snapshot, PolicyAttempt $attempt) use ($uid, $module, $items, &$revision): bool {
            $current = $attempt->state();
                $revision = DecimalCounter::increment($current['revision']);
                Server::get(ResourceScopeMapper::class)->replace($uid, $module, $items, $revision);
                return true;
            });
        $this->touched[] = [$uid, $module];
        return $revision;
    }

    /** @return array<string, array{policyRev:string, items:list<string>}> */
    private function scopes(string $uid): array {
        return Server::get(ResourceScopeMapper::class)->forUser($uid);
    }

    private function account(): string {
        $uid = 'mcp-it-scope-' . bin2hex(random_bytes(4));
        $this->assertNotNull(Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(16))), 'the account was created');
        $this->created[] = $uid;
        return $uid;
    }

    /** Count from the beginning of the same diagnostic population, independently of a previous pass's cursor. */
    private function absentCount(): int {
        Server::get(IAppConfig::class)->deleteKey('mcp', ScopeCleanup::CURSOR_KEY);
        return Server::get(ScopeCleanup::class)->reconcile();
    }

    private function revision(): string {
        return Server::get(PolicyStateMapper::class)->state()['revision'];
    }

    public function testTheRealDeletionRemovesTheScopeTheAccountHadAndLeavesOtherAccountsAlone(): void {
        $uid = $this->account();
        $this->replace($uid, 'files', ['100', '101']);
        $this->replace($uid, 'calendar', []);
        $this->replace(Fixtures::ALICE, 'files', ['200']);
        $before = $this->revision();

        $this->assertTrue(Server::get(IUserManager::class)->get($uid)->delete());

        $this->assertSame([], $this->scopes($uid), 'the scope went with the account, on time');
        $this->assertSame(['200'], $this->scopes(Fixtures::ALICE)['files']['items']);
        $this->assertNotSame($before, $this->revision(), 'removing it advanced the revision');
        $this->created = array_values(array_diff($this->created, [$uid]));
    }

    public function testAScopeSavedForTheRecreatedAccountBeforeTheLateDeletionHandlerIsKept(): void {
        $uid = $this->account();
        $this->replace($uid, 'files', ['100']);
        $saved = '';
        // Runs before the app's listener (higher priority): the account is already deleted, a new incarnation is
        // configured, and only then does the app's UserDeletedEvent handler remove what R_b qualifies.
        $listener = function () use ($uid, &$saved): void {
            $this->assertNotNull(Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(16))), 'the same UID is recreated before the late handler');
            $saved = $this->replace($uid, 'calendar', ['300']);
        };
        Server::get(IEventDispatcher::class)->addListener(UserDeletedEvent::class, $listener, 1000);
        $this->listeners[] = [UserDeletedEvent::class, $listener];

        $this->assertTrue(Server::get(IUserManager::class)->get($uid)->delete());
        Server::get(IEventDispatcher::class)->removeListener(UserDeletedEvent::class, $listener);

        $this->assertNotSame('', $saved, 'the interleaved save ran');
        $remaining = $this->scopes($uid);
        $this->assertSame(['calendar'], array_keys($remaining), 'only the scope saved after R_b survives');
        $this->assertSame($saved, $remaining['calendar']['policyRev']);
        $this->assertNotNull(Server::get(IUserManager::class)->get($uid));
    }

    public function testTheJobKeepsAndCountsTheScopeOfAnAccountThatIsGoneAndKeepsThatOfOneThatExists(): void {
        $gone = 'mcp-it-scope-gone-' . bin2hex(random_bytes(4));
        $absentBefore = $this->absentCount();
        $this->replace($gone, 'tasks', ['400']);
        $this->replace(Fixtures::ALICE, 'files', ['200']);
        $before = $this->revision();

        $absent = 0;
        for ($pass = 0; $pass < 3; ++$pass) {
            $absent = $this->absentCount();
            $this->assertSame($absentBefore + 1, $absent, 'this orphan adds exactly one absent account');
        }

        $this->assertSame(['400'], $this->scopes($gone)['tasks']['items'], 'the scope of an absent account is kept: only the event removes it');
        $this->assertSame(['200'], $this->scopes(Fixtures::ALICE)['files']['items'], 'an account that exists keeps its scope');
        $this->assertSame($before, $this->revision(), 'and the job wrote no policy');
    }

    public function testARealDeletionRolledBackByTheCallerKeepsEveryScope(): void {
        $uid = $this->account();
        $user = Server::get(IUserManager::class)->get($uid);
        $this->replace($uid, 'files', []);
        $this->replace($uid, 'calendar', ['100']);
        $before = $this->scopes($uid);
        $probe = new EventProbe(Server::get(IEventDispatcher::class), $this->official());
        $probe->record(BeforeUserDeletedEvent::class);
        $probe->record(UserDeletedEvent::class);
        $db = $this->official();
        $db->beginTransaction();
        try {
            $this->assertTrue($user->delete());
            $this->assertSame($before, $this->scopes($uid), 'neither event removes scope inside the caller transaction');
            $db->executeQuery('SELECT 1')->closeCursor();
        } finally {
            $db->rollBack();
            $probe->detach();
        }
        $this->assertCount(2, $probe->seen(), 'core dispatched both deletion events');
        foreach ($probe->seen() as $event) {
            $this->assertTrue($event['inTransaction']);
        }
        // The CLI backend cached the deletion; a fresh native HTTP request observes the committed account.
        $account = Http::request('GET', Http::BASE . '/ocs/v2.php/cloud/users/' . rawurlencode($uid) . '?format=json',
            ['Authorization: ' . LoginFactory::basic('admin')->authorization(), 'OCS-APIRequest: true']);
        $this->assertSame(200, $account['status']);
        $this->assertSame($uid, json_decode($account['body'], true)['ocs']['data']['id'] ?? null, 'the native account deletion was rolled back');
        $this->assertSame($before, $this->scopes($uid), 'the caller rollback leaves every scope intact');
    }

    public function testAnActuallyDeletedAndRecreatedAccountKeepsItsRestrictionThroughTheJobAndMcp(): void {
        $uid = $this->account();
        $this->replace($uid, 'files', []);
        $before = $this->scopes($uid);
        $absentBefore = $this->absentCount();
        $probe = new EventProbe(Server::get(IEventDispatcher::class), $this->official());
        $probe->record(BeforeUserDeletedEvent::class);
        $probe->record(UserDeletedEvent::class);
        // Real events inside a caller transaction cannot authorize scope removal, even after commit.
        $db = $this->official();
        $db->beginTransaction();
        try {
            $this->assertTrue(Server::get(IUserManager::class)->get($uid)->delete());
            $db->commit();
        } finally {
            $probe->detach();
        }
        $this->assertCount(2, $probe->seen());
        $this->assertSame($before, $this->scopes($uid));
        $this->assertNull(Server::get(IUserManager::class)->get($uid), 'the account really is absent');
        $this->assertSame($absentBefore + 1, $this->absentCount(), 'this real deletion adds exactly one absent account');
        $this->assertSame($before, $this->scopes($uid), 'it never removes the orphan restriction');
        $this->assertNotNull(Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(16))), 'same UID is really recreated');
        $home = Server::get(IRootFolder::class)->getUserFolder($uid);
        $home->newFile('new-incarnation.txt', 'native access, still outside the retained empty scope');
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible($uid, true);
        $policy->setConnected($uid, true);
        Server::get(ScopeCleanup::class)->reconcile();
        $this->assertSame($before, $this->scopes($uid));
        $client = new McpClient(LoginFactory::basic($uid));
        $listed = $client->tool('files_list', ['path' => '/']);
        $this->assertTrue($listed->ok(), $listed->describe());
        $this->assertSame([], $listed->json(), 'the recreated account is still restricted over MCP');
        $refused = $client->tool('files_read', ['path' => '/new-incarnation.txt']);
        $this->assertTrue($refused->isError(), $refused->describe());
        $this->assertSame($client->tool('files_read', ['path' => '/missing.txt'])->fingerprint(), $refused->fingerprint());
    }
}
