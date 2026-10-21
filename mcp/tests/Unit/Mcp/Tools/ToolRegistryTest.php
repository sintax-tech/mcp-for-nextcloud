<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools;

use OCA\Mcp\Tests\Support\Mcp\FakeRestrictedModule;

use OCA\Mcp\Access\Policy\Resolution\GrantUnknown;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ToolRegistryTest extends TestCase {
    private GrantPolicy $policy;
    private array $enabledApps = ['notes'];
    /** @var array<string, list<string>> per-user enabled apps for restricted-app cases */
    private array $userEnabledApps = [];
    /** @var array<string, string> installed version per app id; an app left out answers '' (unknown) */
    private array $appVersions = [];
    public array $calls = [];
    public ?\Throwable $throw = null;
    private LoggerInterface $logger;

    /** @var list<string> the alternatives of s_share, in the order the definition lists them */
    public array $shareAnyOf = ['share', 'link'];
    private InMemoryConfig $data;
    /** @var array<string, list<string>>|null apps Guests allows each guest; null: no Guests double at all */
    private ?array $guestApps = null;
    private \OCP\IConfig $config;

    protected function setUp(): void {
        $this->data = new InMemoryConfig();
        $this->config = $this->data->mock($this);
        $this->policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($this->config, new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public static function schema(): array {
        return ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'minimum' => 1, 'default' => 5]], 'additionalProperties' => false];
    }

    private function registry(): ToolRegistry {
        $test = $this;
        $module = new class($test) implements ToolModule {
            public function __construct(private ToolRegistryTest $test) {}
            public function definitions(): array {
                return [
                    ['name' => 'a_read', 'description' => 'r', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'files', 'operation' => 'read'],
                    ['name' => 'a_edit', 'description' => 'e', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'files', 'operation' => 'edit'],
                    ['name' => 'n_read', 'description' => 'n', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'notes', 'operation' => 'read', 'app' => 'notes'],
                    ['name' => 'c_read', 'description' => 'c', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'calendar', 'operation' => 'read', 'app' => 'calendar'],
                    ['name' => 'd_read', 'description' => 'd', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'deck', 'operation' => 'read', 'app' => 'deck'],
                    ['name' => 't_read', 'description' => 't', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'talk', 'operation' => 'read', 'app' => 'spreed'],
                    ['name' => 's_share', 'description' => 's', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'files', 'operation' => 'share', 'grantAnyOf' => $this->test->shareAnyOf],
                ];
            }
            public function call(string $name, array $arguments, string $userId): array {
                $this->test->calls[] = [$name, $arguments, $userId];
                if ($this->test->throw !== null) {
                    throw $this->test->throw;
                }
                return ['content' => [['type' => 'text', 'text' => 'ok']]];
            }
        };
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn (string $app, IUser $user) =>
            isset($this->userEnabledApps[$user->getUID()])
                ? in_array($app, $this->userEnabledApps[$user->getUID()], true)
                : in_array($app, $this->enabledApps, true));
        $apps->method('getAppVersion')->willReturnCallback(fn (string $app): string => $this->appVersions[$app] ?? '');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        $guests = null;
        if ($this->guestApps !== null) {
            $guests = $this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);
            $guests->method('allowsApp')->willReturnCallback(fn (string $uid, string $app): bool =>
                $app === '' || !isset($this->guestApps[$uid]) || in_array($app, $this->guestApps[$uid], true));
        }
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $apps, $users, $guests);
        return new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->logger));
    }

    /** Every registered write gets the same readable envelope, regardless of module preview support. */
    public function testEveryRegisteredWriteReturnsMarkdownAndStructuredConfirmation(): void {
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) { $this->policy->setGrant('alice', $module, $operation, true); }
        }
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        foreach (\OCA\Mcp\AppInfo\Application::MODULES as $class) {
            // Definitions are independent of constructor collaborators. Use the real catalog, replacing
            // only schemas so this test focuses on the registry envelope rather than resource validation.
            $definitions = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->definitions();
            $module = new class($definitions) implements ToolModule {
                public function __construct(private array $definitions) {}
                public function definitions(): array {
                    return array_map(static function (array $definition): array {
                        $definition['inputSchema'] = ['type' => 'object', 'properties' => new \stdClass()];
                        return $definition;
                    }, $this->definitions);
                }
                public function call(string $name, array $arguments, string $userId): array {
                    throw new \LogicException('Unconfirmed writes must not execute');
                }
            };
            $guests = null;
            if ($this->guestApps !== null) {
                $guests = $this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);
                $guests->method('allowsApp')->willReturnCallback(fn (string $uid, string $app): bool =>
                    $app === '' || !isset($this->guestApps[$uid]) || in_array($app, $this->guestApps[$uid], true));
            }
            $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $apps, $users, $guests);
            $registry = new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->logger));
            foreach ($definitions as $definition) {
                if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::isWrite($definition)) { continue; }
                foreach ([[], ['confirm' => false]] as $arguments) {
                    $result = $registry->call($definition['name'], $arguments, 'alice');
                    self::assertTrue($result['structuredContent']['requiresConfirmation'], $definition['name']);
                    self::assertSame('requiresConfirmation', array_key_first($result['structuredContent']));
                    self::assertStringStartsWith('**', $result['content'][0]['text']);
                    self::assertNull(json_decode($result['content'][0]['text'], true));
                }
            }
        }
    }

    /**
     * A confirmed write whose state changed since the plan answers with the new plan, in the very envelope of a call
     * without confirm: not an error, nothing logged, and the person reads it again before saying yes.
     */
    public function testAChangedPlanIsAnsweredAsAPlanToConfirmAgain(): void {
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $this->throw = new \OCA\Mcp\Mcp\Confirmation\PlanChanged(['requiresConfirmation' => false, 'action' => 'update', 'plan_state' => 'abc123',
            'warnings' => [['message' => 'It changed.']], 'message' => 'Show it again.']);
        $this->logger->expects($this->never())->method('error');

        $result = $this->registry()->call('a_edit', ['confirm' => true], 'alice');

        self::assertArrayNotHasKey('isError', $result);
        self::assertSame('requiresConfirmation', array_key_first($result['structuredContent']));
        self::assertTrue($result['structuredContent']['requiresConfirmation'], 'o módulo não desliga a confirmação');
        self::assertSame('a_edit', $result['structuredContent']['tool']);
        self::assertSame('abc123', $result['structuredContent']['plan_state']);
        $text = $result['content'][0]['text'];
        self::assertStringContainsString('- It changed.', $text);
        self::assertStringContainsString('Nothing was changed.', $text);
        self::assertStringContainsString('repeat the call with plan_state `abc123`', $text);
    }

    /** Rendering cannot strip the preview payload or let a module override confirmation. */
    public function testPreviewPayloadIsPreservedAndConfirmationCannotBeOverridden(): void {
        $plan = ['requiresConfirmation' => false, 'message' => 'Review this.', 'custom' => ['value' => 7]];
        $module = new class($plan) implements ToolModule, \OCA\Mcp\Mcp\Confirmation\PreviewsWrites, \OCA\Mcp\Mcp\Confirmation\RendersPlans {
            public function __construct(private array $plan) {}
            public function definitions(): array {
                return [['name' => 'fake_edit', 'description' => 'Edit', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'files', 'operation' => 'edit']];
            }
            public function call(string $name, array $arguments, string $userId): array { throw new \LogicException('Must not execute'); }
            public function preview(string $name, array $arguments, string $userId): array { return $this->plan; }
            public function renderPlan(string $tool, array $plan): ?string { return 'Custom body'; }
        };
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
        $result = (new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->logger)))->call('fake_edit', [], 'alice');
        self::assertSame(['requiresConfirmation' => true, 'tool' => 'fake_edit', 'title' => 'Edit'] + $plan, $result['structuredContent']);
        self::assertStringContainsString('Custom body', $result['content'][0]['text']);
        self::assertStringContainsString('*Review this.*', $result['content'][0]['text']);
    }

    public function testListFollowsGrantsAndApps(): void {
        // The guide is a built-in of the registry, so it leads every list: it is how the model finds out
        // about the tools the grants left standing.
        $this->assertSame(['mcp_guide', 'a_read', 'n_read'], array_column($this->registry()->list('alice'), 'name'));
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $this->policy->setGrant('alice', 'files', 'read', false);
        $this->enabledApps = [];
        $this->assertSame(['mcp_guide', 'a_edit'], array_column($this->registry()->list('alice'), 'name'));
        $this->assertSame(['mcp_guide', 'a_read'], array_column($this->registry()->list('bob'), 'name'));
    }

    /**
     * A tool whose kind of write depends on its arguments (files_share: a person or a public link) is reachable with any
     * of the operations it lists; the module then checks the one the call really needs.
     */
    public function testAToolWithAlternativeGrantsIsReachableWithAnyOfThem(): void {
        $names = fn (string $uid): array => array_column($this->registry()->list($uid), 'name');
        $this->assertNotContains('s_share', $names('alice'), 'nenhuma das duas liberada');
        $this->assertUnknown('s_share');
        $this->assertUnknownWith('s_share', ['confirm' => true]);
        $this->assertSame([], $this->calls, 'sem nenhuma das duas, a chamada nem chega ao módulo');

        $this->policy->setGrant('alice', 'files', 'link', true);
        $this->assertContains('s_share', $names('alice'));
        $this->registry()->call('s_share', ['confirm' => true], 'alice');
        $this->assertSame('s_share', $this->calls[0][0], 'só link: chama');

        $this->policy->setGrant('alice', 'files', 'link', false);
        $this->policy->setGrant('alice', 'files', 'share', true);
        $this->assertContains('s_share', $names('alice'));
        $this->registry()->call('s_share', ['confirm' => true], 'alice');
        $this->assertSame('s_share', $this->calls[1][0], 'só share: chama');
        $this->assertSame('share', array_column($this->registry()->definitions('alice'), null, 'name')['s_share']['operation']);
        $guide = $this->registry()->call('mcp_guide', ['tool' => 's_share'], 'alice')['content'][0]['text'];
        $this->assertStringContainsString('Grant operation: `share` or `link`.', $guide);
    }

    public static function alternativeOrders(): iterable {
        yield 'share, link' => [['share', 'link']];
        yield 'link, share' => [['link', 'share']];
    }

    /**
     * Finding F1 of the review of ticket 09: "any of" is three-valued, so the order of the alternatives never changes the
     * answer. A known Allow of link admits the tool even while share is unknown (its only source is a group in
     * needs_confirm); without that Allow the tool alone is left out of the listing and calling it is unavailable (503),
     * never a denial; with every alternative known and denied the tool is hidden.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('alternativeOrders')]
    public function testAnUnknownAlternativeNeverHidesAKnownAllowInEitherOrder(array $order): void {
        $this->shareAnyOf = $order;
        $this->data->groupBackends = [new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(static fn (): array => ['paused' => ['alice']])];
        InMemoryConfig::groupPolicy($this->config, 'paused', ['files' => ['share']], 'needs_confirm');
        $this->policy->setGrant('alice', 'files', 'link', true);
        $this->assertContains('s_share', array_column($this->registry()->list('alice'), 'name'));
        $this->registry()->call('s_share', ['confirm' => true], 'alice');
        $this->assertSame('s_share', $this->calls[0][0], 'the link Allow lets the call in; the module checks the concrete type');
        $this->assertTrue($this->policy->granted('alice', 'files', 'link'));
        try {
            $this->policy->granted('alice', 'files', 'share');
            $this->fail('The person share became known through the link Allow');
        } catch (PolicyUnavailable) {
        }

        // Unknown and denied: the tool alone is left out of the listing (the others stay), and calling it is a 503.
        $this->policy->setGrant('alice', 'files', 'link', false);
        $names = array_column($this->registry()->list('alice'), 'name');
        $this->assertNotContains('s_share', $names);
        $this->assertContains('a_read', $names, 'a group in needs_confirm never empties the whole list');
        try {
            $this->registry()->call('s_share', ['confirm' => true], 'alice');
            $this->fail('A tool of unknown grant was called');
        } catch (GrantUnknown) {
        }
        $this->assertCount(1, $this->calls);

        InMemoryConfig::groupPolicy($this->config, 'paused', ['files' => ['share']]);
        $this->policy->setGrant('alice', 'files', 'share', false);
        $this->assertNotContains('s_share', array_column($this->registry()->list('alice'), 'name'), 'every alternative known and denied');
        $this->assertUnknown('s_share');
    }

    /** A failed read of the policy is no unknown grant: the whole listing fails (503), nothing is silently left out. */
    public function testAFailedPolicyReadFailsTheWholeListing(): void {
        $writer = InMemoryConfig::services($this->config)->transaction;
        (new \ReflectionProperty($writer, 'usable'))->setValue($writer, false);
        try {
            $this->registry()->list('alice');
            $this->fail('A failed read was answered with a partial list');
        } catch (PolicyUnavailable $e) {
            $this->assertNotInstanceOf(GrantUnknown::class, $e);
        }
    }

    public function testListedDefinitionsExposeOnlyMcpFields(): void {
        $this->assertSame(['name', 'title', 'description', 'inputSchema', 'annotations'], array_keys($this->registry()->list('alice')[0]));
        $this->assertSame(['name', 'title', 'description', 'inputSchema', 'annotations'], array_keys($this->registry()->list('alice')[1]));
    }

    public function testListedDefinitionsCarryTitleAndAnnotations(): void {
        $listed = array_column($this->registry()->list('alice'), null, 'name');
        // a_read/n_read are unmapped fixtures, so they must land on the humanized fallback, never on a raw name.
        $this->assertSame('Read', $listed['a_read']['title']);
        $this->assertSame(['title' => 'Read', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], $listed['a_read']['annotations']);
        $this->assertSame('Read', $listed['n_read']['annotations']['title']);
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $edit = array_column($this->registry()->list('alice'), null, 'name')['a_edit'];
        $this->assertSame(['title' => 'Edit', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false], $edit['annotations']);
    }

    public function testCallRechecksGrantAndAppWithoutReachingTheHandler(): void {
        foreach (['a_edit', 'c_read', 'd_read', 't_read', 'missing'] as $name) {
            $this->assertUnknown($name);
        }
        $this->enabledApps = [];
        foreach (['n_read', 'c_read', 'd_read', 't_read'] as $name) {
            $this->assertUnknown($name);
        }
        $this->policy->setGrant('alice', 'files', 'read', false);
        $this->assertUnknown('a_read');
        $this->assertSame([], $this->calls);
    }

    /**
     * A restricted module answers for itself who may see it, before the grant is even read: a user outside its role
     * neither lists nor calls its tools, granted or not, and the grant still decides for a user inside it.
     */
    public function testARestrictedModuleIsHiddenFromUsersOutsideItsRoleWhateverTheGrant(): void {
        $module = new FakeRestrictedModule();
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
        $registry = new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->logger));
        foreach (['root', 'alice'] as $uid) {
            $this->policy->setGrant($uid, 'logs', 'read', true);
        }

        $this->assertSame(['mcp_guide', 'logs_list'], array_column($registry->list('root'), 'name'));
        $this->assertSame(['mcp_guide'], array_column($registry->list('alice'), 'name'));
        $this->assertUnknownIn($registry, 'logs_list', 'alice');
        $this->assertSame([], $module->calls);

        $registry->call('logs_list', [], 'root');
        $this->assertSame([['logs_list', ['n' => 5], 'root']], $module->calls);

        // Inside the role the grant still decides, and logs.read starts denied.
        $this->assertSame(['mcp_guide'], array_column($registry->list('carl'), 'name'));
        $module->members[] = 'carl';
        $this->assertSame(['mcp_guide'], array_column($registry->list('carl'), 'name'));
    }

    /**
     * Every call to a restricted module is audited by the registry with its outcome, whatever stopped it: the role or
     * the grant (denied, before the module), the arguments (invalid), a failure (read_error) or none (success). A
     * denial is never recorded as a read, and listing the tools audits nothing.
     */
    public function testEveryCallToARestrictedModuleIsAuditedWithItsOutcome(): void {
        $module = new FakeRestrictedModule();
        $module->members[] = 'carl';
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
        $registry = new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->logger));
        $this->policy->setGrant('root', 'logs', 'read', true);
        $registry->list('root');
        $this->assertSame([], $module->audits);

        $this->assertUnknownIn($registry, 'logs_list', 'alice');
        $this->assertUnknownIn($registry, 'logs_list', 'carl');
        try {
            $registry->call('logs_list', ['n' => 0], 'root');
            $this->fail('n below its minimum is invalid');
        } catch (\InvalidArgumentException) {
        }
        $module->throw = new \InvalidArgumentException('since must be ISO 8601');
        try {
            $registry->call('logs_list', ['n' => 2], 'root');
            $this->fail('the module refused the arguments');
        } catch (\InvalidArgumentException) {
        }
        $module->throw = new ToolFailure('The server log could not be read.');
        $this->assertTrue($registry->call('logs_list', [], 'root')['isError']);
        $module->throw = new \RuntimeException('disk');
        $this->assertTrue($registry->call('logs_list', [], 'root')['isError']);
        $module->throw = null;
        $registry->call('logs_list', ['n' => 3], 'root');

        $this->assertSame([
            ['logs_list', 'alice', 'denied', []],
            ['logs_list', 'carl', 'denied', []],
            ['logs_list', 'root', 'invalid', ['n' => 0]],
            ['logs_list', 'root', 'invalid', ['n' => 2]],
            ['logs_list', 'root', 'read_error', ['n' => 5]],
            ['logs_list', 'root', 'read_error', ['n' => 5]],
            ['logs_list', 'root', 'success', ['n' => 3]],
        ], $module->audits);
        $this->assertSame([\OCA\Mcp\Mcp\Tools\RestrictedModule::DENIED, \OCA\Mcp\Mcp\Tools\RestrictedModule::INVALID, \OCA\Mcp\Mcp\Tools\RestrictedModule::READ_ERROR, \OCA\Mcp\Mcp\Tools\RestrictedModule::SUCCESS],
            ['denied', 'invalid', 'read_error', 'success']);
    }

    /**
     * A failing audit never replaces the answer of a call that read nothing (denied, invalid, failed), and is logged
     * without arguments; but a read whose audit failed hands no data out: the answer is a generic error.
     */
    public function testAFailingAuditKeepsRefusalsAndWithholdsTheData(): void {
        $module = new FakeRestrictedModule();
        $module->auditThrow = new \RuntimeException('audit backend down: secret-filter');
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$logged): void {
            $logged[] = [$message, $context];
        });
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
        $registry = new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $logger));
        $this->policy->setGrant('root', 'logs', 'read', true);

        $this->assertUnknownIn($registry, 'logs_list', 'alice');
        try {
            $registry->call('logs_list', ['n' => 0], 'root');
            $this->fail('n below its minimum is invalid');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('audit backend down: secret-filter', $e->getMessage());
        }
        $module->throw = new ToolFailure('The server log could not be read.');
        $this->assertSame(ToolResult::error('The server log could not be read.'), $registry->call('logs_list', [], 'root'));
        $module->throw = null;
        $success = $registry->call('logs_list', ['n' => 3], 'root');

        $this->assertSame(ToolResult::error('Unexpected error while accessing Nextcloud.'), $success, 'no data without its audit');
        $this->assertSame(['denied', 'invalid', 'read_error', 'success'], array_column($module->audits, 2));
        $audits = array_values(array_filter($logged, static fn (array $line): bool => $line[0] === 'MCP audit failed'));
        $this->assertSame(['denied', 'invalid', 'read_error', 'success'], array_column(array_column($audits, 1), 'outcome'));
        foreach ($audits as [, $context]) {
            $this->assertSame(['app', 'tool', 'outcome', 'exception_class'], array_keys($context));
            $this->assertSame(\RuntimeException::class, $context['exception_class']);
        }
    }

    /**
     * A file lock that reaches the registry from any module is an expected refusal, not an unexpected failure: the
     * client reads that the file is locked, and nothing is logged as an error.
     */
    public function testAFileLockThatEscapesAModuleIsALockedRefusalWithoutErrorLog(): void {
        $module = new FakeRestrictedModule();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
        $registry = new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $logger));
        $this->policy->setGrant('root', 'logs', 'read', true);
        $module->throw = new \OCP\Lock\ManuallyLockedException('/root/files/x', null, 'files_lock/x', 'text', -1);
        $this->assertSame(ToolResult::error(\OCA\Mcp\Platform\FileAccess\Locks\LockMessages::lockedWithoutDetails()), $registry->call('logs_list', [], 'root'));
        $module->throw = new \OCP\Lock\LockedException('/root/files/x');
        $this->assertSame(ToolResult::error(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::locked()), $registry->call('logs_list', [], 'root'));
    }

    public function testAFailingFallbackLoggerKeepsRefusalsAndWithholdsData(): void {
        $module = new FakeRestrictedModule();
        $module->auditThrow = new \RuntimeException('audit down');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('logger down'));
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
        $registry = new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $logger));
        $this->policy->setGrant('root', 'logs', 'read', true);
        $this->assertUnknownIn($registry, 'logs_list', 'alice');
        try {
            $registry->call('logs_list', ['n' => 0], 'root');
            $this->fail('invalid input must stay invalid');
        } catch (\InvalidArgumentException) {
        }
        $module->throw = new ToolFailure('The server log could not be read.');
        $this->assertSame(ToolResult::error('The server log could not be read.'), $registry->call('logs_list', [], 'root'));
        $module->throw = null;
        $this->assertSame(ToolResult::error('Unexpected error while accessing Nextcloud.'), $registry->call('logs_list', [], 'root'));
        $this->assertSame(['denied', 'invalid', 'read_error', 'success'], array_column($module->audits, 2));
    }

    private function assertUnknownIn(ToolRegistry $registry, string $name, string $uid): void {
        try {
            $registry->call($name, [], $uid);
            $this->fail($name . ' must stay unknown to ' . $uid);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Unknown tool', $e->getMessage());
        }
    }

    public function testToolsStayHiddenForUsersOutsideAnAppAllowlist(): void {
        $this->userEnabledApps['alice'] = [];

        $this->assertNotContains('n_read', array_column($this->registry()->list('alice'), 'name'));
        $this->assertContains('n_read', array_column($this->registry()->list('bob'), 'name'));
        $this->assertUnknown('n_read');
        $this->assertSame([], $this->calls);
    }

    /**
     * A guest of the Guests app reaches only the modules whose app Guests allows to them: the others leave tools/list
     * and a call is refused before the module runs. Everyone else keeps every tool.
     */
    public function testAGuestOnlyReachesTheModulesOfTheAppsGuestsAllows(): void {
        $this->enabledApps = ['notes', 'calendar', 'deck', 'spreed'];
        foreach (['alice', 'bob'] as $uid) {
            foreach (['files', 'notes', 'calendar', 'deck', 'talk'] as $module) {
                $this->policy->setGrant($uid, $module, 'read', true);
            }
        }
        $this->guestApps = ['alice' => ['files', 'calendar']];
        $this->assertSame(['mcp_guide', 'a_read', 'c_read'], array_column($this->registry()->list('alice'), 'name'));
        $this->assertSame(['mcp_guide', 'a_read', 'n_read', 'c_read', 'd_read', 't_read'], array_column($this->registry()->list('bob'), 'name'));
        foreach (['n_read', 'd_read', 't_read'] as $name) {
            $this->assertUnknown($name);
        }
        $this->assertSame([], $this->calls);
        $this->registry()->call('c_read', [], 'alice');
        $this->assertSame('c_read', $this->calls[0][0]);

        // Only the app list of Guests: the files module of a guest whose list lacks it (impossible in Guests, which
        // always allows files) leaves too.
        $this->guestApps = ['alice' => []];
        $this->assertSame(['mcp_guide'], array_column($this->registry()->list('alice'), 'name'));
    }

    /** A Talk older than the oldest release MCP supports counts as off: its tools leave the list and a call is refused. */
    public function testToolsOfAnOptionalAppInAnUnsupportedVersionDisappear(): void {
        $this->enabledApps = ['spreed', 'deck'];
        $this->appVersions = ['spreed' => '21.1.3', 'deck' => '1.15.0'];

        $names = array_column($this->registry()->list('alice'), 'name');
        $this->assertNotContains('t_read', $names);
        $this->assertContains('d_read', $names);
        $this->assertUnknown('t_read');
        $this->assertSame([], $this->calls);

        $this->appVersions['spreed'] = '21.1.4';
        $this->assertContains('t_read', array_column($this->registry()->list('alice'), 'name'));
    }

    public function testCallValidatesArgumentsAndAppliesDefaults(): void {
        $registry = $this->registry();
        $this->assertSame('ok', $registry->call('a_read', [], 'alice')['content'][0]['text']);
        $this->assertSame([['a_read', ['n' => 5], 'alice']], $this->calls);
        foreach ([['n' => 0], ['n' => '5'], ['x' => 1], [1]] as $bad) {
            try {
                $registry->call('a_read', $bad, 'alice');
                $this->fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(1, $this->calls);
    }

    public function testFailuresBecomeSafeToolErrors(): void {
        $this->throw = new ToolFailure(ToolFailure::NOT_FOUND);
        $this->assertSame(['content' => [['type' => 'text', 'text' => ToolFailure::NOT_FOUND]], 'isError' => true], $this->registry()->call('a_read', [], 'alice'));

        $this->throw = new \RuntimeException('/var/www/data/alice/secret.txt');
        $this->logger->expects($this->once())->method('error')->with('MCP tool failed', $this->callback(
            fn (array $context) => $context['exception_class'] === \RuntimeException::class && !str_contains(json_encode($context), 'secret')));
        $result = $this->registry()->call('a_read', [], 'alice');
        $this->assertTrue($result['isError']);
        $this->assertSame('Unexpected error while accessing Nextcloud.', $result['content'][0]['text']);
    }

    public function testUnexpectedFailureLogsClassAndCodeButNeverTheMessage(): void {
        $this->throw = new \Error('Call to undefined method OCA\DAV\CalDAV\CalDavBackend::missing()');
        $logged = [];
        $this->logger->expects($this->once())->method('error')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
            $logged = $context;
        });
        $result = $this->registry()->call('a_read', [], 'alice');
        $this->assertSame('Error (code 0)', $logged['exception_message']);
        $this->assertSame(\Error::class, $logged['exception_class']);
        $this->assertStringNotContainsString('missing', $result['content'][0]['text']);
    }

    /** A message may name the user's files in any form, so the log never carries it, whatever the log setting. */
    public function testLoggedMessageNeverCarriesFileNames(): void {
        $this->throw = new \RuntimeException('File with name /Docs/Salary#Confidential could not be located; "README" ' . str_repeat('x', 400), 5);
        $logged = [];
        $this->logger->expects($this->once())->method('error')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
            $logged = $context;
        });
        $this->registry()->call('a_read', [], 'alice');
        $this->assertSame('RuntimeException (code 5)', $logged['exception_message']);
        foreach (['Salary', 'Confidential', 'README', 'Docs'] as $part) {
            $this->assertStringNotContainsString($part, json_encode($logged));
        }
    }

    /** The refusal of a hidden tool, called with arguments: the same as for a tool that does not exist. */
    private function assertUnknownWith(string $name, array $arguments): void {
        try {
            $this->registry()->call($name, $arguments, 'alice');
            $this->fail("$name was callable");
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Unknown tool', $e->getMessage());
            try {
                $this->registry()->call('no_such_tool', $arguments, 'alice');
            } catch (\InvalidArgumentException $missing) {
                $this->assertSame($missing::class, $e::class);
                $this->assertSame($missing->getMessage(), $e->getMessage());
            }
        }
    }

    private function assertUnknown(string $name): void {
        try {
            $this->registry()->call($name, [], 'alice');
            $this->fail("$name was callable");
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Unknown tool', $e->getMessage());
        }
    }
}
