<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\ArgumentValidator;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\Reorganization;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Files\VersionTools;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Wiring shared by the Files tool tests: a personal tree under /alice/files, files_versions enabled and
 * a real time, plus the tools built exactly the way the registry builds them.
 */
abstract class FilesToolsTestCase extends TestCase {
    protected FakeTree $tree;
    protected InMemoryCheckoutTokenStore $store;
    protected IAppManager $apps;
    protected IUserManager $users;
    protected IUser $user;
    protected ITimeFactory $time;
    protected ITempManager $temp;
    protected FilesModule $module;
    /** @var list<string> tokens handed out per route, in order */
    protected array $issued = [];
    /** @var list<string> apps enabled for alice; a test can empty it to simulate files_versions being off */
    protected array $enabled = ['files_versions'];

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->tree->addFolder('/alice/files/Documentos');
        $this->tree->addFile('/alice/files/Documentos/ata.md', "# Ata\nolá", 'text/markdown');
        $this->store = new InMemoryCheckoutTokenStore();

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice'
            ? $this->tree->rootFolder()
            : throw new \LogicException('other user'));
        $this->temp = $this->createMock(ITempManager::class);
        $this->temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
        $this->apps = $this->createMock(IAppManager::class);
        // Bound by reference on purpose: a test flips $this->enabled to simulate an app being turned off.
        $enabled = &$this->enabled;
        $this->apps->method('isEnabledForUser')->willReturnCallback(function (string $app) use (&$enabled): bool {
            return in_array($app, $enabled, true);
        });
        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('alice');
        $this->users = $this->createMock(IUserManager::class);
        $this->users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $this->user : null);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->time->method('getTime')->willReturn(1790000000);
        $db = $this->createMock(IDBConnection::class);
        $db->method('escapeLikeParameter')->willReturnCallback(fn (string $s) => addcslashes($s, '\\_%'));
        $config = (new InMemoryConfig())->mock($this);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(function (string $route, array $args = []): string {
            $this->issued[$route][] = (string)($args['token'] ?? '');
            return 'https://cloud.test/apps/mcp/' . ($args['token'] ?? '');
        });

        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $extractor = new TextExtractor($this->temp);
        $backup = new FileBackup($this->apps, $this->users, $this->time, $config);
        $this->module = new FilesModule(
            $root,
            $extractor,
            $backup,
            $this->users,
            $db,
            $access,
            new SharedWriteGuard($access),
            new CheckoutService($urls, $config, $this->time, new TokenHasher($config), $this->store, $this->apps, $this->users),
            new VersionTools($this->apps, $this->users, $extractor, $backup, $access, $this->createMock(\Psr\Container\ContainerInterface::class)),
            new Reorganization($access),
        );
    }

    /** Runs a tool the way the registry does: schema validation first, then the handler. */
    protected function tool(string $name, array $arguments = []): array {
        foreach ($this->module->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $this->module->call($name, ArgumentValidator::validate($definition['inputSchema'], $arguments), 'alice');
            }
        }
        $this->fail("no tool $name");
    }

    /** @return array<string, mixed> the decoded JSON of a tool result */
    protected function json(string $name, array $arguments = []): array {
        return json_decode($this->tool($name, $arguments)['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return string the message of the ToolFailure the tool raised */
    protected function failure(string $name, array $arguments): string {
        try {
            $this->tool($name, $arguments);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        $this->fail("$name did not fail");
    }

    /** @return string the last download token handed out */
    protected function downloadToken(): string {
        return $this->lastToken('mcp.checkout.download');
    }

    /** @return string the last upload token handed out */
    protected function uploadToken(): string {
        return $this->lastToken('mcp.checkout.upload');
    }

    /** @return string the last token minted for a route */
    protected function lastToken(string $route): string {
        $tokens = $this->issued[$route] ?? [];
        $this->assertNotSame([], $tokens, "no token issued for $route");
        return (string)end($tokens);
    }

    /** @return CheckoutTokenStore the store type, for readers that need the class name */
    protected function storeClass(): string {
        return CheckoutTokenStore::class;
    }
}
