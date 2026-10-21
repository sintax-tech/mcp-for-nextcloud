<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\ResourceScope\ScopeInactive;
use OCA\Mcp\Access\ResourceScope\ScopeItemsInaccessible;
use OCA\Mcp\Access\ResourceScope\ScopeTooLarge;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** setResourceScope through the real writer on SQLite (contracts B.4, validation S8/S9, ticket F1). */
final class ResourceScopeWriterTest extends TestCase {
    private ScopeFixture $fx;

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
    }

    /** @return array<string, array{string}> */
    public static function modules(): array {
        return ['files' => ['files'], 'calendar' => ['calendar'], 'tasks' => ['tasks']];
    }

    /** B5 and B6 opened every module: each slice wired all the consumers of its modules. */
    public function testTheShippedBarrierOpensEveryModule(): void {
        $this->assertSame(['files' => true, 'calendar' => true, 'tasks' => true], ScopeActivation::MODULES);
        $this->assertSame(ResourceScopeMapper::MODULES, array_keys(ScopeActivation::MODULES));
        $this->assertSame(['files' => true, 'calendar' => true, 'tasks' => true], (new ScopeActivation())->modules());
        $this->assertFalse((new ScopeActivation())->isActive('notes'));
    }

    /** F1: with the barrier closed the direct writer call is refused before anything is read or written. */
    #[DataProvider('modules')]
    public function testAClosedBarrierRefusesWithoutWriteReceiptOrRevision(string $module): void {
        $resources = $this->createMock(NativeResourceEnumerator::class);
        $resources->expects($this->never())->method('inaccessible');
        $writer = $this->fx->commands(false, $resources);
        $before = $this->fx->snapshot();
        foreach ([[], ['1'], null] as $items) {
            try {
                $writer->setResourceScope($before['revision'], 'root', 'alice', $module, $items);
                $this->fail('Closed barrier accepted');
            } catch (ScopeInactive) {
            }
        }
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertNull($this->fx->scopes->forModule('alice', $module));
    }

    public function testASavedScopeCarriesTheCommittedRevisionAReceiptAndAnAudit(): void {
        $revision = $this->fx->revision();
        $result = $this->fx->commands()->setResourceScope($revision, 'root', 'alice', 'files', [$this->fx->projects]);

        $committed = (string)((int)$revision + 1);
        $this->assertSame($committed, $result['revision']);
        $this->assertTrue($result['changed']);
        $this->assertSame(['policyRev' => $committed, 'items' => [$this->fx->projects]], $this->fx->scopes->forModule('alice', 'files'));
        $events = $this->fx->eventRows();
        $this->assertSame([PolicyEventsMapper::KIND_RECEIPT, PolicyEventsMapper::KIND_AUDIT], array_column($events, 'kind'));
        $this->assertSame($result['receiptId'], $events[0]['id']);
        $this->assertSame([['uid' => 'alice', 'key' => 'scope_files', 'before' => null, 'after' => '["' . $this->fx->projects . '"]']], $events[0]['payload']['changes']);
        $this->assertSame(PolicyEventPayload::ACTION_SCOPE, $events[1]['payload']['action']);
        $this->assertSame($result['receiptId'], $events[1]['payload']['receiptId']);
    }

    public function testAStaleRevisionIsAConflictThatWritesNothing(): void {
        $writer = $this->fx->commands();
        $stale = $this->fx->revision();
        $writer->setResourceScope($stale, 'root', 'alice', 'calendar', ['11']);
        $before = $this->fx->snapshot();

        $this->expectException(PolicyConflict::class);
        try {
            $writer->setResourceScope($stale, 'root', 'alice', 'calendar', ['12']);
        } finally {
            $this->assertSame($before, $this->fx->snapshot());
            $this->assertSame(['11'], $this->fx->scopes->forModule('alice', 'calendar')['items']);
        }
    }

    /** @return array<string, array{string, \Closure(ScopeFixture): list<string>}> resources alice cannot reach natively */
    public static function inaccessible(): array {
        return [
            'unreadable folder' => ['files', static fn (ScopeFixture $fx): array => [$fx->projects, $fx->locked]],
            'a file, not a folder' => ['files', static fn (ScopeFixture $fx): array => [$fx->file]],
            'missing folder' => ['files', static fn (ScopeFixture $fx): array => ['987654']],
            'calendar of another user' => ['calendar', static fn (ScopeFixture $fx): array => ['14']],
            'task list as calendar' => ['calendar', static fn (ScopeFixture $fx): array => ['13']],
            'event calendar as task list' => ['tasks', static fn (ScopeFixture $fx): array => ['11', '12']],
        ];
    }

    #[DataProvider('inaccessible')]
    public function testAResourceTheUserCannotReachIsRefusedWithoutWrite(string $module, \Closure $ids): void {
        $before = $this->fx->snapshot();
        try {
            $this->fx->commands()->setResourceScope($before['revision'], 'root', 'alice', $module, $ids($this->fx));
            $this->fail('Inaccessible resource accepted');
        } catch (ScopeItemsInaccessible $e) {
            $this->assertNotContains($this->fx->projects, $e->items);
            $this->assertNotSame([], $e->items);
        }
        $this->assertSame($before, $this->fx->snapshot());
    }

    public function testAnExcludedFolderMayBeChosenItIsNativelyReachable(): void {
        $result = $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->secret]);
        $this->assertTrue($result['changed']);
    }

    public function testTheLimitAppliesToTheDistinctSetAndRefusesTheWholeCommand(): void {
        $resources = $this->createMock(NativeResourceEnumerator::class);
        $resources->method('inaccessible')->willReturn([]);
        $writer = $this->fx->commands(true, $resources);
        $ids = array_map('strval', range(1, ResourceScopeMapper::MAX_ITEMS));
        $before = $this->fx->snapshot();
        try {
            $writer->setResourceScope($before['revision'], 'root', 'alice', 'files', [...$ids, '201']);
            $this->fail('201 distinct resources accepted');
        } catch (ScopeTooLarge) {
        }
        $this->assertSame($before, $this->fx->snapshot());

        // 200 distinct ids sent with duplicates are 200: accepted, stored once each.
        $writer->setResourceScope($before['revision'], 'root', 'alice', 'files', [...$ids, ...array_reverse($ids), '7']);
        $this->assertSame($ids, $this->fx->scopes->forModule('alice', 'files')['items']);
    }

    public function testDuplicatesAreDroppedAndOrderDoesNotMatter(): void {
        $writer = $this->fx->commands();
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'calendar', ['12', '11', '12']);
        $this->assertSame(['11', '12'], $this->fx->scopes->forModule('alice', 'calendar')['items']);

        $before = $this->fx->snapshot();
        $result = $writer->setResourceScope($before['revision'], 'root', 'alice', 'calendar', ['11', '12', '11']);
        $this->assertFalse($result['changed'], 'the stored scope again is a no-op');
        $this->assertNull($result['receiptId']);
        $this->assertSame($before, $this->fx->snapshot());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidItems(): array {
        return [
            'leading zero' => [['011']], 'zero' => [['0']], 'negative' => [['-1']], 'integer' => [[11]],
            'not a list' => [['a' => '11']], 'beyond bigint' => [['9223372036854775808']], 'text' => [['eleven']],
        ];
    }

    #[DataProvider('invalidItems')]
    public function testNonCanonicalIdsAreRefusedBeforeAnything(array $items): void {
        $before = $this->fx->snapshot();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->fx->commands()->setResourceScope($before['revision'], 'root', 'alice', 'calendar', $items);
        } finally {
            $this->assertSame($before, $this->fx->snapshot());
        }
    }

    public function testUnknownUserModuleOrActorIsRefused(): void {
        $revision = $this->fx->revision();
        foreach ([['root', 'nobody', 'files'], ['root', 'alice', 'notes'], ['', 'alice', 'files']] as [$actor, $uid, $module]) {
            try {
                $this->fx->commands()->setResourceScope($revision, $actor, $uid, $module, []);
                $this->fail("Accepted $actor/$uid/$module");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame($revision, $this->fx->revision());
    }

    public function testRestrictedToNothingIsDistinctFromUnrestricted(): void {
        $writer = $this->fx->commands();
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'tasks', []);
        $this->assertSame([], $this->fx->scopes->forModule('alice', 'tasks')['items']);
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'tasks', null);
        $this->assertNull($this->fx->scopes->forModule('alice', 'tasks'));
    }

    /** S8/F4: a resource the user reaches natively can be added to a scope that does not hold it yet. */
    public function testANativelyReachableResourceCanBeAddedToARestrictedOrEmptyScope(): void {
        $writer = $this->fx->commands();
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects, $this->fx->archive]);
        $this->assertSame([$this->fx->projects, $this->fx->archive], $this->fx->scopes->forModule('alice', 'files')['items']);

        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'calendar', []);
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'calendar', ['12']);
        $this->assertSame(['12'], $this->fx->scopes->forModule('alice', 'calendar')['items']);
    }

    public function testUndoRestoresTheAbsenceOfTheModeAndThenThePreviousItems(): void {
        $writer = $this->fx->commands();
        $first = $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
        $undo = $this->fx->services()->undo->restoreReceipt((string)$first['receiptId'], $first['revision'], 'root');
        $this->assertSame(1, $undo['changed']);
        $this->assertNull($this->fx->scopes->forModule('alice', 'files'), 'undo restores unrestricted, not an empty scope');

        $second = $writer->setResourceScope($undo['revision'], 'root', 'alice', 'files', [$this->fx->projects]);
        $third = $writer->setResourceScope($second['revision'], 'root', 'alice', 'files', [$this->fx->archive]);
        $restored = $this->fx->services()->undo->restoreReceipt((string)$third['receiptId'], $third['revision'], 'root');
        $this->assertSame(['policyRev' => $restored['revision'], 'items' => [$this->fx->projects]], $this->fx->scopes->forModule('alice', 'files'));
        $audit = $this->fx->eventRows();
        $last = end($audit);
        $this->assertSame(PolicyEventPayload::ACTION_UNDO, $last['payload']['action']);
        $this->assertSame([['uid' => 'alice', 'key' => 'scope_files', 'before' => '["' . $this->fx->archive . '"]', 'after' => '["' . $this->fx->projects . '"]']], $last['payload']['changes']);
    }

    public function testUndoByAnotherAdministratorOrAfterAnotherChangeTouchesNothing(): void {
        $writer = $this->fx->commands();
        $first = $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
        try {
            $this->fx->services()->undo->restoreReceipt((string)$first['receiptId'], $first['revision'], 'bob');
            $this->fail('Foreign receipt restored');
        } catch (\OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound) {
        }
        $writer->setResourceScope($first['revision'], 'root', 'alice', 'calendar', ['11']);
        $before = $this->fx->snapshot();
        try {
            $this->fx->services()->undo->restoreReceipt((string)$first['receiptId'], $first['revision'], 'root');
            $this->fail('Stale undo accepted');
        } catch (PolicyConflict) {
        }
        $this->assertSame($before, $this->fx->snapshot());
    }

    public function testUndoOfAScopeIsRefusedWhileItsBarrierIsClosed(): void {
        $first = $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
        $before = $this->fx->snapshot();

        $this->expectException(ScopeInactive::class);
        try {
            $this->fx->services(false)->undo->restoreReceipt((string)$first['receiptId'], $first['revision'], 'root');
        } finally {
            $this->assertSame($before, $this->fx->snapshot());
        }
    }

    /** S9: a scope change revokes no credential and moves no epoch; it applies from the next operation. */
    public function testAScopeChangeTouchesNoCredentialOrEpoch(): void {
        $this->fx->oauth->insertToken(['user_id' => 'alice', 'client_id' => 'c', 'access_hash' => 'a', 'refresh_hash' => 'r',
            'access_expires' => $this->fx->now + 3600, 'refresh_expires' => $this->fx->now + 7200, 'scope' => 'mcp'], $this->fx->now);
        $tokens = $this->fx->oauth->tokens;
        $epoch = $this->fx->state->state()['auth_epoch'];
        $writer = $this->fx->commands();

        $first = $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', []);
        $this->fx->services()->undo->restoreReceipt((string)$first['receiptId'], $first['revision'], 'root');

        $this->assertSame($tokens, $this->fx->oauth->tokens);
        $this->assertSame($epoch, $this->fx->state->state()['auth_epoch']);
    }
}
