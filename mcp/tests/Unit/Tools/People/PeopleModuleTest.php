<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\People;

use InvalidArgumentException;
use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tools\ArgumentValidator;
use OCA\Mcp\Tools\People\PeopleModule;
use OCA\Mcp\Tools\ToolPresentation;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the account search: exact and partial matches, limit, term bounds and the optional e-mail;
 * and the optional group search, which adds the group type to the same call without changing the account output.
 */
final class PeopleModuleTest extends TestCase {
    private function user(string $uid, string $name, ?string $email = null): array {
        return ['label' => $name, 'subline' => $email, 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => $uid]];
    }

    /** A group row as the core GroupPlugin builds it: no subline, only a display name and the group ID. */
    private function group(string $gid, string $name): array {
        return ['label' => $name, 'value' => ['shareType' => IShare::TYPE_GROUP, 'shareWith' => $gid]];
    }

    /** @param array<string, mixed> $result */
    private function module(array $result, ?array &$captured = null): PeopleModule {
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturnCallback(function ($term, $types, $lookup, $limit, $offset) use ($result, &$captured) {
            $captured = [$term, $types, $lookup, $limit, $offset];
            return [$result, false];
        });
        return new PeopleModule($search);
    }

    /** @return list<array<string, string>> */
    private function lookup(PeopleModule $module, array $args): array {
        $out = $module->call('users_search', $args + ['limit' => 10], 'alice');
        return json_decode($out['content'][0]['text'], true);
    }

    public function testDefinitionIsAnAppLessReadTool(): void {
        $definitions = (new PeopleModule($this->createMock(ISearch::class)))->definitions();
        self::assertCount(1, $definitions);
        self::assertSame('users_search', $definitions[0]['name']);
        self::assertSame('people', $definitions[0]['module']);
        self::assertSame('read', $definitions[0]['operation']);
        self::assertArrayNotHasKey('app', $definitions[0]);
        self::assertSame(25, $definitions[0]['inputSchema']['properties']['limit']['maximum']);
        self::assertSame(10, $definitions[0]['inputSchema']['properties']['limit']['default']);
        self::assertSame(2, $definitions[0]['inputSchema']['properties']['query']['minLength']);
        self::assertSame(100, $definitions[0]['inputSchema']['properties']['query']['maxLength']);
        $groups = $definitions[0]['inputSchema']['properties']['include_groups'];
        self::assertSame('boolean', $groups['type']);
        self::assertFalse($groups['default']);
        self::assertStringContainsString('group', $groups['description']);
        self::assertSame(['query'], $definitions[0]['inputSchema']['required']);
        self::assertStringContainsString('include_groups', $definitions[0]['description']);
    }

    public function testIncludeGroupsIsValidatedAsABooleanByTheSchema(): void {
        $schema = (new PeopleModule($this->createMock(ISearch::class)))->definitions()[0]['inputSchema'];
        self::assertFalse(ArgumentValidator::validate($schema, ['query' => 've'])['include_groups']);
        self::assertTrue(ArgumentValidator::validate($schema, ['query' => 've', 'include_groups' => true])['include_groups']);
        $this->expectException(InvalidArgumentException::class);
        ArgumentValidator::validate($schema, ['query' => 've', 'include_groups' => 'yes']);
    }

    public function testSearchesUsersOnlyWithoutLookupAndMergesExactFirst(): void {
        $captured = null;
        $module = $this->module([
            'exact' => ['users' => [$this->user('pedro', 'Pedro Almeida', 'pedro@x.com')]],
            'users' => [$this->user('pedrosa', 'Ana Pedrosa'), $this->user('pedro', 'Pedro Almeida', 'pedro@x.com')],
        ], $captured);
        $rows = $this->lookup($module, ['query' => 'pedro']);
        self::assertSame([IShare::TYPE_USER], $captured[1]);
        self::assertFalse($captured[2]);
        self::assertSame([
            ['type' => 'user', 'uid' => 'pedro', 'displayName' => 'Pedro Almeida', 'email' => 'pedro@x.com'],
            ['type' => 'user', 'uid' => 'pedrosa', 'displayName' => 'Ana Pedrosa'],
        ], $rows);
    }

    public function testGroupsAreNotReturnedWhenTheyWereNotAskedFor(): void {
        $captured = null;
        $module = $this->module([
            'users' => [$this->user('vendas', 'Vendas')],
            'groups' => [$this->group('vendas', 'Vendas')],
        ], $captured);
        $rows = $this->lookup($module, ['query' => 'vendas']);
        self::assertSame([IShare::TYPE_USER], $captured[1]);
        self::assertSame([['type' => 'user', 'uid' => 'vendas', 'displayName' => 'Vendas']], $rows);
    }

    public function testGroupsAreSearchedOnlyWhenAskedForAndCarryTheirId(): void {
        $captured = null;
        $module = $this->module([
            'users' => [$this->user('vera', 'Vera Lima')],
            'groups' => [$this->group('vendas', 'Vendas')],
        ], $captured);
        $rows = $this->lookup($module, ['query' => 've', 'include_groups' => true]);
        self::assertSame([IShare::TYPE_USER, IShare::TYPE_GROUP], $captured[1]);
        self::assertFalse($captured[2]);
        self::assertSame([
            ['type' => 'user', 'uid' => 'vera', 'displayName' => 'Vera Lima'],
            ['type' => 'group', 'id' => 'vendas', 'displayName' => 'Vendas'],
        ], $rows);
    }

    public function testExactMatchesComeFirstAndAccountsBeforeGroups(): void {
        $module = $this->module([
            'exact' => ['users' => [$this->user('vendas', 'Vendas')], 'groups' => [$this->group('vendas', 'Vendas')]],
            'users' => [$this->user('vera', 'Vera Lima')],
            'groups' => [$this->group('vendas-2', 'Vendasregionais')],
        ]);
        $rows = $this->lookup($module, ['query' => 'vendas', 'include_groups' => true]);
        self::assertSame(['user', 'group', 'user', 'group'], array_column($rows, 'type'));
        self::assertSame(['vendas', 'vendas', 'vera', 'vendas-2'], array_map(
            static fn (array $row): string => (string)($row['id'] ?? $row['uid']),
            $rows,
        ));
    }

    public function testAnAccountAndAGroupWithTheSameIdAreBothReturned(): void {
        $module = $this->module([
            'users' => [$this->user('comercial', 'Comercial')],
            'groups' => [$this->group('comercial', 'Comercial'), $this->group('comercial', 'Comercial')],
        ]);
        $rows = $this->lookup($module, ['query' => 'com', 'include_groups' => true]);
        self::assertCount(2, $rows);
        self::assertSame('user', $rows[0]['type']);
        self::assertSame('group', $rows[1]['type']);
    }

    public function testTheLimitStillCapsTheRowsWithBothTypes(): void {
        $captured = null;
        $module = $this->module([
            'users' => [$this->user('a1', 'A1'), $this->user('a2', 'A2')],
            'groups' => [$this->group('a3', 'A3'), $this->group('a4', 'A4')],
        ], $captured);
        $rows = $this->lookup($module, ['query' => 'aa', 'limit' => 3, 'include_groups' => true]);
        self::assertSame(3, $captured[3]);
        self::assertCount(3, $rows);
    }

    public function testEmailIsOmittedWhenTheApiReturnsNone(): void {
        $rows = $this->lookup($this->module(['users' => [$this->user('bob', 'Bob', ''), $this->user('eve', 'Eve', 'not an email')]]), ['query' => 'bo']);
        self::assertArrayNotHasKey('email', $rows[0]);
        self::assertArrayNotHasKey('email', $rows[1]);
    }

    public function testLimitIsAppliedToTheRowsAndPassedToTheApi(): void {
        $captured = null;
        $module = $this->module(['users' => [$this->user('a1', 'A1'), $this->user('a2', 'A2'), $this->user('a3', 'A3')]], $captured);
        $rows = $this->lookup($module, ['query' => 'a1', 'limit' => 2]);
        self::assertCount(2, $rows);
        self::assertSame(2, $captured[3]);
    }

    public function testNoResultsIsAnEmptyList(): void {
        self::assertSame([], $this->lookup($this->module([]), ['query' => 'zz']));
    }

    public function testShortOrLongTermsAreRejected(): void {
        $module = $this->module([]);
        foreach (['a', ' a ', '', str_repeat('x', 101)] as $term) {
            try {
                $this->lookup($module, ['query' => $term]);
                self::fail('Expected rejection for: ' . $term);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLimitOutsideRangeIsRejectedAndUnknownToolToo(): void {
        $module = $this->module([]);
        foreach ([0, 26] as $limit) {
            try {
                $this->lookup($module, ['query' => 'ab', 'limit' => $limit]);
                self::fail('Expected rejection');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $module->call('users_other', [], 'alice');
    }

    public function testReadGrantIsOnByDefaultAndCanBeDenied(): void {
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        self::assertSame(['read'], GrantPolicy::CATALOG['people']);
        self::assertTrue($policy->granted('alice', 'people', 'read'));
        $policy->setGrant('alice', 'people', 'read', false);
        self::assertFalse($policy->granted('alice', 'people', 'read'));
    }

    public function testModuleIsRegisteredAndTheModelIsToldToSearchBeforeGuessing(): void {
        self::assertContains(PeopleModule::class, Application::MODULES);
        self::assertStringContainsString('Never invent an ID', ToolPresentation::INSTRUCTIONS);
        self::assertStringContainsString('Search people', ToolPresentation::INSTRUCTIONS);
        self::assertSame('Search people', ToolPresentation::title('users_search'));
        self::assertStringContainsString('users_search', implode(' ', (new PeopleModule($this->createMock(ISearch::class)))->guideNotes()));
    }

    public function testTheModelIsToldHowToShareWithAGroup(): void {
        $module = new PeopleModule($this->createMock(ISearch::class));
        $description = $module->definitions()[0]['description'];
        self::assertStringContainsString('include_groups', $description);
        self::assertStringContainsString('group:', $description);
        $notes = implode(' ', $module->guideNotes());
        self::assertStringContainsString('include_groups', $notes);
        self::assertStringContainsString('group:', $notes);
    }
}
