<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolRegistry;
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
    public array $calls = [];
    public ?\Throwable $throw = null;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->policy = new GrantPolicy((new InMemoryConfig())->mock($this));
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
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        return new ToolRegistry([$module], $this->policy, $apps, $users, $this->logger);
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

    public function testToolsStayHiddenForUsersOutsideAnAppAllowlist(): void {
        $this->userEnabledApps['alice'] = [];

        $this->assertNotContains('n_read', array_column($this->registry()->list('alice'), 'name'));
        $this->assertContains('n_read', array_column($this->registry()->list('bob'), 'name'));
        $this->assertUnknown('n_read');
        $this->assertSame([], $this->calls);
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
        $this->assertStringNotContainsString('secret', $result['content'][0]['text']);
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
