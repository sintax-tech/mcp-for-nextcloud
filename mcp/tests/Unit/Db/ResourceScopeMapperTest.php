<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;

use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Migration\Version001100Date20261015020000;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The resource scope mapper on SQLite: presence semantics, identity checks before every write and policy_rev. */
final class ResourceScopeMapperTest extends TestCase {
    private SqliteDatabase $database;
    private IDBConnection $db;
    private ResourceScopeMapper $scopes;

    protected function setUp(): void {
        $this->database = new SqliteDatabase($this, [new Version001100Date20261015020000()]);
        $this->db = $this->database->connection();
        $this->scopes = new ResourceScopeMapper($this->db);
    }

    /** @template T @param \Closure(): T $work @return T */
    private function inTransaction(\Closure $work): mixed {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> every row of a table, ordered */
    private function rows(string $table): array {
        return $this->database->dbal->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY 1, 2, 3');
    }

    private function rowCount(string $table): int {
        return (int)$this->database->dbal->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    public function testNoRowMeansUnrestrictedAndReadsNeverMaterializeOne(): void {
        $this->assertSame([], $this->scopes->forUser('alice'));
        foreach (ResourceScopeMapper::MODULES as $module) {
            $this->assertNull($this->scopes->forModule('alice', $module));
        }
        $this->assertSame(0, $this->rowCount(ResourceScopeMapper::MODES_TABLE));
        $this->assertSame(0, $this->rowCount(ResourceScopeMapper::ITEMS_TABLE));
    }

    public function testRestrictedToNothingIsDistinctFromUnrestricted(): void {
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', [], '7'));

        $this->assertSame(['policyRev' => '7', 'items' => []], $this->scopes->forModule('alice', 'files'));
        $this->assertSame(['files' => ['policyRev' => '7', 'items' => []]], $this->scopes->forUser('alice'));
        $this->assertSame(1, $this->rowCount(ResourceScopeMapper::MODES_TABLE));

        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', null, '8'));
        $this->assertNull($this->scopes->forModule('alice', 'files'));
        $this->assertSame(0, $this->rowCount(ResourceScopeMapper::MODES_TABLE));
    }

    public function testReplaceStoresDigestOriginalIdAndPolicyRevisionPerModule(): void {
        $this->inTransaction(function (): void {
            $this->scopes->replace('alice', 'files', ['120', '9', '45'], '3');
            $this->scopes->replace('alice', 'calendar', ['9'], '3');
            $this->scopes->replace('alice', 'tasks', ['9'], '4');
        });

        $this->assertSame([
            'calendar' => ['policyRev' => '3', 'items' => ['9']],
            'files' => ['policyRev' => '3', 'items' => ['9', '45', '120']],
            'tasks' => ['policyRev' => '4', 'items' => ['9']],
        ], $this->scopes->forUser('alice'));
        $modes = array_column($this->rows(ResourceScopeMapper::MODES_TABLE), null, 'module');
        $this->assertSame(['user_key' => IdentityKey::user('alice'), 'module' => 'files', 'user_id' => 'alice', 'policy_rev' => '3'],
            array_map('strval', $modes['files']));
        $keys = array_column(array_filter($this->rows(ResourceScopeMapper::ITEMS_TABLE), static fn (array $row): bool => $row['resource_id'] === '9'), 'resource_key', 'module');
        // The same id in two modules is two resources: calendar and tasks scopes stay independent.
        $this->assertSame(hash('sha256', "calendar\0" . '9'), $keys['calendar']);
        $this->assertSame(hash('sha256', "files\0" . '9'), $keys['files']);
        $this->assertSame(hash('sha256', "tasks\0" . '9'), $keys['tasks']);
        $this->assertCount(3, array_unique($keys));

        // Replacing one module leaves the others untouched and records its own revision.
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', ['45'], '5'));
        $this->assertSame(['policyRev' => '5', 'items' => ['45']], $this->scopes->forModule('alice', 'files'));
        $this->assertSame(['policyRev' => '3', 'items' => ['9']], $this->scopes->forModule('alice', 'calendar'));
        $this->assertSame([], $this->scopes->forUser('Alice'), 'ids are never case-folded');
    }

    public function testTheLargestRevisionAndResourceIdRoundTrip(): void {
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', [DecimalCounter::MAX], DecimalCounter::MAX));

        $this->assertSame(['policyRev' => DecimalCounter::MAX, 'items' => [DecimalCounter::MAX]], $this->scopes->forModule('alice', 'files'));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidLists(): array {
        return [
            'duplicate' => [['5', '5']],
            'leading zero' => [['05']],
            'zero' => [['0']],
            'negative' => [['-1']],
            'integer' => [[5]],
            'space' => [[' 5']],
            'overflow' => [['9223372036854775808']],
            'name instead of id' => [['/Projects']],
            'not a list' => [['a' => '5']],
            'above the limit' => [array_map('strval', range(1, ResourceScopeMapper::MAX_ITEMS + 1))],
        ];
    }

    #[DataProvider('invalidLists')]
    public function testInvalidResourceListsAreRefusedWithoutWrite(array $items): void {
        try {
            $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', $items, '1'));
            $this->fail('Invalid list accepted');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, $this->rowCount(ResourceScopeMapper::MODES_TABLE));
        $this->assertSame(0, $this->rowCount(ResourceScopeMapper::ITEMS_TABLE));
    }

    public function testTheLimitItselfIsAccepted(): void {
        $ids = array_map('strval', range(1, ResourceScopeMapper::MAX_ITEMS));
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', $ids, '1'));

        $this->assertSame($ids, $this->scopes->forModule('alice', 'files')['items']);
    }

    public function testInvalidModuleRevisionOrUserAreRefused(): void {
        foreach ([
            fn () => $this->scopes->replace('alice', 'notes', [], '1'),
            fn () => $this->scopes->replace('alice', 'Files', [], '1'),
            fn () => $this->scopes->replace('alice', 'files', [], '01'),
            fn () => $this->scopes->replace('alice', 'files', [], '-1'),
            fn () => $this->scopes->replace('', 'files', [], '1'),
            fn () => $this->scopes->replace("al\0ice", 'files', [], '1'),
            fn () => $this->scopes->removeUpTo('alice', 'x'),
        ] as $write) {
            try {
                $this->inTransaction($write);
                $this->fail('Invalid write accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->scopes->forModule('alice', 'deck');
    }

    public function testWritesOutsideTheWriterTransactionAreRefused(): void {
        foreach ([
            fn () => $this->scopes->replace('alice', 'files', [], '1'),
            fn () => $this->scopes->replace('alice', 'files', null, '1'),
            fn () => $this->scopes->removeUpTo('alice', '1'),
        ] as $write) {
            try {
                $write();
                $this->fail('Write outside the transaction accepted');
            } catch (\LogicException) {
            }
        }
        $this->assertSame(0, $this->rowCount(ResourceScopeMapper::MODES_TABLE));
    }

    /** A row of "Alice" under alice's key: what a digest collision or a case-insensitive merge would look like. */
    private function insertCollidingMode(string $module, string $policyRev = '2'): void {
        $this->database->dbal->insert(ResourceScopeMapper::MODES_TABLE, [
            'user_key' => IdentityKey::user('alice'), 'module' => $module, 'user_id' => 'Alice', 'policy_rev' => $policyRev,
        ]);
    }

    public function testAModeOfAnotherIdentityFailsReadsAndEveryWriteBeforeAnyChange(): void {
        $this->insertCollidingMode('files');
        $this->database->dbal->insert(ResourceScopeMapper::ITEMS_TABLE, [
            'user_key' => IdentityKey::user('alice'), 'module' => 'files', 'resource_key' => IdentityKey::resource('files', '5'), 'resource_id' => '5',
        ]);
        $before = [$this->rows(ResourceScopeMapper::MODES_TABLE), $this->rows(ResourceScopeMapper::ITEMS_TABLE)];

        foreach ([
            fn () => $this->scopes->forUser('alice'),
            fn () => $this->scopes->forModule('alice', 'files'),
            fn () => $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', ['6'], '3')),
            fn () => $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', null, '3')),
            fn () => $this->inTransaction(fn () => $this->scopes->removeUpTo('alice', '9')),
        ] as $call) {
            try {
                $call();
                $this->fail('Colliding identity accepted');
            } catch (\UnexpectedValueException) {
            }
        }
        $this->assertSame($before, [$this->rows(ResourceScopeMapper::MODES_TABLE), $this->rows(ResourceScopeMapper::ITEMS_TABLE)]);
    }

    public function testAModeOfAnotherIdentityInOneModuleBlocksTheCleanupOfAllModules(): void {
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'calendar', ['1'], '1'));
        $this->insertCollidingMode('files');

        try {
            $this->inTransaction(fn () => $this->scopes->removeUpTo('alice', '9'));
            $this->fail('Colliding identity accepted');
        } catch (\UnexpectedValueException) {
        }
        $this->assertSame(2, $this->rowCount(ResourceScopeMapper::MODES_TABLE));
        $this->assertSame(1, $this->rowCount(ResourceScopeMapper::ITEMS_TABLE));
    }

    /** @return array<string, array{array<string, string>}> stored item rows that do not match their digest or shape */
    public static function invalidItems(): array {
        $key = static fn (string $module, string $id): string => IdentityKey::resource($module, $id);
        return [
            'key of another id' => [['resource_key' => $key('files', '6'), 'resource_id' => '5']],
            'key of another module' => [['resource_key' => $key('calendar', '5'), 'resource_id' => '5']],
            'non-canonical id' => [['resource_key' => $key('files', '05'), 'resource_id' => '05']],
            'unknown module' => [['module' => 'notes', 'resource_key' => $key('notes', '5'), 'resource_id' => '5']],
        ];
    }

    #[DataProvider('invalidItems')]
    public function testAnInconsistentItemFailsTheReadAndTheReplaceWithoutWrite(array $row): void {
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', ['1'], '1'));
        $this->database->dbal->insert(ResourceScopeMapper::ITEMS_TABLE, $row + ['user_key' => IdentityKey::user('alice'), 'module' => 'files']);
        $before = $this->rows(ResourceScopeMapper::ITEMS_TABLE);

        try {
            $this->scopes->forUser('alice');
            $this->fail('Inconsistent item accepted');
        } catch (\UnexpectedValueException) {
        }
        if (($row['module'] ?? 'files') === 'files') {
            try {
                $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', ['2'], '2'));
                $this->fail('Inconsistent item replaced');
            } catch (\UnexpectedValueException) {
            }
        }
        $this->assertSame($before, $this->rows(ResourceScopeMapper::ITEMS_TABLE));
    }

    public function testAnInvalidStoredModeOrRevisionFailsTheRead(): void {
        foreach ([['module' => 'deck', 'policy_rev' => '1'], ['module' => 'files', 'policy_rev' => '-3']] as $row) {
            $this->database->dbal->executeStatement('DELETE FROM ' . ResourceScopeMapper::MODES_TABLE);
            $this->database->dbal->insert(ResourceScopeMapper::MODES_TABLE, $row + ['user_key' => IdentityKey::user('alice'), 'user_id' => 'alice']);
            try {
                $this->scopes->forUser('alice');
                $this->fail('Invalid stored mode accepted');
            } catch (\UnexpectedValueException $e) {
                $this->assertSame('Invalid stored resource scope', $e->getMessage());
            }
        }
    }

    public function testMoreItemsThanTheLimitStoredFailsTheRead(): void {
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', array_map('strval', range(1, ResourceScopeMapper::MAX_ITEMS)), '1'));
        $this->database->dbal->insert(ResourceScopeMapper::ITEMS_TABLE, [
            'user_key' => IdentityKey::user('alice'), 'module' => 'files', 'resource_key' => IdentityKey::resource('files', '999'), 'resource_id' => '999',
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->scopes->forModule('alice', 'files');
    }

    public function testItemsWithoutAModeHaveNoEffectAndAReplaceClearsThem(): void {
        $this->database->dbal->insert(ResourceScopeMapper::ITEMS_TABLE, [
            'user_key' => IdentityKey::user('alice'), 'module' => 'files', 'resource_key' => IdentityKey::resource('files', '5'), 'resource_id' => '5',
        ]);

        $this->assertNull($this->scopes->forModule('alice', 'files'));
        $this->assertSame([], $this->scopes->forUser('alice'));
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', ['6'], '1'));
        $this->assertSame(['policyRev' => '1', 'items' => ['6']], $this->scopes->forModule('alice', 'files'));
    }

    public function testCleanupRemovesOnlyScopesSavedUpToTheRevisionReadBeforeTheDeletion(): void {
        $this->inTransaction(function (): void {
            $this->scopes->replace('alice', 'files', ['1', '2'], '4');
            $this->scopes->replace('alice', 'calendar', ['3'], '10');
            $this->scopes->replace('alice', 'tasks', [], '5');
            $this->scopes->replace('bob', 'files', ['1'], '2');
        });

        $this->assertSame(['files', 'tasks'], $this->inTransaction(fn () => $this->scopes->removeUpTo('alice', '9')));

        // The calendar scope was saved after the deletion began (an account recreated and configured again): kept.
        $this->assertSame(['calendar' => ['policyRev' => '10', 'items' => ['3']]], $this->scopes->forUser('alice'));
        $this->assertSame(['files' => ['policyRev' => '2', 'items' => ['1']]], $this->scopes->forUser('bob'));
        $this->assertSame([], $this->inTransaction(fn () => $this->scopes->removeUpTo('alice', '9')));
        $this->assertSame(['calendar'], $this->inTransaction(fn () => $this->scopes->removeUpTo('alice', '10')));
        $this->assertSame(['files'], array_column($this->rows(ResourceScopeMapper::MODES_TABLE), 'module'));
        $this->assertSame(1, $this->rowCount(ResourceScopeMapper::ITEMS_TABLE));
    }

    public function testCleanupComparesRevisionsAsDecimalsNotAsText(): void {
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', ['1'], '10'));

        $this->assertSame([], $this->inTransaction(fn () => $this->scopes->removeUpTo('alice', '9')));
        $this->assertSame(['files'], $this->inTransaction(fn () => $this->scopes->removeUpTo('alice', '10')));
    }

    public function testAWriteRolledBackLeavesNoRow(): void {
        try {
            $this->inTransaction(function (): void {
                $this->scopes->replace('alice', 'files', ['1'], '1');
                throw new \RuntimeException('injected');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $this->scopes->forUser('alice'));
        $this->assertSame(0, $this->rowCount(ResourceScopeMapper::ITEMS_TABLE));
    }

    public function testUsersWithModesAreListedOncePerUserByKeyInPagesAfterACursor(): void {
        $this->inTransaction(function (): void {
            foreach (['alice', 'bob', 'carol'] as $uid) {
                $this->scopes->replace($uid, 'files', ['1'], '1');
                $this->scopes->replace($uid, 'calendar', ['2'], '2');
            }
        });
        $keys = [];
        foreach (['alice', 'bob', 'carol'] as $uid) {
            $keys[IdentityKey::user($uid)] = $uid;
        }
        ksort($keys);
        $expected = [];
        foreach ($keys as $key => $uid) {
            $expected[] = ['userKey' => $key, 'userId' => $uid];
        }

        $this->assertSame($expected, $this->scopes->usersWithModes(10), 'two modules of one user give one entry');
        $first = $this->scopes->usersWithModes(1);
        $this->assertSame([$expected[0]], $first);
        $this->assertSame([$expected[1]], $this->scopes->usersWithModes(1, $first[0]['userKey']));
        $this->assertSame([], $this->scopes->usersWithModes(1, $expected[2]['userKey']));
    }

    public function testOnlyUsersWithoutAModeAreCountedAsOrphanItemUsers(): void {
        $this->inTransaction(fn () => $this->scopes->replace('alice', 'files', ['1'], '1'));
        $this->assertSame(0, $this->scopes->orphanItemUsers());
        // Items of a module the user has no mode for, whoever the user is: one user key counts once.
        foreach (['5', '6'] as $id) {
            $this->database->dbal->insert(ResourceScopeMapper::ITEMS_TABLE, [
                'user_key' => IdentityKey::user('carol'), 'module' => 'files', 'resource_key' => IdentityKey::resource('files', $id), 'resource_id' => $id,
            ]);
        }
        $this->database->dbal->insert(ResourceScopeMapper::ITEMS_TABLE, [
            'user_key' => IdentityKey::user('alice'), 'module' => 'calendar', 'resource_key' => IdentityKey::resource('calendar', '9'), 'resource_id' => '9',
        ]);
        $this->assertSame(2, $this->scopes->orphanItemUsers(), 'carol and the calendar items of alice, who has no calendar mode');
    }
}
