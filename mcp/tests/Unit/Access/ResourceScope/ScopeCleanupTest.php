<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Access\ResourceScope\ScopeCleanup;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * S12 (contracts B.3, F3) on the real writer, state, events and scope tables in SQLite: a qualified removal on time
 * (policy_rev <= R_b) and a scope saved later kept; a job that never removes anything, never writes policy and only
 * reports how many scopes belong to absent accounts; and a recreated account that keeps its restriction. Every account
 * read runs outside any transaction, and what cannot be proven keeps the scope.
 */
final class ScopeCleanupTest extends TestCase {
    private \OCA\Mcp\Tests\Support\Access\Policy\ScopeStateFixture $fx;
    private PolicyTransaction $writer;
    private ScopeCleanup $cleanup;
    private InMemoryAppConfig $apps;
    /** @var list<string> accounts that exist */
    private array $existing = [];
    /** @var array<string, \Throwable> accounts whose read fails */
    private array $failing = [];
    /** @var bool whether the object lookup says missing while the backend's existence answer says present */
    private bool $backendSaysPresent = false;
    /** @var (\Closure(string): void)|null runs inside every account read */
    private ?\Closure $readHook = null;
    /** @var list<string> */
    private array $reads = [];
    /** @var list<array{string, string, array}> */
    private array $logged = [];

    protected function setUp(): void {
        $this->fx = new \OCA\Mcp\Tests\Support\Access\Policy\ScopeStateFixture($this);
        $this->writer = $this->fx->transaction();
        $this->apps = new InMemoryAppConfig();
        $this->cleanup = $this->cleanup();
    }

    private function cleanup(): ScopeCleanup {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) {
            $this->read($uid);
            return in_array($uid, $this->existing, true) ? $this->fx->user($uid) : null;
        });
        $users->method('userExists')->willReturnCallback(function (string $uid): bool {
            $this->read($uid);
            return in_array($uid, $this->existing, true) || $this->backendSaysPresent;
        });
        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<array{string, string, array}> $log */
            public function __construct(private array &$log) {
            }

            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->log[] = [(string)$level, (string)$message, $context];
            }
        };
        return new ScopeCleanup($this->writer, $this->fx->scopes, $users, $this->apps->worker($this), $logger);
    }

    private function read(string $uid): void {
        $this->reads[] = $uid;
        $this->assertFalse($this->fx->db->inTransaction(), 'the account is never read under the mutex or inside a transaction');
        if (isset($this->failing[$uid])) {
            throw $this->failing[$uid];
        }
        if ($this->readHook !== null) {
            ($this->readHook)($uid);
        }
    }

    /** One saved scope, as a committed save writes it: policy_rev is the revision the commit produces. */
    private function save(string $uid, string $module, ?array $ids): string {
        $revision = '';
        $this->writer->runInternal([], static fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($uid, $module, $ids, &$revision): bool {
            $revision = DecimalCounter::increment($attempt->state()['revision']);
            $this->fx->scopes->replace($uid, $module, $ids, $revision);
            return true;
        });
        return $revision;
    }

    /** @return array<string, array{policyRev:string, items:list<string>}> */
    private function scopes(string $uid): array {
        return $this->fx->scopes->forUser($uid);
    }

    private function revision(): string {
        return $this->fx->revision();
    }

    /** @return list<string> messages logged at a level */
    private function logs(string $level): array {
        return array_values(array_map(static fn (array $entry): string => $entry[1], array_filter($this->logged, static fn (array $entry): bool => $entry[0] === $level)));
    }

    // --- on time: UserDeletedEvent with R_b -----------------------------------------------------------------------

    public function testTheScopesSavedUpToRbAreRemovedWithTheirItemsAndTheRevisionAdvances(): void {
        $this->save('alice', 'files', ['10', '11']);
        $this->save('alice', 'calendar', []);
        $this->save('bob', 'files', ['20']);
        $rb = $this->revision();
        $before = $this->revision();

        $this->assertSame(['calendar', 'files'], $this->cleanup->deleted('alice', $rb));

        $this->assertSame([], $this->scopes('alice'));
        $this->assertSame(['20'], $this->scopes('bob')['files']['items'], 'another account is untouched');
        $this->assertSame(0, (int)$this->fx->database->dbal->fetchOne("SELECT COUNT(*) FROM mcp_scope_items WHERE resource_id IN ('10', '11')"));
        $this->assertNotSame($before, $this->revision(), 'removing a scope is a policy change and advances the revision');
        $this->assertContains('MCP resource scope removed with its deleted account', $this->logs('info'));
    }

    public function testAScopeSavedAtExactlyRbIsRemovedAndOneSavedAfterItIsNever(): void {
        $this->save('alice', 'files', ['10']);
        $rb = $this->revision(); // policy_rev of the files scope equals R_b
        $this->assertSame($rb, $this->scopes('alice')['files']['policyRev']);
        // The account was recreated and configured again after the deletion started.
        $later = $this->save('alice', 'calendar', ['30']);
        $this->assertSame(1, DecimalCounter::compare($later, $rb));

        $this->assertSame(['files'], $this->cleanup->deleted('alice', $rb));

        $this->assertSame(['calendar'], array_keys($this->scopes('alice')));
        $this->assertSame(['30'], $this->scopes('alice')['calendar']['items']);
        $this->assertSame($later, $this->scopes('alice')['calendar']['policyRev']);
    }

    public function testAScopeReplacedAfterRbIsNotRemovedEvenIfAnOlderOneExistedForTheModule(): void {
        $this->save('alice', 'files', ['10']);
        $rb = $this->revision();
        $this->save('alice', 'files', ['10', '99']); // the same module saved again later: a new policy_rev

        $this->assertSame([], $this->cleanup->deleted('alice', $rb));
        $this->assertSame(['10', '99'], $this->scopes('alice')['files']['items']);
    }

    public function testNothingToRemoveCommitsNothing(): void {
        $this->save('bob', 'files', ['20']);
        $before = $this->fx->snapshot();
        $this->assertSame([], $this->cleanup->deleted('alice', $this->revision()));
        $this->assertSame($before, $this->fx->snapshot(), 'no revision, no event');
    }

    public function testRevisionsAreComparedAsDecimalsAndInvalidInputIsRefused(): void {
        $this->save('alice', 'files', ['10']);
        foreach (['', '01', '-1', '1.5', 'abc', '99999999999999999999999'] as $bad) {
            try {
                $this->cleanup->deleted('alice', $bad);
                $this->fail("accepted R_b '$bad'");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->cleanup->deleted("bad\0uid", '1');
    }

    public function testAnInvalidStoredRowKeepsEveryScopeOfTheUserAndFailsClosed(): void {
        $this->save('alice', 'files', ['10']);
        $this->save('alice', 'calendar', ['30']);
        // An item whose digest does not match its id: the removal verifies every row of the user before deleting any.
        $this->fx->database->dbal->insert('mcp_scope_items', ['user_key' => \OCA\Mcp\Db\IdentityKey::user('alice'), 'module' => 'tasks',
            'resource_key' => str_repeat('0', 64), 'resource_id' => '5']);
        $before = $this->fx->snapshot();

        try {
            $this->cleanup->deleted('alice', $this->revision());
            $this->fail('removed despite an invalid row');
        } catch (PolicyUnavailable) {
        }
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertSame(['calendar', 'files'], array_keys($this->fx->database->dbal->fetchAllKeyValue('SELECT module, user_id FROM mcp_scope_modes ORDER BY module')));
    }

    // --- job: diagnostic only -----------------------------------------------------------------------------------------

    /** The job's service with a writer that must never be touched: it takes no mutex and writes no policy. */
    private function diagnosticJob(): ScopeCleanup {
        $writer = $this->createMock(PolicyTransaction::class);
        $writer->expects($this->never())->method($this->anything());
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) {
            $this->read($uid);
            return in_array($uid, $this->existing, true) ? $this->fx->user($uid) : null;
        });
        $users->method('userExists')->willReturnCallback(function (string $uid): bool {
            $this->read($uid);
            return in_array($uid, $this->existing, true) || $this->backendSaysPresent;
        });
        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<array{string, string, array}> $log */
            public function __construct(private array &$log) {
            }

            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->log[] = [(string)$level, (string)$message, $context];
            }
        };
        return new ScopeCleanup($writer, $this->fx->scopes, $users, $this->apps->worker($this), $logger);
    }

    private function key(string $uid): string {
        return \OCA\Mcp\Db\IdentityKey::user($uid);
    }

    /** @return list<array<string, mixed>> the context of every diagnostic line */
    private function diagnostics(): array {
        return array_values(array_map(static fn (array $entry): array => $entry[2],
            array_filter($this->logged, static fn (array $entry): bool => $entry[1] === 'MCP resource scope diagnostic pass')));
    }

    /** @return list<string> the app config keys the job stored */
    private function storedKeys(): array {
        return array_keys($this->apps->stored['mcp'] ?? []);
    }

    public function testTheJobNeverRemovesAScopeHoweverOftenItSeesTheAccountAbsent(): void {
        $this->save('alice', 'files', ['10']);
        $this->save('alice', 'tasks', ['40']);
        $job = $this->diagnosticJob();
        $before = $this->fx->snapshot();

        for ($pass = 1; $pass <= 10; ++$pass) {
            $this->assertSame(1, $job->reconcile(), "pass $pass reports the absent account and nothing else");
        }

        $this->assertSame($before, $this->fx->snapshot(), 'no command, no revision, no event, no row changed');
        $this->assertSame(['10'], $this->scopes('alice')['files']['items']);
        $this->assertSame(['40'], $this->scopes('alice')['tasks']['items']);
        $this->assertSame([ScopeCleanup::CURSOR_KEY], $this->storedKeys(), 'the cursor is the only thing the job stores');
    }

    public function testTheJobDoesNotConsultTheClockSoNoElapsedTimeCanBringARemovalOn(): void {
        $constructor = (new \ReflectionClass(ScopeCleanup::class))->getConstructor();
        foreach ($constructor->getParameters() as $parameter) {
            $this->assertStringNotContainsString('Time', (string)$parameter->getType(), 'no clock is injected into the cleanup');
        }
        $source = (string)file_get_contents((string)(new \ReflectionClass(ScopeCleanup::class))->getFileName());
        foreach (['getTime', 'ITimeFactory', 'scope_absent', 'ABSENCE', 'runInternal'] as $needle) {
            $count = substr_count($source, $needle);
            // runInternal is the event path's single command; nothing else may appear.
            $this->assertSame($needle === 'runInternal' ? 1 : 0, $count, $needle);
        }
    }

    public function testTheJobKeepsTheScopeOfAnAccountThatExistsAndCountsIt(): void {
        $this->existing = ['alice'];
        $this->save('alice', 'files', ['10']);
        $before = $this->fx->snapshot();

        $this->assertSame(0, $this->diagnosticJob()->reconcile());

        $this->assertSame($before, $this->fx->snapshot());
        $this->assertSame(['10'], $this->scopes('alice')['files']['items']);
        $this->assertSame(['alice'], $this->reads, 'one fresh read of the account');
        $this->assertSame(1, $this->diagnostics()[0]['kept_account_exists']);
        $this->assertSame(0, $this->diagnostics()[0]['absent']);
    }

    public function testAnAccountRecreatedBeforeTheJobKeepsItsInheritedRestriction(): void {
        $this->save('alice', 'files', ['10', '11']);
        $job = $this->diagnosticJob();
        $this->assertSame(1, $job->reconcile(), 'absent: reported');
        $this->existing = ['alice']; // the same id exists again
        $this->assertSame(0, $job->reconcile());

        $this->assertSame(['10', '11'], $this->scopes('alice')['files']['items'], 'still restricted to the old items');
        $this->assertNotNull($this->fx->scopes->forModule('alice', 'files'));
    }

    public function testTheDiagnosticCountsEachKindOfUserOnce(): void {
        foreach (['alice', 'bob', 'carol', 'dave'] as $uid) {
            $this->save($uid, 'files', ['10']);
        }
        $this->save('alice', 'calendar', ['11']); // two modules of one user are one user
        $this->existing = ['bob'];
        $this->failing['dave'] = new \RuntimeException('directory down');
        // carol's mode claims another owner: her id fails its digest and no account is read for it.
        $this->fx->database->dbal->executeStatement('UPDATE mcp_scope_modes SET user_id = ? WHERE user_key = ?', ['mallory', $this->key('carol')]);
        $this->fx->database->dbal->insert('mcp_scope_items', ['user_key' => $this->key('erin'), 'module' => 'files',
            'resource_key' => \OCA\Mcp\Db\IdentityKey::resource('files', '7'), 'resource_id' => '7']);
        $before = $this->fx->snapshot();

        $this->assertSame(1, $this->diagnosticJob()->reconcile(), 'only alice is absent');

        $this->assertSame([['app' => 'mcp', 'users' => 4, 'kept_account_exists' => 1, 'absent' => 1, 'blocked_or_failed' => 2, 'orphan_item_users' => 1]], $this->diagnostics());
        $this->assertNotContains('mallory', $this->reads, 'no directory read for an owner that fails its digest');
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertNotEmpty($this->logs('warning'));
    }

    public function testAnAccountTheDirectoryStillAnswersForIsNeverCountedAsAbsent(): void {
        $this->save('alice', 'files', ['10']);
        $this->backendSaysPresent = true; // the object lookup says missing, the backend's own existence answer says present

        $this->assertSame(0, $this->diagnosticJob()->reconcile());

        $this->assertSame(1, $this->diagnostics()[0]['kept_account_exists']);
        $this->assertSame(0, $this->diagnostics()[0]['absent']);
    }

    public function testNothingIsLoggedWhenNoUserHasAScope(): void {
        $this->assertSame(0, $this->diagnosticJob()->reconcile());
        $this->assertSame([], $this->diagnostics());
    }

    public function testTheJobBlocksOnNothingAnInvalidRowOfOneUserDoesNotStopTheOthers(): void {
        $this->save('alice', 'files', ['10']);
        $this->save('bob', 'files', ['20']);
        $this->fx->database->dbal->insert('mcp_scope_items', ['user_key' => $this->key('alice'), 'module' => 'tasks',
            'resource_key' => str_repeat('0', 64), 'resource_id' => '5']);
        $this->existing = ['bob'];

        $this->assertSame(1, $this->diagnosticJob()->reconcile());

        $this->assertSame(['10'], $this->scopes_unverified('alice'), 'nothing was removed');
        $this->assertSame(['20'], $this->scopes('bob')['files']['items']);
    }

    public function testThePageCursorMovesPastUsersThatKeepFailingAndWraps(): void {
        foreach (['alice', 'bob', 'carol'] as $uid) {
            $this->save($uid, 'files', ['10']);
        }
        $this->existing = ['bob'];
        $this->failing['carol'] = new \RuntimeException('directory down');
        $job = $this->diagnosticJob();

        for ($run = 0; $run < 7; ++$run) {
            $job->reconcile(1);
        }

        $this->assertEqualsCanonicalizing(['alice', 'bob', 'carol'], array_unique($this->reads), 'every user got its turn, the failing one included');
        $this->assertCount(3, $this->scopes_all(), 'and every scope is still there');
    }

    public function testAFailingListingIsContainedAndReportsNothing(): void {
        $scopes = $this->createMock(ResourceScopeMapper::class);
        $scopes->method('usersWithModes')->willThrowException(new \RuntimeException('query failed'));
        $writer = $this->createMock(PolicyTransaction::class);
        $writer->expects($this->never())->method($this->anything());
        $job = new ScopeCleanup($writer, $scopes, $this->createMock(IUserManager::class), $this->apps->worker($this), new \Psr\Log\NullLogger());
        $this->assertSame(0, $job->reconcile());
    }

    public function testNoDirectoryReadHappensInsideAnyTransaction(): void {
        $this->save('alice', 'files', ['10']);
        $this->save('bob', 'files', ['20']);
        $this->existing = ['bob'];
        $this->diagnosticJob()->reconcile();
        // read() asserts, on every account read, that no transaction is open.
        $this->assertNotEmpty($this->reads);
    }

    public function testOrphanItemsAreCountedInTheDiagnosticAndLeftInPlace(): void {
        $this->existing = ['alice'];
        $this->save('alice', 'files', ['10']);
        $this->fx->database->dbal->insert('mcp_scope_items', ['user_key' => \OCA\Mcp\Db\IdentityKey::user('carol'), 'module' => 'files',
            'resource_key' => \OCA\Mcp\Db\IdentityKey::resource('files', '7'), 'resource_id' => '7']);

        $this->diagnosticJob()->reconcile();

        $this->assertSame(1, $this->diagnostics()[0]['orphan_item_users']);
        $this->assertSame(1, (int)$this->fx->database->dbal->fetchOne("SELECT COUNT(*) FROM mcp_scope_items WHERE resource_id = '7'"));
    }

    // --- the contract's negative promises --------------------------------------------------------------------------

    public function testThereIsNoUnqualifiedDeleteByUserAndNoAuthGenerationInTheScopeTables(): void {
        foreach ((new \ReflectionClass(ResourceScopeMapper::class))->getMethods() as $method) {
            $this->assertDoesNotMatchRegularExpression('/^delete(ForUser|User|All)/i', $method->getName(), 'no unqualified delete in the scope mapper');
        }
        foreach ([ResourceScopeMapper::class, ScopeCleanup::class] as $class) {
            $source = (string)file_get_contents((string)(new \ReflectionClass($class))->getFileName());
            $this->assertStringNotContainsString('auth_gen', $source);
            $this->assertStringNotContainsString('deleteForUser', $source);
            $this->assertStringNotContainsString('scope_absent_since', $source, 'no code reads the notes of the dropped 24-hour rule');
        }
    }

    /** @return list<string> user ids that still have a mode row */
    private function scopes_all(): array {
        return array_map('strval', $this->fx->database->dbal->fetchFirstColumn('SELECT DISTINCT user_id FROM mcp_scope_modes ORDER BY user_id'));
    }

    /** @return list<string> the items of the user's files scope, read without the verification that would refuse the user */
    private function scopes_unverified(string $uid): array {
        $rows = $this->fx->database->dbal->fetchFirstColumn("SELECT i.resource_id FROM mcp_scope_modes m JOIN mcp_scope_items i ON i.user_key = m.user_key AND i.module = m.module WHERE m.user_key = ? AND m.module = 'files'",
            [\OCA\Mcp\Db\IdentityKey::user($uid)]);
        return array_map('strval', $rows);
    }
}
