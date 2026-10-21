<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Modules\People\PeopleModule;
use OCA\Mcp\Modules\People\Tools\SearchUsers;
use OCA\Mcp\Modules\People\Search\PeopleSearch;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Guide\ToolGuide;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\Validation\UnknownToolException;
use OCP\Collaboration\Collaborators\ISearch;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PeopleMigrationCompatibilityTest extends TestCase {
    protected function tearDown(): void {
        Translator::reset();
    }

    private function module(ISearch $search, ?LoggerInterface $logger = null): PeopleModule {
        return new PeopleModule(['users_search' => fn () => new SearchUsers(new PeopleSearch($search, $logger ?? $this->createMock(LoggerInterface::class)))]);
    }

    private function registry(PeopleModule $module, ?GrantPolicy &$policy = null): ToolRegistry {
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $policy->setGrant('alice', 'people', 'read', true);
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(false);
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, $this->createMock(\OCP\IUserManager::class));
        return new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->createMock(LoggerInterface::class)));
    }

    private static function row(string $id, ?string $label = null, string $subline = ''): array {
        return ['value' => ['shareWith' => $id], 'label' => $label, 'subline' => $subline];
    }

    private static function mixedResults(): array {
        return [
            'exact' => ['users' => [self::row('0', 'First', 'zero@example.test')], 'groups' => [self::row('0', '')]],
            'users' => [false, [], self::row('0', 'Duplicate'), self::row('beta', null, "tab\t@example.test"), self::row('bad', "bad\xff", 'has space@example.test')],
            'groups' => [self::row('0', 'Duplicate group'), self::row('team', null, 'omit@example.test')],
            'emails' => [self::row('external', 'Not an account')],
        ];
    }

    /** Immutable fixtures are captured once from the pre-extraction revision. */
    public function capture(): array {
        Translator::reset();
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturn([self::mixedResults(), true]);
        $module = $this->module($search);
        $registry = $this->registry($module);
        $published = $registry->definitions('alice');
        $definitions = [
            'raw' => $module->definitions(),
            'published' => $published,
            'guide' => (new ToolGuide([$module]))->result($published, ['module' => 'people']),
            'moduleOrder' => array_map(static fn (string $class): string => substr($class, strrpos($class, '\\') + 1), Application::MODULES),
        ];
        $results = [];
        foreach (['users' => [], 'mixed' => ['include_groups' => true], 'capped' => ['include_groups' => true, 'limit' => 2]] as $key => $args) {
            $results[$key] = $module->call('users_search', ['query' => 'zero'] + $args, 'alice');
        }
        $empty = $this->createMock(ISearch::class);
        $empty->method('search')->willReturn([[], false]);
        $results['empty'] = $this->module($empty)->call('users_search', ['query' => 'none'], 'alice');
        foreach (['en', 'pt_BR', 'es'] as $locale) {
            Translator::use(new JsonL10n($locale));
            $failing = $this->createMock(ISearch::class);
            $failing->method('search')->willThrowException(new \RuntimeException('private@example.test'));
            $results['failure_'.$locale] = $this->registry($this->module($failing))->call('users_search', ['query' => 'private@example.test'], 'alice');
        }
        Translator::reset();
        return ['definitions' => $definitions, 'results' => $results];
    }

    public function testFrozenPublicationAndResults(): void {
        foreach ($this->capture() as $name => $value) {
            $expected = file_get_contents(dirname(__DIR__, 2).'/Support/People/Compatibility/'.$name.'.json');
            self::assertSame($expected, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function testTrimmedUnicodeBoundsAndDirectCoercion(): void {
        $calls = [];
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturnCallback(static function (...$args) use (&$calls): array { $calls[] = $args; return [[], false]; });
        $module = $this->module($search);
        $module->call('users_search', ['query' => '  éé  '], 'alice');
        $module->call('users_search', ['query' => str_repeat('é', 100), 'limit' => '2', 'include_groups' => 'false'], 'alice');
        self::assertSame([['éé', [0], false, 10, 0], [str_repeat('é', 100), [0, 1], false, 2, 0]], $calls);
        foreach ([[], ['query' => ' é '], ['query' => str_repeat('é', 101)], ['query' => 'ok', 'limit' => 0]] as $args) {
            try { $module->call('users_search', $args, 'alice'); self::fail('Expected invalid input'); }
            catch (\InvalidArgumentException $e) { self::assertSame('Invalid search term or limit', $e->getMessage()); }
        }
        $registry = $this->registry($module);
        foreach ([['query' => 'ok', 'limit' => '2'], ['query' => 'ok', 'include_groups' => 'false']] as $args) {
            try { $registry->call('users_search', $args, 'alice'); self::fail('Expected schema rejection'); }
            catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
        self::assertCount(2, $calls, 'Rejected input must never reach the native search');
    }

    public function testTypedDeduplicationPrecedesCombinedLimit(): void {
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturn([self::mixedResults(), false]);
        $result = $this->module($search)->call('users_search', ['query' => 'zero', 'include_groups' => true, 'limit' => 3], 'alice');
        self::assertSame([
            ['type' => 'user', 'uid' => '0', 'displayName' => 'First', 'email' => 'zero@example.test'],
            ['type' => 'group', 'id' => '0', 'displayName' => ''],
            ['type' => 'user', 'uid' => 'beta', 'displayName' => 'beta', 'email' => "tab\t@example.test"],
        ], json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testNativeFailureBoundaryAndPrivacy(): void {
        foreach ([new \RuntimeException('private@example.test'), new \Error('private@example.test')] as $failure) {
            $search = $this->createMock(ISearch::class);
            $search->method('search')->willThrowException($failure);
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('warning')->with('MCP people search failed', ['app' => 'mcp', 'exception_class' => $failure::class]);
            try { $this->module($search, $logger)->call('users_search', ['query' => 'private@example.test'], 'alice'); self::fail('Expected safe failure'); }
            catch (ToolFailure $e) { self::assertSame('The search for people failed; try again.', $e->getMessage()); self::assertNull($e->getPrevious()); }
        }
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturn(['invalid-result', false]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        try { $this->module($search, $logger)->call('users_search', ['query' => 'ok'], 'alice'); self::fail('Expected mapping TypeError'); }
        catch (\TypeError) { self::addToAssertionCount(1); }
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willThrowException(new \RuntimeException('native'));
        $logger = $this->createMock(LoggerInterface::class);
        $failure = new \RuntimeException('logger failed');
        $logger->method('warning')->willThrowException($failure);
        try { $this->module($search, $logger)->call('users_search', ['query' => 'ok'], 'alice'); self::fail('Expected logger failure'); }
        catch (\RuntimeException $e) { self::assertSame($failure, $e); }
    }

    public function testDeniedGrantStopsNativeAccessAndOptionalAppsAreIrrelevant(): void {
        $search = $this->createMock(ISearch::class);
        $search->expects(self::never())->method('search');
        $registry = $this->registry($this->module($search), $policy);
        self::assertSame(['users_search'], array_column($registry->definitions('alice'), 'name'));
        $policy->setGrant('alice', 'people', 'read', false);
        self::assertSame([], $registry->definitions('alice'));
        $this->expectException(UnknownToolException::class);
        $registry->call('users_search', ['query' => 'ok'], 'alice');
    }

    public function testRepeatedCallsFollowHostSessionAndCurrentLocale(): void {
        $sessionUser = 'alice';
        $fails = false;
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturnCallback(static function () use (&$sessionUser, &$fails): array {
            if ($fails) { throw new \RuntimeException('private term'); }
            return [['users' => [self::row($sessionUser)]], false];
        });
        $module = $this->module($search);
        foreach (['alice', 'bob', 'alice'] as $sessionUser) {
            $result = $module->call('users_search', ['query' => 'same'], 'context-does-not-impersonate');
            self::assertSame($sessionUser, json_decode($result['content'][0]['text'], true)[0]['uid']);
        }
        $fails = true;
        foreach (['en' => 'The search for people failed; try again.', 'es' => 'La búsqueda de personas falló; inténtelo de nuevo.', 'en' . '_again' => 'The search for people failed; try again.'] as $locale => $expected) {
            Translator::use(new JsonL10n($locale === 'en_again' ? 'en' : $locale));
            try { $module->call('users_search', ['query' => 'same'], 'alice'); self::fail('Expected failure'); }
            catch (ToolFailure $e) { self::assertSame($expected, $e->getMessage()); }
        }
    }
}
