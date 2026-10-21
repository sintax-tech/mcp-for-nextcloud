<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Files;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Modules\Files\Checkout\CheckoutService;
use OCA\Mcp\Modules\Files\Checkout\CheckoutTokenStore;
use OCA\Mcp\Modules\Files\Checkout\FileCheckout;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Modules\Files\Editing\ContentPlan;
use OCA\Mcp\Modules\Files\Editing\ContentWriter;
use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Modules\Files\Editing\TextFileEditor;
use OCA\Mcp\Modules\Files\Editing\TextReplacer;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Images\ImageMetadata;
use OCA\Mcp\Modules\Files\Images\ImageRenderer;
use OCA\Mcp\Modules\Files\Images\ImageSearch;
use OCA\Mcp\Modules\Files\Images\ImageSelector;
use OCA\Mcp\Modules\Files\Images\ImageViewer;
use OCA\Mcp\Modules\Files\Reading\DirectoryReader;
use OCA\Mcp\Modules\Files\Reading\FileEntry;
use OCA\Mcp\Modules\Files\Reading\FileReader;
use OCA\Mcp\Modules\Files\Reading\OcrSupport;
use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Modules\Files\Reading\TreeReader;
use OCA\Mcp\Modules\Files\Reorganization\BatchMover;
use OCA\Mcp\Modules\Files\Reorganization\BatchStore;
use OCA\Mcp\Modules\Files\Reorganization\BatchUndo;
use OCA\Mcp\Modules\Files\Reorganization\BatchVisibility;
use OCA\Mcp\Modules\Files\Reorganization\CopyMove;
use OCA\Mcp\Modules\Files\Reorganization\FolderCreator;
use OCA\Mcp\Modules\Files\Reorganization\FolderPreparation;
use OCA\Mcp\Modules\Files\Reorganization\MoveExecution;
use OCA\Mcp\Modules\Files\Reorganization\MoveInspection;
use OCA\Mcp\Modules\Files\Reorganization\MovePlanner;
use OCA\Mcp\Modules\Files\Reorganization\MoveReport;
use OCA\Mcp\Modules\Files\Reorganization\TreeMeasure;
use OCA\Mcp\Modules\Files\Search\FileSearch;
use OCA\Mcp\Modules\Files\Sharing\LinkPassword;
use OCA\Mcp\Modules\Files\Sharing\ShareAccess;
use OCA\Mcp\Modules\Files\Sharing\ShareFormatter;
use OCA\Mcp\Modules\Files\Sharing\ShareLister;
use OCA\Mcp\Modules\Files\Sharing\ShareRecipientResolver;
use OCA\Mcp\Modules\Files\Sharing\ShareRemover;
use OCA\Mcp\Modules\Files\Sharing\ShareWriter;
use OCA\Mcp\Modules\Files\Tools\CheckoutFile;
use OCA\Mcp\Modules\Files\Tools\CopyFile;
use OCA\Mcp\Modules\Files\Tools\CreateFile;
use OCA\Mcp\Modules\Files\Tools\EditFile;
use OCA\Mcp\Modules\Files\Tools\ListFiles;
use OCA\Mcp\Modules\Files\Tools\ListShares;
use OCA\Mcp\Modules\Files\Tools\ListVersions;
use OCA\Mcp\Modules\Files\Tools\MakeDirectory;
use OCA\Mcp\Modules\Files\Tools\MoveBatch;
use OCA\Mcp\Modules\Files\Tools\MoveFile;
use OCA\Mcp\Modules\Files\Tools\ReadFile;
use OCA\Mcp\Modules\Files\Tools\ReadTree;
use OCA\Mcp\Modules\Files\Tools\ReadVersion;
use OCA\Mcp\Modules\Files\Tools\ReplaceText;
use OCA\Mcp\Modules\Files\Tools\RestoreVersion;
use OCA\Mcp\Modules\Files\Tools\SearchFiles;
use OCA\Mcp\Modules\Files\Tools\SearchImages;
use OCA\Mcp\Modules\Files\Tools\ShareFile;
use OCA\Mcp\Modules\Files\Tools\UndoBatch;
use OCA\Mcp\Modules\Files\Tools\UnshareFile;
use OCA\Mcp\Modules\Files\Tools\UploadFile;
use OCA\Mcp\Modules\Files\Tools\ViewImage;
use OCA\Mcp\Modules\Files\Tools\ViewImages;
use OCA\Mcp\Modules\Files\Versions\VersionReader;
use OCA\Mcp\Modules\Files\Versions\VersionRestorer;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Files\Checkout\InMemoryCheckoutTokenStore;
use OCA\Mcp\Tests\Support\Files\Sharing\FakeSecureRandom;
use OCA\Mcp\Tests\Support\Files\Sharing\FakeShares;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLockManager;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
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
    protected ?BatchStore $batchStore = null;
    protected ITempManager $temp;
    protected InMemoryConfig $config;
    protected IPreview $previewManager;
    protected ISystemTagManager $tagManager;
    protected ISystemTagObjectMapper $tagMapper;
    protected \Psr\Log\LoggerInterface $logger;
    protected FilesModule $module;
    /** files_lock behind ILockManager, without any lock until a test puts one. */
    protected FakeLockManager $locks;
    protected ?VisibilityGuard $visibilityGuard = null;
    /** @var list<string> tokens handed out per route, in order */
    protected array $issued = [];
    /** @var array<string, string> accounts the sharing tools know, uid => display name */
    protected array $people = ['alice' => 'Alice', 'bruno' => 'Bruno Lima', 'carla' => 'Carla Dias'];
    /** @var array<string, array{name:string, members:list<string>}> groups the sharing tools know, by gid */
    protected array $groups = ['finance' => ['name' => 'Financeiro', 'members' => ['alice', 'bruno']], 'board' => ['name' => 'Diretoria', 'members' => ['carla']]];
    /** Grants of the sharing tools: read only until a test allows share or link. */
    protected GrantPolicy $sharePolicy;
    /** @var list<string> passwords the password_policy generator hands out for links, in order; empty means no generator */
    protected array $linkPasswords = [];
    /** Whether the password policy refuses every password, as one no generated password can meet. */
    protected bool $refuseAllPasswords = false;
    /** @var (\Closure(string): \Throwable)|null what a broken policy listener throws for the candidate it validates, null for none */
    protected ?\Closure $passwordPolicyFailure = null;
    /** Logger of the share writer, to check that only the exception class is logged. */
    protected \Psr\Log\LoggerInterface $shareLogger;
    /** Shares alice created, behind the IShareManager double of files_list_shares. */
    protected FakeShares $shares;
    /** Logger of files_upload and files_create, to check that a failed write logs the exception class only. */
    protected \Psr\Log\LoggerInterface $creationLogger;
    /** Nextcloud's file name rules, as a default install applies them. */
    protected FakeFilenameValidator $filenames;
    /** @var list<string> apps enabled for alice; a test can empty it to simulate files_versions being off */
    protected array $enabled = ['files_versions'];

    /** Root lookup follows the current tree when a scenario rebuilds its fixtures. */
    protected function visibilityRoot(): \OCP\Files\IRootFolder {
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
        return $root;
    }

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
        $this->visibilityGuard ??= \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $config, root: $this->visibilityRoot());
        $backup = new FileBackup($this->apps, $this->users, $this->time, $config, $this->visibilityGuard, $locks);
        $this->previewManager = $this->createMock(IPreview::class);
        $this->tagManager = $this->createMock(ISystemTagManager::class);
        $this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->filenames = new FakeFilenameValidator();
        $this->creationLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $checkout = new CheckoutService($urls, $config, $this->time, new TokenHasher($config), $this->store, $this->apps, $this->users);
        $imageTools = new ImageViewer($config, new ImageSelector($this->users, $this->visibilityGuard), new ImageRenderer($this->previewManager, $this->logger), new ImageMetadata($access, $this->tagManager, $this->tagMapper, $this->users, fn () => ($this->createMock(\Psr\Container\ContainerInterface::class))->get(ImageMetadata::METADATA_SERVICE)));
        $imageSearch = new ImageSearch(new ImageMetadata($access, $this->tagManager, $this->tagMapper, $this->users, fn () => ($this->createMock(\Psr\Container\ContainerInterface::class))->get(ImageMetadata::METADATA_SERVICE)), $this->tagManager, $this->tagMapper, $this->users, $this->logger, $db, $this->visibilityGuard);
        $fileVisibility = $this->visibilityGuard ?? \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $config, $this->tagMapper, $this->visibilityRoot());
        $sharing = $this->sharing($urls);
        $this->services = [
            'rootFolder' => $root,
            'extractor' => $extractor,
            'backup' => $backup,
            'accessInfo' => $access,
            'guard' => new SharedWriteGuard($access),
            'locks' => $locks,
            'visibilityGuard' => $this->visibilityGuard,
            'versions' => new VersionReader($this->apps, $this->users, $extractor, $access, fn () => ($this->createMock(\Psr\Container\ContainerInterface::class))->get(VersionReader::MANAGER), new OcrSupport($this->apps)),
            'checkout' => $checkout,
            'timezone' => null,
        ];
        $this->toolFactories = [
            'files_read' => fn () => new ReadFile(new FileReader($root, $extractor, $access, new OcrSupport($this->apps), $fileVisibility)),
            'files_edit' => fn () => new EditFile(new TextFileEditor($root, $extractor, new ContentWriter($backup, $access, $fileVisibility, $locks), new ContentPlan($access, new SharedWriteGuard($access), $locks), new SharedWriteGuard($access), $fileVisibility, $locks)),
            'files_list' => fn () => new ListFiles($root, new DirectoryReader(new FileEntry($access), $this->visibilityGuard)),
            'files_search' => fn () => new SearchFiles($root, new FileSearch($db, $this->users, new FileEntry($access), $this->visibilityGuard, null, null)),
            'files_tree' => fn () => new ReadTree($root, new TreeReader($access, $this->visibilityGuard)),
            'files_replace' => fn () => new ReplaceText(new TextReplacer($root, $extractor, new ContentWriter($backup, $access, $this->visibilityGuard, $locks), new ContentPlan($access, new SharedWriteGuard($access), $locks), new SharedWriteGuard($access), $this->visibilityGuard, $locks)),
            'files_versions_list' => fn () => new ListVersions($root, new VersionReader($this->apps, $this->users, $extractor, $access, fn () => ($this->createMock(\Psr\Container\ContainerInterface::class))->get(VersionReader::MANAGER), new OcrSupport($this->apps)), $this->visibilityGuard),
            'files_version_read' => fn () => new ReadVersion($root, new VersionReader($this->apps, $this->users, $extractor, $access, fn () => ($this->createMock(\Psr\Container\ContainerInterface::class))->get(VersionReader::MANAGER), new OcrSupport($this->apps)), $this->visibilityGuard),
            'files_version_restore' => fn () => new RestoreVersion($root, new VersionRestorer(new VersionReader($this->apps, $this->users, $extractor, $access, fn () => ($this->createMock(\Psr\Container\ContainerInterface::class))->get(VersionReader::MANAGER), new OcrSupport($this->apps)), $backup, $access, new SharedWriteGuard($access), $this->visibilityGuard, $locks, null)),
            'files_checkout' => fn () => new CheckoutFile(new FileCheckout($root, $checkout, $access, new SharedWriteGuard($access), $this->visibilityGuard, $locks)),
            'files_upload' => fn () => new UploadFile($root, new FileCreation($access, new SharedWriteGuard($access), $checkout, $this->filenames,
                $this->visibilityGuard ?? \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $config, $this->tagMapper, $this->visibilityRoot()), $this->creationLogger, $locks)),
            'files_create' => fn () => new CreateFile($root, new FileCreation($access, new SharedWriteGuard($access), $checkout, $this->filenames,
                $this->visibilityGuard ?? \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $config, $this->tagMapper, $this->visibilityRoot()), $this->creationLogger, $locks)),
            'files_image_view' => fn () => new ViewImage($root, $imageTools),
            'files_images_view' => fn () => new ViewImages($root, $imageTools),
            'files_image_search' => fn () => new SearchImages($root, $imageSearch),
            'files_list_shares' => fn () => new ListShares($root, $sharing['shareLister']),
            'files_share' => fn () => new ShareFile($root, $sharing['shareWriter']),
            'files_unshare' => fn () => new UnshareFile($root, $sharing['shareRemover']),
            'files_mkdir' => fn () => new MakeDirectory($root, new FolderCreator($access, new SharedWriteGuard($access), new MoveInspection($access, new SharedWriteGuard($access), $this->visibilityGuard, $locks), $this->visibilityGuard)),
            'files_copy' => fn () => new CopyFile($root, new CopyMove($access, $this->report(), $this->users, new MoveInspection($access, new SharedWriteGuard($access), $this->visibilityGuard, $locks), new TreeMeasure($this->visibilityGuard), new MoveExecution($this->visibilityGuard, $locks), $this->visibilityGuard, $locks)),
            'files_move' => fn () => new MoveFile($root, new CopyMove($access, $this->report(), $this->users, new MoveInspection($access, new SharedWriteGuard($access), $this->visibilityGuard, $locks), new TreeMeasure($this->visibilityGuard), new MoveExecution($this->visibilityGuard, $locks), $this->visibilityGuard, $locks)),
            'files_move_batch' => fn () => new MoveBatch($root, new BatchMover(new MoveInspection($access, new SharedWriteGuard($access), $this->visibilityGuard, $locks), new FolderPreparation($this->visibilityGuard), new MoveExecution($this->visibilityGuard, $locks), new BatchVisibility($this->visibilityGuard), $this->visibilityGuard), $this->batchStore ?? $this->batches, $this->time, new MovePlanner(new MoveInspection($access, new SharedWriteGuard($access), $this->visibilityGuard, $locks), new FolderPreparation($this->visibilityGuard), $access, new SharedWriteGuard($access), $this->visibilityGuard)),
            'files_undo_batch' => fn () => new UndoBatch($root, new BatchUndo(new MoveInspection($access, new SharedWriteGuard($access), $this->visibilityGuard, $locks), new FolderPreparation($this->visibilityGuard), new MoveExecution($this->visibilityGuard, $locks), new BatchVisibility($this->visibilityGuard), $this->visibilityGuard), $this->batchStore ?? $this->batches, $this->time),
        ];
        $this->module = new FilesModule($this->toolFactories, ($this->visibilityGuard)->assertScopeReadable(...));
    }

    /**
     * The sharing services of files_list_shares, files_share and files_unshare over {@see self::$shares}, with the same tree and guard.
     *
     * The accounts and groups they know are {@see self::$people} and {@see self::$groups}, read at call time so a test
     * can add one; the grants are in {@see self::$sharePolicy}, all denied but read, as on a fresh install.
     *
     * @return array{shareLister: \OCA\Mcp\Modules\Files\Sharing\ShareLister, shareWriter: \OCA\Mcp\Modules\Files\Sharing\ShareWriter, shareRemover: \OCA\Mcp\Modules\Files\Sharing\ShareRemover}
     */
    private function sharing(IURLGenerator $urls): array {
        $this->shares = new FakeShares($this);
        $this->sharePolicy = InMemoryConfig::policy($this->config->mock($this), new InMemoryOAuthStore());
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
        $access = new ShareAccess($manager, $this->sharePolicy, $this->visibilityGuard ?? $this->shares->guardShowingAll());
        $recipients = new ShareRecipientResolver($accounts, $groups);
        $formatter = new ShareFormatter($recipients, $urls);
        $this->shareLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $states = new PlanState($this->config->mock($this));
        return [
            'shareLister' => new ShareLister($access, $formatter),
            'shareWriter' => new ShareWriter($access, $recipients, $formatter, $manager, $accounts, $groups,
                new UserTimezone($this->config->mock($this)), $this->time, $this->shareLogger, $this->linkPassword(), $states),
            'shareRemover' => new ShareRemover($access, $recipients, $manager, $this->shareLogger, $states),
        ];
    }

    /**
     * The link password generator over {@see self::$linkPasswords}, {@see self::$refuseAllPasswords} and
     * {@see self::$passwordPolicyFailure}, read at call time, with a reproducible ISecureRandom as the fallback and the
     * logger of the share writer.
     */
    private function linkPassword(): LinkPassword {
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
        return new LinkPassword($events, new FakeSecureRandom(), $this->shareLogger);
    }

    /** The post-condition report, wired against the same version and share doubles the module uses. */
    protected function report(): MoveReport {
        $manager = new class {
            /** @return list<object> an empty version list */
            public function getVersionsForFile(): array {
                return [];
            }
        };
        return new MoveReport(static fn () => null, $this->tree->shareManager(), $this->apps);
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
        if (in_array($name, self::STATEFUL_PLANS, true) && !array_key_exists(PlanState::ARGUMENT, $arguments)) {
            // These confirm only the state their plan showed: like the model, the test reads the plan first and gives
            // its plan_state back. A test about a changed state or a missing one passes plan_state itself.
            try {
                $state = $this->plan($name, $arguments)[PlanState::ARGUMENT];
            } catch (ToolFailure|\InvalidArgumentException) {
                $state = self::UNPLANNED_STATE;
            }
            $arguments[PlanState::ARGUMENT] = $state;
        }
        return $this->module->call($name, $this->validated($name, $arguments), 'alice');
    }

    /** @return string the plan_state the plan of a stateful write gives right now, for a test that changes things after it */
    protected function stateOf(string $name, array $arguments): string {
        return $this->plan($name, $arguments)[PlanState::ARGUMENT];
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
    /**
     * @param array<string, object> $replace constructor arguments to swap, by parameter name
     * @return FilesModule the module of the base case with those collaborators replaced
     */
    private array $services = [];
    private array $toolFactories = [];

    protected function rebuilt(array $replace): FilesModule {
        $get = fn (string $name) => $this->services[$name];
        if (isset($replace['backup'])) {
            $visibility = $this->visibilityGuard ?? \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $this->config->mock($this), $this->tagMapper, $this->visibilityRoot());
            $replace['editFile'] = new EditFile(new TextFileEditor($get('rootFolder'), $get('extractor'), new ContentWriter($replace['backup'], $get('accessInfo'), $visibility, $get('locks')), new ContentPlan($get('accessInfo'), $get('guard'), $get('locks')), $get('guard'), $visibility, $get('locks')));
        }
        $value = fn (string $name) => $replace[$name] ?? $get($name);
        $replace['replaceText'] = new ReplaceText(new TextReplacer(
            $value('rootFolder'), $value('extractor'),
            new ContentWriter($value('backup'), $value('accessInfo'), $value('visibilityGuard'), $value('locks')),
            new ContentPlan($value('accessInfo'), $value('guard'), $value('locks')),
            $value('guard'), $value('visibilityGuard'), $value('locks'),
        ));
        $replace['restoreVersion'] = new RestoreVersion($value('rootFolder'), new VersionRestorer($value('versions'), $value('backup'), $value('accessInfo'), $value('guard'), $value('visibilityGuard'), $value('locks'), $value('timezone')));
        $replace['checkoutFile'] = new CheckoutFile(new FileCheckout(
            $value('rootFolder'), $value('checkout'), $value('accessInfo'), $value('guard'), $value('visibilityGuard'), $value('locks'),
        ));
        $factories = $this->toolFactories;
        foreach ($replace as $service) {
            if ($service instanceof Tool) {
                $factories[$service::definition()->name] = static fn () => $service;
            }
        }
        return new FilesModule($factories, ($this->visibilityGuard)->assertScopeReadable(...));
    }
}
