<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\ArgumentValidator;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FileCreation;
use OCA\Mcp\Tools\Files\ImageTools;
use OCA\Mcp\Tools\Files\MovePlanner;
use OCA\Mcp\Tools\Files\MoveReport;
use OCA\Mcp\Tools\Files\Reorganization;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\OcrSupport;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Files\VersionTools;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\WriteGate;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IPreview;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * Wiring shared by the Files tool tests: a personal tree under /alice/files, files_versions enabled and
 * a real time, plus the tools built exactly the way the registry builds them.
 */
abstract class FilesToolsTestCase extends TestCase {
    /** Tools whose confirmed call gives back the plan_state of its plan. */
    protected const STATEFUL_PLANS = ['files_share', 'files_unshare'];
    /** The plan_state of a confirmed call whose plan refused: no state the write could ever compute. */
    protected const UNPLANNED_STATE = 'no-plan-was-shown';
    protected FakeTree $tree;
    protected InMemoryCheckoutTokenStore $store;
    protected IAppManager $apps;
    /** Whether the Workflow OCR app counts as enabled. */
    protected bool $ocrActive = false;
    protected IUserManager $users;
    protected IUser $user;
    protected ITimeFactory $time;
    protected InMemoryBatchStore $batches;
    /** The store the module records batches in, when a test needs the production one instead of {@see self::$batches}. */
    protected ?\OCA\Mcp\Tools\Files\BatchStore $batchStore = null;
    protected ITempManager $temp;
    protected \OCA\Mcp\Tests\Unit\InMemoryConfig $config;
    protected IPreview $previewManager;
    protected ISystemTagManager $tagManager;
    protected ISystemTagObjectMapper $tagMapper;
    protected \Psr\Log\LoggerInterface $logger;
    protected FilesModule $module;
    /** files_lock behind ILockManager, without any lock until a test puts one. */
    protected FakeLockManager $locks;
    protected ?\OCA\Mcp\Service\VisibilityGuard $visibilityGuard = null;
    /** @var list<string> tokens handed out per route, in order */
    protected array $issued = [];
    /** @var array<string, string> accounts the sharing tools know, uid => display name */
    protected array $people = ['alice' => 'Alice', 'bruno' => 'Bruno Lima', 'carla' => 'Carla Dias'];
    /** @var array<string, array{name:string, members:list<string>}> groups the sharing tools know, by gid */
    protected array $groups = ['finance' => ['name' => 'Financeiro', 'members' => ['alice', 'bruno']], 'board' => ['name' => 'Diretoria', 'members' => ['carla']]];
    /** Grants of the sharing tools: read only until a test allows share or link. */
    protected \OCA\Mcp\Service\GrantPolicy $sharePolicy;
    /** @var list<string> passwords the password_policy generator hands out for links, in order; empty means no generator */
    protected array $linkPasswords = [];
    /** Whether the password policy refuses every password, as one no generated password can meet. */
    protected bool $refuseAllPasswords = false;
    /** @var (\Closure(string): \Throwable)|null what a broken policy listener throws for the candidate it validates, null for none */
    protected ?\Closure $passwordPolicyFailure = null;
    /** Logger of the share writer, to check that only the exception class is logged. */
    protected \Psr\Log\LoggerInterface $shareLogger;
    /** Shares alice created, behind the IShareManager double of files_list_shares. */
    protected \OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares $shares;
    /** Logger of files_upload and files_create, to check that a failed write logs the exception class only. */
    protected \Psr\Log\LoggerInterface $creationLogger;
    /** Nextcloud's file name rules, as a default install applies them. */
    protected FakeFilenameValidator $filenames;
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
        $this->apps->method('isEnabledForAnyone')->willReturnCallback(fn (string $app): bool => $this->ocrActive && $app === 'workflow_ocr');
        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('alice');
        $this->users = $this->createMock(IUserManager::class);
        $this->users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $this->user : null);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->time->method('getTime')->willReturn(1790000000);
        $db = $this->createMock(IDBConnection::class);
        $this->batches = new InMemoryBatchStore($db);
        $db->method('escapeLikeParameter')->willReturnCallback(fn (string $s) => addcslashes($s, '\\_%'));
        $this->config = new InMemoryConfig();
        $config = $this->config->mock($this);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(function (string $route, array $args = []): string {
            $this->issued[$route][] = (string)($args['token'] ?? '');
            return 'https://cloud.test/apps/mcp/' . ($args['token'] ?? '');
        });

        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $extractor = new TextExtractor($this->temp);
        $this->locks = new FakeLockManager();
        $locks = $this->locks->service($this);
        $backup = new FileBackup($this->apps, $this->users, $this->time, $config, $this->visibilityGuard, $locks);
        $this->previewManager = $this->createMock(IPreview::class);
        $this->tagManager = $this->createMock(ISystemTagManager::class);
        $this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->filenames = new FakeFilenameValidator();
        $this->creationLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $checkout = new CheckoutService($urls, $config, $this->time, new TokenHasher($config), $this->store, $this->apps, $this->users);
        $imageTools = new ImageTools(
            $this->previewManager,
            $access,
            $config,
            $this->tagManager,
            $this->tagMapper,
            $this->users,
            $this->logger,
            $this->createMock(\Psr\Container\ContainerInterface::class),
            $db,
            $this->visibilityGuard,
        );
        $this->module = new FilesModule(
            $root,
            $extractor,
            $backup,
            $this->users,
            $db,
            $access,
            new SharedWriteGuard($access),
            $checkout,
            new VersionTools($this->apps, $this->users, $extractor, $backup, $access, $this->createMock(\Psr\Container\ContainerInterface::class), new OcrSupport($this->apps), $locks),
            new Reorganization($access, new SharedWriteGuard($access), $this->report(), $this->users, $this->visibilityGuard, $locks),
            new MovePlanner(new Reorganization($access, new SharedWriteGuard($access), $this->report(), $this->users, $this->visibilityGuard, $locks), $access, new SharedWriteGuard($access), $this->visibilityGuard),
            $this->batchStore ?? $this->batches,
            $this->time,
            $imageTools,
            new OcrSupport($this->apps),
            $this->visibilityGuard,
            ...$this->sharing($urls),
            creation: new FileCreation($access, new SharedWriteGuard($access), $checkout, $this->filenames,
                $this->visibilityGuard ?? new \OCA\Mcp\Service\VisibilityGuard($config, $this->tagMapper), $this->creationLogger),
            locks: $locks,
        );
    }

    /**
     * The sharing services of files_list_shares, files_share and files_unshare over {@see self::$shares}, with the same tree and guard.
     *
     * The accounts and groups they know are {@see self::$people} and {@see self::$groups}, read at call time so a test
     * can add one; the grants are in {@see self::$sharePolicy}, all denied but read, as on a fresh install.
     *
     * @return array{shareLister: \OCA\Mcp\Tools\Files\Sharing\ShareLister, shareWriter: \OCA\Mcp\Tools\Files\Sharing\ShareWriter, shareRemover: \OCA\Mcp\Tools\Files\Sharing\ShareRemover}
     */
    private function sharing(IURLGenerator $urls): array {
        $this->shares = new \OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares($this);
        $this->sharePolicy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($this->config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $accounts = $this->createMock(IUserManager::class);
        $accounts->method('get')->willReturnCallback(function (string $uid): ?IUser {
            if (!isset($this->people[$uid])) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('getDisplayName')->willReturn($this->people[$uid]);
            return $user;
        });
        $groups = $this->createMock(\OCP\IGroupManager::class);
        $groups->method('get')->willReturnCallback(function (string $gid): ?\OCP\IGroup {
            if (!isset($this->groups[$gid])) {
                return null;
            }
            $group = $this->createMock(\OCP\IGroup::class);
            $group->method('getGID')->willReturn($gid);
            $group->method('getDisplayName')->willReturn($this->groups[$gid]['name']);
            $group->method('inGroup')->willReturnCallback(fn (IUser $user): bool => in_array($user->getUID(), $this->groups[$gid]['members'], true));
            return $group;
        });
        $groups->method('getUserGroupIds')->willReturnCallback(fn (IUser $user): array => array_keys(array_filter(
            $this->groups, fn (array $group): bool => in_array($user->getUID(), $group['members'], true))));
        $manager = $this->shares->manager();
        $access = new \OCA\Mcp\Tools\Files\Sharing\ShareAccess($manager, $this->sharePolicy, $this->visibilityGuard ?? $this->shares->guardShowingAll());
        $recipients = new \OCA\Mcp\Tools\Files\Sharing\ShareRecipientResolver($accounts, $groups);
        $formatter = new \OCA\Mcp\Tools\Files\Sharing\ShareFormatter($recipients, $urls);
        $this->shareLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $states = new \OCA\Mcp\Tools\PlanState($this->config->mock($this));
        return [
            'shareLister' => new \OCA\Mcp\Tools\Files\Sharing\ShareLister($access, $formatter),
            'shareWriter' => new \OCA\Mcp\Tools\Files\Sharing\ShareWriter($access, $recipients, $formatter, $manager, $accounts, $groups,
                new \OCA\Mcp\Service\UserTimezone($this->config->mock($this)), $this->time, $this->shareLogger, $this->linkPassword(), $states),
            'shareRemover' => new \OCA\Mcp\Tools\Files\Sharing\ShareRemover($access, $recipients, $manager, $this->shareLogger, $states),
        ];
    }

    /**
     * The link password generator over {@see self::$linkPasswords}, {@see self::$refuseAllPasswords} and
     * {@see self::$passwordPolicyFailure}, read at call time, with a reproducible ISecureRandom as the fallback and the
     * logger of the share writer.
     */
    private function linkPassword(): \OCA\Mcp\Tools\Files\Sharing\LinkPassword {
        $events = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
            if ($event instanceof \OCP\Security\Events\GenerateSecurePasswordEvent && $this->linkPasswords !== []) {
                $event->setPassword(array_shift($this->linkPasswords));
            }
            if ($event instanceof \OCP\Security\Events\ValidatePasswordPolicyEvent && $this->passwordPolicyFailure !== null) {
                throw ($this->passwordPolicyFailure)($event->getPassword());
            }
            if ($event instanceof \OCP\Security\Events\ValidatePasswordPolicyEvent && $this->refuseAllPasswords) {
                throw new \OCP\HintException('Password is too weak', 'Password is too weak');
            }
        });
        return new \OCA\Mcp\Tools\Files\Sharing\LinkPassword($events, new \OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeSecureRandom(), $this->shareLogger);
    }

    /** The post-condition report, wired against the same version and share doubles the module uses. */
    protected function report(): MoveReport {
        $manager = new class {
            /** @return list<object> an empty version list */
            public function getVersionsForFile(): array {
                return [];
            }
        };
        return new MoveReport($this->createMock(\Psr\Container\ContainerInterface::class), $this->tree->shareManager(), $this->apps);
    }

    /**
     * Runs a confirmed tool the way the registry does: the published schema first, then the module.
     *
     * The registry is what refuses an unconfirmed write, and {@see WriteGateContractTest} covers that refusal
     * for every module; here the call goes straight to the module so a test can state what the write does
     * without repeating the confirmation on every line. The tools of {@see self::STATEFUL_PLANS} get the plan_state of
     * their plan, read just before, unless the test gives one.
     *
     * A plan that refuses never answers for the confirmed call: production sends the confirmed call straight to the
     * write, which has to refuse on its own. So a refused plan gives {@see self::UNPLANNED_STATE}, and the write is
     * what the test sees — a write that skipped a check would answer "the plan changed" or write, never the refusal.
     */
    protected function tool(string $name, array $arguments = []): array {
        if (in_array($name, self::STATEFUL_PLANS, true) && !array_key_exists(\OCA\Mcp\Tools\PlanState::ARGUMENT, $arguments)) {
            // These confirm only the state their plan showed: like the model, the test reads the plan first and gives
            // its plan_state back. A test about a changed state or a missing one passes plan_state itself.
            try {
                $state = $this->plan($name, $arguments)[\OCA\Mcp\Tools\PlanState::ARGUMENT];
            } catch (ToolFailure|\InvalidArgumentException) {
                $state = self::UNPLANNED_STATE;
            }
            $arguments[\OCA\Mcp\Tools\PlanState::ARGUMENT] = $state;
        }
        return $this->module->call($name, $this->validated($name, $arguments), 'alice');
    }

    /** @return string the plan_state the plan of a stateful write gives right now, for a test that changes things after it */
    protected function stateOf(string $name, array $arguments): string {
        return $this->plan($name, $arguments)[\OCA\Mcp\Tools\PlanState::ARGUMENT];
    }

    /** @return array<string, mixed> the decoded JSON of the plan of a write */
    protected function plan(string $name, array $arguments = []): array {
        return $this->module->preview($name, $this->validated($name, $arguments), 'alice');
    }

    /**
     * @param string $name tool name
     * @param array<string, mixed> $arguments arguments of the test
     * @return array<string, mixed> the arguments validated against the schema the client sees
     */
    protected function validated(string $name, array $arguments): array {
        foreach ($this->module->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return ArgumentValidator::validate(WriteGate::publish($definition)['inputSchema'], $arguments);
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
