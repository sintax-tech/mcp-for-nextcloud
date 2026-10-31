<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Files_Versions\Versions\FakeVersion;
use OCA\Files_Versions\Versions\FakeVersionManager;
use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Service\FileWritePolicy;
use OCA\Mcp\Service\FileWritePolicyUnavailable;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLock;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\Common\FileTypeDenied;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCA\Mcp\Tools\Common\FileTypeMessages;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\BatchStore;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\ContentCommit;
use OCA\Mcp\Tools\Files\ContentTypeGate;
use OCA\Mcp\Tools\Files\ContentWritePlan;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\Handler\EditFileHandler;
use OCA\Mcp\Tools\Files\Handler\ReplaceFileHandler;
use OCA\Mcp\Tools\Files\Handler\RestoreVersionHandler;
use OCA\Mcp\Tools\Files\ImageTools;
use OCA\Mcp\Tools\Files\MovePlanner;
use OCA\Mcp\Tools\Files\MoveReport;
use OCA\Mcp\Tools\Files\OcrSupport;
use OCA\Mcp\Tools\Files\Reorganization;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Files\VersionRestore;
use OCA\Mcp\Tools\Files\VersionTools;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Lock\ILock;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * File-type policy on files_edit, files_replace and files_version_restore.
 *
 * The guard is a real {@see FileTypeGuard}. Mode off is an absent key, never a null guard.
 * Confirm, confirm_shared and the user id do not bypass it. {@see FileBackup} is not asked to read it.
 */
final class FileContentTypePolicyTest extends TestCase {
	private const MANAGER = 'OCA\Files_Versions\Versions\IVersionManager';

	private const ROOT = '/alice/files';

	private FakeTree $tree;

	private InMemoryAppConfig $appConfig;

	private FileWritePolicy $policy;

	private FileTypeGuard $guard;

	private ContentTypeGate $gate;

	private FilesModule $module;

	private FakeVersionManager $manager;

	private ?FakeLockManager $lockManager = null;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/Stubs/versions_api.php';
		$open = AbsentFileTypePolicy::open($this);
		$this->appConfig = $open['config'];
		$this->policy = $open['policy'];
		$this->guard = $open['guard'];
		$this->gate = $open['gate'];
		$this->build('none');
	}

	/**
	 * @param list<string> $extensions Extensions already accepted by the canonical allowlist.
	 * @return void
	 */
	private function allow(array $extensions): void {
		$this->policy->save('allowlist', $extensions, false);
	}

	/**
	 * @param string $locks none, on (provider present) or off (provider absent).
	 * @return void
	 */
	private function build(string $locks): void {
		$this->tree = new FakeTree($this);
		$this->manager = FakeVersionManager::with([]);
		$this->lockManager = $locks === 'none' ? null : new FakeLockManager();
		if ($this->lockManager !== null) {
			$this->lockManager->available = $locks === 'on';
		}
		$lockService = $this->lockManager?->service($this);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$clock = new InMemoryConfig();
		$clock->user['alice']['core']['timezone'] = 'America/Sao_Paulo';
		$iConfig = $clock->mock($this);
		$db = $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => tempnam(sys_get_temp_dir(), 'mcp'));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/apps/mcp/token');

		$access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
		$extractor = new TextExtractor($temp);
		$shared = new SharedWriteGuard($access);
		$backup = new FileBackup($apps, $users, $time, $iConfig, null, $lockService);
		$commit = new ContentCommit($this->gate, $access, $lockService, null);
		$plans = new ContentWritePlan($shared, $access, $lockService);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): ?object => $id === self::MANAGER ? $this->manager : null);
		$versionRestore = new VersionRestore($apps, $users, $this->gate, $access, $container, $lockService, null);
		$versions = new VersionTools($apps, $users, $extractor, $backup, $access, $container, new OcrSupport($apps), $lockService, null, $versionRestore);
		$report = new MoveReport($this->createMock(ContainerInterface::class), $this->tree->shareManager(), $apps);
		$reorganization = new Reorganization($access, new SharedWriteGuard($access), $report, $users);
		$imageTools = new ImageTools(
			$this->createMock(\OCP\IPreview::class),
			$access,
			$iConfig,
			$this->createMock(\OCP\SystemTag\ISystemTagManager::class),
			$this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class),
			$users,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			$db,
		);
		$this->module = new FilesModule(
			$this->tree->filesystemRoot(),
			$extractor,
			$backup,
			$users,
			$db,
			$access,
			$shared,
			new CheckoutService($urls, $iConfig, $time, new TokenHasher($iConfig), $this->createMock(CheckoutTokenStore::class), $apps, $users),
			$versions,
			$reorganization,
			new MovePlanner($reorganization, $access, new SharedWriteGuard($access)),
			new BatchStore($db),
			$time,
			$imageTools,
			new OcrSupport($apps),
			locks: $lockService,
			timezone: new UserTimezone($iConfig),
			editFile: new EditFileHandler($extractor, $shared, $plans, $commit, $this->gate, $lockService, null),
			replaceFile: new ReplaceFileHandler($extractor, $shared, $plans, $commit, $this->gate, $lockService, null),
			restoreVersion: new RestoreVersionHandler($access, $shared, $this->gate, $lockService, null),
		);
	}

	/**
	 * @param string $name Path under the user root, with no leading slash.
	 * @param string $content Initial bytes.
	 * @param string $mime MIME type stored on the node.
	 * @return string User-relative path.
	 */
	private function put(string $name, string $content, string $mime = 'text/plain'): string {
		$this->tree->addFile(self::ROOT . '/' . $name, $content, $mime);
		return '/' . $name;
	}

	/**
	 * @param string $userPath User-relative path.
	 * @return array{content: string, etag: string}
	 */
	private function node(string $userPath): array {
		$stored = $this->tree->nodes[self::ROOT . $userPath];
		return ['content' => $stored['content'], 'etag' => $stored['etag']];
	}

	/**
	 * @return array<string, array<string, mixed>> Backup nodes keyed by their full path.
	 */
	private function backups(): array {
		return array_filter(
			$this->tree->nodes,
			static fn (array $node, string $path): bool => str_contains($path, '/MCP backups/') && ($node['type'] ?? '') === 'file',
			ARRAY_FILTER_USE_BOTH,
		);
	}

	/**
	 * @param string $userPath User-relative path that must stay as it was.
	 * @param string $content Bytes before the refused call.
	 * @param string $etag ETag before the refused call.
	 * @return void
	 */
	private function assertUntouched(string $userPath, string $content, string $etag): void {
		$stored = $this->node($userPath);
		$this->assertSame($content, $stored['content']);
		$this->assertSame($etag, $stored['etag']);
		$this->assertSame([], $this->tree->ops);
		$this->assertSame([], $this->backups());
	}

	/**
	 * @param string $name Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string $userId Authenticated user.
	 * @return ToolFailure The refusal.
	 */
	private function failure(string $name, array $arguments, string $userId = 'alice'): ToolFailure {
		try {
			$this->module->call($name, $arguments, $userId);
		} catch (ToolFailure $e) {
			return $e;
		}
		$this->fail($name . ' did not fail');
	}

	/**
	 * @param string $name Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return ToolFailure The refusal of the plan.
	 */
	private function planFailure(string $name, array $arguments): ToolFailure {
		try {
			$this->module->preview($name, $arguments, 'alice');
		} catch (ToolFailure $e) {
			return $e;
		}
		$this->fail($name . ' plan did not fail');
	}

	/**
	 * @param string $name Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string $userId Authenticated user.
	 * @return array<string, mixed> Decoded tool JSON.
	 */
	private function json(string $name, array $arguments, string $userId = 'alice'): array {
		$result = $this->module->call($name, $arguments, $userId);
		$decoded = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);
		return $decoded;
	}

	/**
	 * An allowed edit plans without writing, then backs up the original and writes the new bytes.
	 *
	 * @return void
	 */
	public function testEditOfAnAllowedNameBacksUpThenWrites(): void {
		$this->allow(['txt']);
		$path = $this->put('a.txt', 'alpha');
		$before = $this->node($path);
		$plan = $this->module->preview('files_edit', ['path' => $path, 'content' => 'beta'], 'alice');

		$this->assertSame(['action', 'size', 'diff', 'message', 'path', 'etag', 'backup', 'access', 'shared', 'requiresSharedConfirmation', 'recoverable', 'consequence'], array_keys($plan));
		$this->assertSame('files_edit', $plan['action']);
		$this->assertSame('/' . FileBackup::FOLDER, $plan['backup']);
		$this->assertUntouched($path, 'alpha', $before['etag']);

		$out = $this->json('files_edit', ['path' => $path, 'content' => 'beta']);
		$stored = $this->node($path);
		$this->assertSame('beta', $stored['content']);
		$this->assertNotSame($before['etag'], $stored['etag']);
		$this->assertStringEndsWith('.bak', $out['backup']);
		$this->assertStringStartsWith('/MCP backups/', $out['backup']);
		$copies = $this->backups();
		$this->assertCount(1, $copies);
		$this->assertSame('alpha', array_values($copies)[0]['content']);
		$this->assertStringEndsWith('.bak', (string)array_key_first($copies));
	}

	/**
	 * The lock-aware branch writes through the file after the same backup. The provider is present and the file is free.
	 *
	 * @return void
	 */
	public function testEditWithALockProviderWritesThroughTheFile(): void {
		$this->build('on');
		$this->allow(['txt']);
		$path = $this->put('a.txt', 'alpha');
		$out = $this->json('files_edit', ['path' => $path, 'content' => 'beta']);

		$this->assertSame('beta', $this->node($path)['content']);
		$this->assertStringEndsWith('.bak', $out['backup']);
		$this->assertSame('alpha', array_values($this->backups())[0]['content']);
		$this->assertNotNull($this->lockManager);
		$this->assertSame([], $this->lockManager->forbidden);
	}

	/**
	 * A refused edit, planned or confirmed, creates no backup and does not change the file.
	 *
	 * @return void
	 */
	public function testDeniedEditDoesNotBackUpOrWrite(): void {
		$this->allow(['txt']);
		$path = $this->put('note.md', 'alpha', 'text/markdown');
		$before = $this->node($path);

		$planned = $this->planFailure('files_edit', ['path' => $path, 'content' => 'beta']);
		$this->assertInstanceOf(FileTypeDenied::class, $planned);
		$this->assertSame(FileTypeMessages::denied(), $planned->getMessage());
		$this->assertStringNotContainsString('md', $planned->getMessage());
		$this->assertUntouched($path, 'alpha', $before['etag']);

		$done = $this->failure('files_edit', ['path' => $path, 'content' => 'beta', 'confirm' => true, 'confirm_shared' => true]);
		$this->assertInstanceOf(FileTypeDenied::class, $done);
		$this->assertSame(FileTypeMessages::denied(), $done->getMessage());
		$this->assertUntouched($path, 'alpha', $before['etag']);
	}

	/**
	 * A refused replace does not read the snippet into a write and does not back the file up.
	 *
	 * @return void
	 */
	public function testDeniedReplaceDoesNotBackUpOrWrite(): void {
		$this->allow(['txt']);
		$path = $this->put('note.md', 'alpha beta', 'text/markdown');
		$before = $this->node($path);

		foreach ([true, false] as $planned) {
			$failure = $planned
				? $this->planFailure('files_replace', ['path' => $path, 'old' => 'alpha', 'new' => 'gamma'])
				: $this->failure('files_replace', ['path' => $path, 'old' => 'alpha', 'new' => 'gamma']);
			$this->assertInstanceOf(FileTypeDenied::class, $failure);
			$this->assertSame(FileTypeMessages::denied(), $failure->getMessage());
			$this->assertUntouched($path, 'alpha beta', $before['etag']);
		}
	}

	/**
	 * An allowed replace keeps the snippet result and the backup of the previous bytes.
	 *
	 * @return void
	 */
	public function testReplaceOfAnAllowedNameBacksUpThenWrites(): void {
		$this->allow(['txt']);
		$path = $this->put('a.txt', 'alpha beta');
		$plan = $this->module->preview('files_replace', ['path' => $path, 'old' => 'beta', 'new' => 'gamma'], 'alice');
		$this->assertSame('files_replace', $plan['action']);
		$this->assertSame(['occurrences' => 1, 'replaced' => 1], $plan['snippet']);
		$this->assertSame([], $this->tree->ops);

		$out = $this->json('files_replace', ['path' => $path, 'old' => 'beta', 'new' => 'gamma']);
		$this->assertSame('alpha gamma', $this->node($path)['content']);
		$this->assertSame('alpha beta', array_values($this->backups())[0]['content']);
		$this->assertStringEndsWith('.bak', $out['backup']);
	}

	/**
	 * A missing snippet is reported before the type policy on the plan, including when the name would be denied.
	 *
	 * @return void
	 */
	public function testSnippetErrorsWinOverTypeDenialOnThePlan(): void {
		$this->allow(['txt']);
		$path = $this->put('a.php', 'alpha', 'text/plain');
		$missing = $this->planFailure('files_replace', ['path' => $path, 'old' => 'nope', 'new' => 'x']);
		$this->assertSame(FilesMessages::snippetMissing(), $missing->getMessage());
		$this->assertNotInstanceOf(FileTypeDenied::class, $missing);

		$raw = $this->failure('files_replace', ['path' => $path, 'old' => "\xE3", 'new' => 'x']);
		$this->assertSame(FilesMessages::snippetNotUtf8(), $raw->getMessage());
		$this->assertSame([], $this->tree->ops);
	}

	/**
	 * Text, size, backup-folder and ETag refusals stay ahead of the type policy. A denied name never reaches the backup.
	 *
	 * @return void
	 */
	public function testTextSizeLockAndEtagStayAheadOfThePolicy(): void {
		$this->allow(['pdf', 'png', 'txt']);
		$png = $this->put('a.png', 'PNG', 'image/png');
		$pdf = $this->put('a.pdf', '%PDF', 'application/pdf');
		$pngBefore = $this->node($png);
		$picture = $this->failure('files_edit', ['path' => $png, 'content' => 'x']);
		$this->assertSame(FilesMessages::notText(), $picture->getMessage());
		$this->assertSame(FilesMessages::notText(), $this->planFailure('files_edit', ['path' => $pdf, 'content' => 'x'])->getMessage());
		$this->assertUntouched($png, 'PNG', $pngBefore['etag']);

		$php = $this->put('a.php', '<?php ');
		$huge = $this->failure('files_edit', ['path' => $php, 'content' => str_repeat('x', FilesModule::MAX_EDIT_BYTES + 1)]);
		$this->assertSame(FilesMessages::editTooLarge(FilesModule::MAX_EDIT_BYTES), $huge->getMessage());
		$this->assertSame(FilesMessages::backupPath(), $this->failure('files_edit', ['path' => '/MCP backups/a.txt', 'content' => 'x'])->getMessage());

		$txt = $this->put('a.txt', 'alpha');
		$stalePlan = $this->planFailure('files_edit', ['path' => $php, 'content' => 'x', 'etag' => 'stale']);
		$this->assertSame(CommonMessages::conflict(), $stalePlan->getMessage());
		$staleWrite = $this->failure('files_edit', ['path' => $txt, 'content' => 'beta', 'etag' => 'stale']);
		$this->assertSame(CommonMessages::conflict(), $staleWrite->getMessage());
		$this->assertSame('alpha', $this->node($txt)['content']);
		$this->assertSame([], $this->backups());
	}

	/**
	 * A lock is refused before the type policy, so a denied extension of a locked file still reports the lock.
	 *
	 * @return void
	 */
	public function testLockRefusalPrecedesTheTypePolicy(): void {
		$this->build('on');
		$this->allow(['txt']);
		$path = $this->put('a.php', '<?php ');
		$this->assertNotNull($this->lockManager);
		$this->lockManager->put(new FakeLock($this->tree->nodes[self::ROOT . $path]['id'], ILock::TYPE_APP, 'text'));
		$failure = $this->failure('files_edit', ['path' => $path, 'content' => 'x']);

		$this->assertInstanceOf(LockWriteFailure::class, $failure);
		$this->assertSame([], $this->tree->ops);
		$this->assertSame('<?php ', $this->node($path)['content']);
	}

	/**
	 * The native rollback runs only after the backup, and only when no lock provider is available.
	 *
	 * @return void
	 */
	public function testRestoreWithoutALockProviderRollsBackAfterBackup(): void {
		$this->build('off');
		$this->allow(['txt']);
		$path = $this->put('a.txt', 'current');
		$version = (new FakeVersion('1759100000', 1759100000, 3, 'text/plain', 'old.php'))->withContent('old');
		$this->manager->versions = [$version];
		$this->manager->onRollback = function () use ($path): void {
			$this->tree->nodes[self::ROOT . $path]['content'] = 'old';
			$this->tree->nodes[self::ROOT . $path]['etag'] .= '+';
		};

		$plan = $this->module->preview('files_version_restore', ['path' => $path, 'version' => '1759100000'], 'alice');
		$this->assertSame(
			['action', 'path', 'version', 'timezone', 'current', 'backup', 'access', 'shared', 'requiresSharedConfirmation', 'recoverable', 'consequence', 'message'],
			array_keys($plan),
		);
		$this->assertArrayNotHasKey('etag', $plan);
		$this->assertSame([], $this->tree->ops);

		$out = $this->json('files_version_restore', ['path' => $path, 'version' => '1759100000']);
		$this->assertSame('old', $this->node($path)['content']);
		$this->assertContains('rollback', $this->manager->ops);
		$this->assertNotContains('read', $this->manager->ops);
		$this->assertSame('current', array_values($this->backups())[0]['content']);
		$this->assertStringEndsWith('.bak', $out['backup']);
	}

	/**
	 * With a lock provider, the version is written through the file after its stream is closed, and the core rollback is not used.
	 *
	 * @return void
	 */
	public function testRestoreWithALockProviderWritesTheVersion(): void {
		$this->build('on');
		$this->allow(['txt']);
		$path = $this->put('a.txt', 'current');
		$version = (new FakeVersion('1759100000', 1759100000, 3, 'text/plain', 'old.php'))->withContent('<?php ');
		$this->manager->versions = [$version];
		$this->tree->beforeWrite = function (string $full) use ($version, $path): void {
			if ($full !== self::ROOT . $path) {
				return;
			}
			$this->assertNotEmpty($version->handles);
			foreach ($version->handles as $handle) {
				$this->assertFalse(is_resource($handle));
			}
		};

		$out = $this->json('files_version_restore', ['path' => $path, 'version' => '1759100000']);
		$this->assertSame('<?php ', $this->node($path)['content']);
		$this->assertNotContains('rollback', $this->manager->ops);
		$this->assertContains('read', $this->manager->ops);
		$this->assertSame('current', array_values($this->backups())[0]['content']);
		$this->assertStringEndsWith('.bak', $out['backup']);
	}

	/**
	 * The current basename is authorized. The version's old name and its bytes are not.
	 *
	 * @return void
	 */
	public function testRestoreUsesTheCurrentNameNotTheVersionBytes(): void {
		$this->build('off');
		$this->allow(['txt']);
		$txt = $this->put('a.txt', 'current');
		$this->manager->versions = [(new FakeVersion('1', 1759100000, 5, 'text/plain', 'old.php'))->withContent('<?php ')];
		$this->manager->onRollback = function () use ($txt): void {
			$this->tree->nodes[self::ROOT . $txt]['content'] = '<?php ';
		};
		$this->json('files_version_restore', ['path' => $txt, 'version' => '1']);
		$this->assertSame('<?php ', $this->node($txt)['content']);

		$this->tree = new FakeTree($this);
		$this->build('off');
		$this->allow(['txt']);
		$php = $this->put('a.php', 'current');
		$before = $this->node($php);
		$this->manager->versions = [(new FakeVersion('1', 1759100000, 5, 'text/plain', 'a.txt'))->withContent('plain')];
		$denied = $this->failure('files_version_restore', ['path' => $php, 'version' => '1']);
		$this->assertInstanceOf(FileTypeDenied::class, $denied);
		$this->assertSame([], $this->manager->ops);
		$this->assertUntouched($php, 'current', $before['etag']);
	}

	/**
	 * A denied restore never lists, backs up or rolls back. The plan of a missing version still reports that version.
	 *
	 * @return void
	 */
	public function testDeniedRestoreDoesNotBackUpOrRollBack(): void {
		$this->build('off');
		$this->allow(['txt']);
		$path = $this->put('a.php', 'current');
		$before = $this->node($path);
		$this->manager->versions = [(new FakeVersion('1', 1759100000, 7, 'text/plain', 'a.txt'))->withContent('plain')];

		$planned = $this->planFailure('files_version_restore', ['path' => $path, 'version' => '1']);
		$this->assertInstanceOf(FileTypeDenied::class, $planned);
		$done = $this->failure('files_version_restore', ['path' => $path, 'version' => '1']);
		$this->assertInstanceOf(FileTypeDenied::class, $done);
		$this->assertSame(FileTypeMessages::denied(), $done->getMessage());
		$this->assertNotContains('rollback', $this->manager->ops);
		$this->assertUntouched($path, 'current', $before['etag']);

		$this->manager->ops = [];
		$this->manager->versions = [];
		$missing = $this->planFailure('files_version_restore', ['path' => $this->put('a.txt', 'alpha'), 'version' => 'nope']);
		$this->assertSame(FilesMessages::versionMissing('nope'), $missing->getMessage());
		$this->assertSame([], $this->backups());
	}

	/**
	 * A denied name never enters the lock-aware restore, so the version is not read and nothing is written.
	 *
	 * @return void
	 */
	public function testDeniedRestoreWithLockProviderDoesNotWrite(): void {
		$this->build('on');
		$this->allow(['txt']);
		$path = $this->put('a.php', 'current');
		$before = $this->node($path);
		$this->manager->versions = [(new FakeVersion('1', 1759100000, 7, 'text/plain', 'a.txt'))->withContent('plain')];

		$done = $this->failure('files_version_restore', ['path' => $path, 'version' => '1']);
		$this->assertInstanceOf(FileTypeDenied::class, $done);
		$this->assertSame([], $this->manager->ops);
		$this->assertUntouched($path, 'current', $before['etag']);
	}

	/**
	 * The plan reads one snapshot. Confirmation reads another, so a policy saved in between refuses the write.
	 *
	 * @return void
	 */
	public function testPolicyChangeAfterPlanRefusesTheEdit(): void {
		$this->allow(['txt']);
		$path = $this->put('a.txt', 'alpha');
		$before = $this->node($path);
		$plan = $this->module->preview('files_edit', ['path' => $path, 'content' => 'beta'], 'alice');
		$this->assertSame('files_edit', $plan['action']);
		$this->assertSame([], $this->tree->ops);

		$this->allow(['md']);
		$done = $this->failure('files_edit', ['path' => $path, 'content' => 'beta', 'confirm' => true]);
		$this->assertInstanceOf(FileTypeDenied::class, $done);
		$this->assertUntouched($path, 'alpha', $before['etag']);
	}

	/**
	 * confirm and confirm_shared do not authorize a name the policy refuses.
	 *
	 * @return void
	 */
	public function testConfirmDoesNotOverrideThePolicy(): void {
		$this->allow(['txt']);
		$path = $this->put('note.md', 'alpha', 'text/markdown');
		$before = $this->node($path);
		$done = $this->failure('files_edit', ['path' => $path, 'content' => 'beta', 'confirm' => true, 'confirm_shared' => true]);

		$this->assertInstanceOf(FileTypeDenied::class, $done);
		$this->assertSame(FileTypeMessages::denied(), $done->getMessage());
		$this->assertUntouched($path, 'alpha', $before['etag']);
	}

	/**
	 * The user id is not an input of the policy. An administrator is refused the same way.
	 *
	 * @return void
	 */
	public function testAdminUserIdDoesNotOverrideThePolicy(): void {
		$this->allow(['txt']);
		$path = $this->put('note.md', 'alpha', 'text/markdown');
		$before = $this->node($path);
		$done = $this->failure('files_edit', ['path' => $path, 'content' => 'beta', 'confirm' => true, 'confirm_shared' => true], 'admin');

		$this->assertInstanceOf(FileTypeDenied::class, $done);
		$this->assertSame(FileTypeMessages::denied(), $done->getMessage());
		$this->assertUntouched($path, 'alpha', $before['etag']);
	}

	/**
	 * An unreadable policy refuses the subject write and is not treated as mode off.
	 *
	 * @return void
	 */
	public function testUnavailablePolicyRefusesBeforeBackup(): void {
		$path = $this->put('a.txt', 'alpha');
		$before = $this->node($path);
		$this->appConfig->stored['mcp'][FileWritePolicy::KEY] = '{';

		$done = $this->failure('files_edit', ['path' => $path, 'content' => 'beta']);
		$this->assertInstanceOf(FileWritePolicyUnavailable::class, $done);
		$this->assertSame(FileTypeMessages::unavailable(), $done->getMessage());
		$this->assertStringNotContainsString('allowlist', $done->getMessage());
		$this->assertUntouched($path, 'alpha', $before['etag']);
	}

	/**
	 * Deleting the key returns to mode off, including for a name the previous allowlist refused.
	 *
	 * @return void
	 */
	public function testOffPolicyEditsANameOutsideThePreviousList(): void {
		$this->allow(['txt']);
		$path = $this->put('note.md', 'alpha', 'text/markdown');
		$denied = $this->failure('files_edit', ['path' => $path, 'content' => 'nope']);
		$this->assertInstanceOf(FileTypeDenied::class, $denied);

		unset($this->appConfig->stored['mcp'][FileWritePolicy::KEY]);
		$this->json('files_edit', ['path' => $path, 'content' => 'beta']);
		$this->assertSame('beta', $this->node($path)['content']);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function commitSinks(): iterable {
		yield 'direct write' => ['none'];
		yield 'locked write' => ['on'];
	}

	/**
	 * A refusal after the backup keeps the copy and reports the same partial state as a failed write.
	 *
	 * `none` is the direct putContent. `on` is the lock-aware putContent. Removing the check before either
	 * write changes the file, so both inventory branches fail this test.
	 *
	 * @param string $locks none or on, as {@see self::build()} accepts.
	 * @return void
	 */
	#[DataProvider('commitSinks')]
	public function testSinkDenialAfterBackupKeepsTheOriginalAndTheBackup(string $locks): void {
		$this->gate = new FlipGate($this->guard, 3);
		$this->build($locks);
		$path = $this->put('a.txt', 'alpha');
		$before = $this->node($path);

		$done = $this->failure('files_edit', ['path' => $path, 'content' => 'beta']);
		$relative = $this->keptBackup($path, 'alpha', $before['etag']);
		$this->assertSame(FilesMessages::writeFailed($relative), $done->getMessage());
		$this->assertNotInstanceOf(FileTypeDenied::class, $done);
		$this->assertSame(3, $this->gate->calls);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function restoreSinks(): iterable {
		yield 'rollback' => ['off'];
		yield 'locked write' => ['on'];
	}

	/**
	 * The same post-backup refusal covers the native rollback and the lock-aware write.
	 *
	 * @param string $locks off or on, as {@see self::build()} accepts.
	 * @return void
	 */
	#[DataProvider('restoreSinks')]
	public function testSinkDenialAfterBackupOnRestoreKeepsTheOriginalAndTheBackup(string $locks): void {
		$this->gate = new FlipGate($this->guard, 3);
		$this->build($locks);
		$path = $this->put('a.txt', 'current');
		$before = $this->node($path);
		$this->manager->versions = [(new FakeVersion('1', 1759100000, 3, 'text/plain', 'a.txt'))->withContent('old')];
		$this->manager->onRollback = function () use ($path): void {
			$this->tree->nodes[self::ROOT . $path]['content'] = 'old';
		};

		$done = $this->failure('files_version_restore', ['path' => $path, 'version' => '1']);
		$relative = $this->keptBackup($path, 'current', $before['etag']);
		$this->assertSame(FilesMessages::writeFailed($relative), $done->getMessage());
		$this->assertNotContains('rollback', $this->manager->ops);
		$this->assertSame(3, $this->gate->calls);
	}

	/**
	 * @param string $userPath User-relative path whose bytes and ETag must be unchanged.
	 * @param string $content Original bytes, also expected inside the backup.
	 * @param string $etag ETag before the refused sink.
	 * @return string User-relative path of the backup that was kept.
	 */
	private function keptBackup(string $userPath, string $content, string $etag): string {
		$stored = $this->node($userPath);
		$this->assertSame($content, $stored['content']);
		$this->assertSame($etag, $stored['etag']);
		$this->assertNotContains('write ' . self::ROOT . $userPath, $this->tree->ops);
		$copies = $this->backups();
		$this->assertCount(1, $copies);
		$path = (string)array_key_first($copies);
		$this->assertSame($content, $copies[$path]['content']);
		$this->assertStringEndsWith('.bak', $path);
		return substr($path, strlen(self::ROOT));
	}

	/**
	 * The backup service has no way to read the policy. The refusal has to happen before it is called.
	 *
	 * @return void
	 */
	public function testFileBackupDoesNotReadThePolicy(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Tools/Files/FileBackup.php');
		$this->assertStringNotContainsString('FileTypeGuard', $source);
		$this->assertStringNotContainsString('FileWritePolicy', $source);
		$this->assertStringNotContainsString('ContentTypeGate', $source);
		$this->assertStringNotContainsString('assertWritableName', $source);
	}

	/**
	 * The large modules only delegate. The sinks live in the focused classes.
	 *
	 * @return void
	 */
	public function testEditReplaceAndRestoreDelegateOutOfTheLargeModules(): void {
		$files = (string)file_get_contents(__DIR__ . '/../../../../lib/Tools/Files/FilesModule.php');
		$versions = (string)file_get_contents(__DIR__ . '/../../../../lib/Tools/Files/VersionTools.php');
		$this->assertStringNotContainsString('->putContent', $files);
		$this->assertStringNotContainsString('->rollback', $files);
		$this->assertStringNotContainsString('->putContent', $versions);
		$this->assertStringNotContainsString('->rollback', $versions);
		$this->assertStringContainsString('VersionRestore is not wired', $versions);
		$this->assertStringContainsString('EditFileHandler is not wired', $files);
	}
}

/**
 * Counts every current-name check and refuses from one call onward.
 *
 * The shared gate is how a test reaches the check after the backup without a second policy store.
 */
final class FlipGate extends ContentTypeGate {
	/** How many times the current name was checked. */
	public int $calls = 0;

	/**
	 * @param FileTypeGuard $guard Policy underneath. Mode off makes the parent check a no-op.
	 * @param int $denyFrom The call count, starting at 1, from which the name is refused.
	 */
	public function __construct(FileTypeGuard $guard, private int $denyFrom) {
		parent::__construct($guard);
	}

	/**
	 * @param File $file File whose current basename is being authorized.
	 * @return void
	 * @throws FileTypeDenied From {@see $denyFrom} onward.
	 */
	public function assertCurrentName(File $file): void {
		$this->calls++;
		if ($this->calls >= $this->denyFrom) {
			throw new FileTypeDenied();
		}
		parent::assertCurrentName($file);
	}
}
