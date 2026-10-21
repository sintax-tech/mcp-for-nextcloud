<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;

use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Grants\GrantMatrix;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The edges of the scope administration: the matrix shows only {mode, count}, a scope read is one statement, the
 * receipt form is canonical, and no tool reaches the native enumerator.
 */
final class ScopeBoundariesTest extends TestCase {
    private ScopeFixture $fx;

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
    }

    private function matrix(ResourceScopeMapper $scopes): GrantMatrix {
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('forUsers')->willReturnCallback(static fn (array $uids): array => array_fill_keys($uids, []));
        return new GrantMatrix($policy, $this->createMock(IUserManager::class), $this->createMock(IGroupManager::class),
            $this->createMock(IAppManager::class), $this->createMock(LogsAccess::class), $scopes);
    }

    public function testTheMatrixShowsModeAndCountWithoutIdsOrNames(): void {
        $writer = $this->fx->commands();
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects, $this->fx->archive]);
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'tasks', []);

        $rows = $this->matrix($this->fx->scopes)->rows([$this->fx->user('alice'), $this->fx->user('bob')]);

        $this->assertSame([
            'files' => ['mode' => 'restricted', 'count' => 2],
            'calendar' => ['mode' => 'unrestricted', 'count' => null],
            'tasks' => ['mode' => 'restricted', 'count' => 0],
        ], $rows[0]['scope']);
        $this->assertSame(['unrestricted'], array_values(array_unique(array_column($rows[1]['scope'], 'mode'))));
        $this->assertStringNotContainsString($this->fx->projects, (string)json_encode($rows));
    }

    /** B7 review A1: an unreadable scope is "unavailable" in every cell, never unrestricted, never blank; grants stay. */
    public function testAnUnreadableScopeIsUnavailableInTheMatrixNeverUnrestricted(): void {
        $this->fx->database->dbal->insert(ResourceScopeMapper::MODES_TABLE, ['user_key' => \OCA\Mcp\Db\IdentityKey::user('alice'), 'module' => 'files', 'user_id' => 'mallory', 'policy_rev' => '1']);
        $rows = $this->matrix($this->fx->scopes)->rows([$this->fx->user('alice'), $this->fx->user('bob')]);
        // The page is still drawn, so the grants stay editable; only the scope cells say they cannot be read.
        $this->assertSame(['alice', 'bob'], array_column($rows, 'uid'));
        foreach ($rows as $row) {
            $this->assertSame(array_fill_keys(ResourceScopeMapper::MODULES, ['mode' => 'unavailable', 'count' => null]), $row['scope'], $row['uid']);
        }
    }

    /** Review F1 of B1: a mode and its items come from one statement, never from two reads of different commits. */
    public function testAScopeReadIsOneStatement(): void {
        $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
        $statements = 0;
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () use (&$statements) {
            ++$statements;
            return $this->fx->db->getQueryBuilder();
        });
        $scopes = new ResourceScopeMapper($db);

        $this->assertSame([$this->fx->projects], $scopes->forModule('alice', 'files')['items']);
        $this->assertSame(1, $statements);
        $this->assertSame(['files'], array_keys($scopes->forUser('alice')));
        $this->assertSame(2, $statements);
        $this->assertSame(['alice' => ['files' => 1], 'bob' => []], $scopes->counts(['alice', 'bob']));
        $this->assertSame(3, $statements);
    }

    /** @return array<string, array{?string, bool}> raw scope values of a receipt and whether they are canonical */
    public static function scopeValues(): array {
        return [
            'unrestricted' => [null, true], 'restricted to nothing' => ['[]', true], 'sorted' => ['["2","10"]', true],
            'unsorted' => ['["10","2"]', false], 'duplicate' => ['["2","2"]', false], 'integer' => ['[2]', false],
            'leading zero' => ['["02"]', false], 'zero' => ['["0"]', false], 'object' => ['{"a":"1"}', false],
            'spaced' => ['[ "2" ]', false], 'not json' => ['2', false],
        ];
    }

    #[DataProvider('scopeValues')]
    public function testOnlyTheCanonicalScopeFormIsAReceiptValue(?string $value, bool $valid): void {
        $this->assertSame($valid, PolicyEventPayload::scopeItems($value) !== false);
        $change = ['uid' => 'alice', 'key' => 'scope_files', 'before' => $value === '["5"]' ? null : '["5"]', 'after' => $value];
        try {
            PolicyEventPayload::receipt('root', PolicyEventPayload::ACTION_SCOPE, '1', '2', [$change]);
            $this->assertTrue($valid);
        } catch (\UnexpectedValueException) {
            $this->assertFalse($valid);
        }
    }

    public function testScopeKeysAreNoGrantCellsAndGrantCommandsCannotWriteThem(): void {
        $this->assertSame('files', PolicyEventPayload::scopeModule('scope_files'));
        $this->assertNull(PolicyEventPayload::scopeModule('scope_notes'));
        $this->assertFalse(PolicyEventPayload::validUserKey('scope_files'));
        $this->expectException(\InvalidArgumentException::class);
        $this->fx->services()->grants->applyCells($this->fx->revision(), 'root', PolicyEventPayload::ACTION_COMMANDS, [['uid' => 'alice', 'key' => 'scope_files', 'value' => null]]);
    }

    /** Review F4: a scope receipt holds exactly one scope cell, a grant receipt none, and applyCells writes no scope. */
    public function testReceiptActionsAndCellKindsAreTiedTogether(): void {
        $scope = ['uid' => 'alice', 'key' => 'scope_files', 'before' => null, 'after' => '["5"]'];
        $grant = ['uid' => 'alice', 'key' => 'grant_notes_edit', 'before' => null, 'after' => '1'];
        $this->assertSame([$scope], PolicyEventPayload::receipt('root', PolicyEventPayload::ACTION_SCOPE, '1', '2', [$scope])['changes']);
        foreach ([
            [PolicyEventPayload::ACTION_SCOPE, [$grant]],
            [PolicyEventPayload::ACTION_SCOPE, [$scope, $grant]],
            [PolicyEventPayload::ACTION_SCOPE, [$scope, ['key' => 'scope_calendar'] + $scope]],
            [PolicyEventPayload::ACTION_UPDATE, [$scope]],
            [PolicyEventPayload::ACTION_COMMANDS, [$grant, $scope]],
        ] as $index => [$action, $changes]) {
            try {
                PolicyEventPayload::receipt('root', $action, '1', '2', $changes);
                $this->fail("Receipt case $index accepted");
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
        $before = $this->fx->snapshot();
        try {
            $this->fx->services()->grants->applyCells($before['revision'], 'root', PolicyEventPayload::ACTION_SCOPE, [['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1']]);
            $this->fail('applyCells accepted the scope action');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame($before, $this->fx->snapshot());
    }

    /** The enumerator lists what the account reaches in Nextcloud; a tool must never use it as its view. */
    public function testNoToolOrMcpEndpointUsesTheNativeEnumerator(): void {
        $root = dirname(__DIR__, 4) . '/lib';
        $users = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains((string)file_get_contents((string)$file), 'NativeResourceEnumerator')) {
                $users[] = substr((string)$file, strlen($root) + 1);
            }
        }
        sort($users);
        $this->assertSame([
            'Access/ResourceScope/NativeResourceEnumerator.php',
            'Access/ResourceScope/ResourceScopeWriter.php',
            'Controller/MyScopeController.php',
            'Controller/ScopeController.php',
        ], $users);
    }
}
