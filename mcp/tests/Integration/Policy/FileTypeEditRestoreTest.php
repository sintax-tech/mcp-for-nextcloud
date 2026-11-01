<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\DavClient;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCA\Mcp\Tools\Files\FileBackup;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;

/**
 * File-type policy on files_edit, files_replace and files_version_restore against a real server.
 *
 * The folder of each test stays in the user's tree, and so does every backup. Nothing created here is deleted.
 * Grants and the policy key are put back. Mode off is an absent key. confirm and the admin uid do not bypass the rule.
 */
final class FileTypeEditRestoreTest extends IntegrationTestCase {
	private const ADMIN = 'admin';

	/** @var list<string> User-config keys this class changes and restores. */
	private const USER_KEYS = [
		GrantPolicy::ELIGIBLE_KEY,
		GrantPolicy::CONNECTED_KEY,
		'grant_files_edit',
		'grant_files_restore',
	];

	/** @var list<string> Accounts that receive edit and restore for this class. */
	private const USERS = [Fixtures::ALICE, self::ADMIN];

	private string $folder = '';

	/** @var array<string, ?string> Values put back after the test. Null means the key was absent. */
	private array $before = [];

	private McpClient $mcp;

	private DavClient $dav;

	/**
	 * Snapshots grants and the policy, then opens edit and restore for alice and admin with the policy absent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$apps = Server::get(IAppConfig::class);
		$users = Server::get(IUserConfig::class);
		$this->before['service'] = $apps->hasKey('mcp', GrantPolicy::SERVICE_KEY)
			? $apps->getValueString('mcp', GrantPolicy::SERVICE_KEY)
			: null;
		$this->before['policy'] = $apps->hasKey(FileWritePolicy::APP, FileWritePolicy::KEY, null)
			? $apps->getValueString(FileWritePolicy::APP, FileWritePolicy::KEY, '', false)
			: null;
		foreach (self::USERS as $uid) {
			foreach (self::USER_KEYS as $key) {
				$this->before[$uid . ' ' . $key] = $users->hasKey($uid, 'mcp', $key)
					? $users->getValueString($uid, 'mcp', $key)
					: null;
			}
		}
		if ($apps->hasKey(FileWritePolicy::APP, FileWritePolicy::KEY, null)) {
			$apps->deleteKey(FileWritePolicy::APP, FileWritePolicy::KEY);
		}
		$apps->clearCache(false);
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		foreach (self::USERS as $uid) {
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
			$policy->setGrant($uid, 'files', 'edit', true);
			$policy->setGrant($uid, 'files', 'restore', true);
		}
		$this->folder = 't15-4-' . bin2hex(random_bytes(4));
		$login = LoginFactory::basic(Fixtures::ALICE);
		$this->mcp = new McpClient($login);
		$this->dav = new DavClient($login);
		$this->dav->mkcol($this->folder);
	}

	/**
	 * Puts grants and the policy key back. Created files and backups stay where they are.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		try {
			$this->restoreConfig();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Restores every snapshotted key, including when a test failed after changing it.
	 *
	 * @return void
	 */
	private function restoreConfig(): void {
		if ($this->before === []) {
			return;
		}
		$config = Server::get(IConfig::class);
		$apps = Server::get(IAppConfig::class);
		$users = Server::get(IUserConfig::class);
		try {
			if (array_key_exists('service', $this->before)) {
				$this->before['service'] === null
					? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY)
					: $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->before['service']);
			}
			if (array_key_exists('policy', $this->before)) {
				if ($this->before['policy'] === null) {
					if ($apps->hasKey(FileWritePolicy::APP, FileWritePolicy::KEY, null)) {
						$apps->deleteKey(FileWritePolicy::APP, FileWritePolicy::KEY);
					}
				} else {
					$apps->setValueString(FileWritePolicy::APP, FileWritePolicy::KEY, $this->before['policy'], false);
				}
			}
			foreach (self::USERS as $uid) {
				foreach (self::USER_KEYS as $key) {
					$slot = $uid . ' ' . $key;
					if (!array_key_exists($slot, $this->before)) {
						continue;
					}
					$this->before[$slot] === null
						? $config->deleteUserValue($uid, 'mcp', $key)
						: $config->setUserValue($uid, 'mcp', $key, $this->before[$slot]);
				}
				$users->clearCache($uid, false);
			}
		} finally {
			$apps->clearCache(false);
		}
	}

	/**
	 * Saves an allowlist. The extensions are already in canonical order.
	 *
	 * @param string ...$extensions Lowercase extensions, without a leading dot.
	 * @return void
	 */
	private function allow(string ...$extensions): void {
		Server::get(FileWritePolicy::class)->save('allowlist', array_values($extensions), false);
	}

	/**
	 * @param string $uid Nextcloud user id.
	 * @return Folder That user's folder.
	 */
	private function home(string $uid): Folder {
		return Server::get(IRootFolder::class)->getUserFolder($uid);
	}

	/**
	 * @param string $name File name inside this test's folder.
	 * @return string User-relative path the tools receive.
	 */
	private function path(string $name): string {
		return '/' . $this->folder . '/' . $name;
	}

	/**
	 * Reads the file again from the user's folder, not from a node held across the HTTP call.
	 *
	 * @param string $uid Nextcloud user id.
	 * @param string $name File name inside this test's folder.
	 * @return File The current node.
	 */
	private function node(string $uid, string $name): File {
		$node = $this->home($uid)->get($this->folder . '/' . $name);
		$this->assertInstanceOf(File::class, $node);
		return $node;
	}

	/**
	 * @param string $uid Nextcloud user id.
	 * @param string $name File name inside this test's folder.
	 * @return string Current bytes.
	 */
	private function content(string $uid, string $name): string {
		return $this->node($uid, $name)->getContent();
	}

	/**
	 * @param string $uid Nextcloud user id.
	 * @param string $name File name inside this test's folder.
	 * @return string Current ETag.
	 */
	private function etag(string $uid, string $name): string {
		return (string)$this->node($uid, $name)->getEtag();
	}

	/**
	 * Names of the backups of this test's folder. Folder::nodeExists sees only direct children.
	 *
	 * @param string $uid Nextcloud user id.
	 * @return list<string> Sorted file names.
	 */
	private function backupNames(string $uid): array {
		$home = $this->home($uid);
		if (!$home->nodeExists(FileBackup::FOLDER)) {
			return [];
		}
		$root = $home->get(FileBackup::FOLDER);
		if (!$root instanceof Folder || !$root->nodeExists($this->folder)) {
			return [];
		}
		$dir = $root->get($this->folder);
		if (!$dir instanceof Folder) {
			return [];
		}
		$names = [];
		foreach ($dir->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$names[] = $node->getName();
			}
		}
		sort($names);
		return $names;
	}

	/**
	 * The reported backup exists under MCP backups, ends in .bak and holds the bytes from before the write.
	 *
	 * @param string $uid Nextcloud user id.
	 * @param mixed $reported Path returned by the tool.
	 * @param string $expected Bytes the backup must hold.
	 * @return void
	 */
	private function assertBackupHolds(string $uid, mixed $reported, string $expected): void {
		$this->assertIsString($reported);
		$relative = ltrim($reported, '/');
		$this->assertStringStartsWith(FileBackup::FOLDER . '/' . $this->folder . '/', $relative);
		$this->assertStringEndsWith('.bak', $relative);
		$node = $this->home($uid)->get($relative);
		$this->assertInstanceOf(File::class, $node);
		$this->assertSame($expected, $node->getContent());
	}

	/**
	 * A refusal is a tool error or a JSON-RPC error, and it does not reveal the allowlist.
	 *
	 * @param ToolReply $reply Answer of the call.
	 * @param string $label What was refused.
	 * @return void
	 */
	private function assertRefused(ToolReply $reply, string $label): void {
		$this->assertTrue($reply->isError() || isset($reply->body['error']), $label . ': ' . $reply->describe());
		$this->assertFalse($reply->ok(), $label);
		$this->assertStringNotContainsString('allowlist', $reply->raw, $label);
		$this->assertStringNotContainsString('"extensions"', $reply->raw, $label);
	}

	/**
	 * An allowed a.txt is edited and replaced. Each write leaves a verified backup of the previous bytes.
	 *
	 * @return void
	 */
	public function testAllowedEditAndReplaceOfATxtLeaveAVerifiedBackup(): void {
		$this->allow('txt');
		$this->dav->put($this->folder . '/a.txt', 'alpha');
		$edit = $this->mcp->write('files_edit', ['path' => $this->path('a.txt'), 'content' => 'beta']);
		$this->assertTrue($edit->ok(), $edit->describe());
		$this->assertSame('beta', $this->content(Fixtures::ALICE, 'a.txt'));
		$this->assertBackupHolds(Fixtures::ALICE, $edit->json()['backup'] ?? null, 'alpha');

		$replace = $this->mcp->write('files_replace', [
			'path' => $this->path('a.txt'),
			'old' => 'beta',
			'new' => 'gamma',
		]);
		$this->assertTrue($replace->ok(), $replace->describe());
		$this->assertSame('gamma', $this->content(Fixtures::ALICE, 'a.txt'));
		$this->assertBackupHolds(Fixtures::ALICE, $replace->json()['backup'] ?? null, 'beta');
	}

	/**
	 * note.md is text, so an allowlist of txt refuses it without a backup. Removing the key turns the rule off.
	 *
	 * @return void
	 */
	public function testDeniedMarkdownEditLeavesNoBackupAndAnAbsentKeyAllowsIt(): void {
		$this->allow('txt');
		$this->dav->put($this->folder . '/note.md', 'keep');
		$etag = $this->etag(Fixtures::ALICE, 'note.md');
		$backups = $this->backupNames(Fixtures::ALICE);
		$refused = $this->mcp->write('files_edit', ['path' => $this->path('note.md'), 'content' => 'changed']);
		$this->assertRefused($refused, 'edit of note.md');
		$this->assertSame('keep', $this->content(Fixtures::ALICE, 'note.md'));
		$this->assertSame($etag, $this->etag(Fixtures::ALICE, 'note.md'));
		$this->assertSame($backups, $this->backupNames(Fixtures::ALICE));

		$apps = Server::get(IAppConfig::class);
		$apps->deleteKey(FileWritePolicy::APP, FileWritePolicy::KEY);
		$apps->clearCache(false);
		$edit = $this->mcp->write('files_edit', ['path' => $this->path('note.md'), 'content' => 'changed']);
		$this->assertTrue($edit->ok(), $edit->describe());
		$this->assertSame('changed', $this->content(Fixtures::ALICE, 'note.md'));
	}

	/**
	 * The plan is accepted, then the policy changes. The confirmed call does not write.
	 *
	 * @return void
	 */
	public function testPolicyChangeAfterThePlanRefusesConfirmation(): void {
		$this->allow('txt');
		$this->dav->put($this->folder . '/a.txt', 'alpha');
		$etag = $this->etag(Fixtures::ALICE, 'a.txt');
		$backups = $this->backupNames(Fixtures::ALICE);
		$plan = $this->mcp->tool('files_edit', ['path' => $this->path('a.txt'), 'content' => 'beta']);
		$this->assertTrue($plan->ok(), $plan->describe());
		$this->assertTrue(($plan->structured()['requiresConfirmation'] ?? false) === true, $plan->describe());
		$this->assertSame('alpha', $this->content(Fixtures::ALICE, 'a.txt'));
		$this->assertSame($backups, $this->backupNames(Fixtures::ALICE));

		$this->allow('md');
		$write = $this->mcp->write('files_edit', ['path' => $this->path('a.txt'), 'content' => 'beta']);
		$this->assertRefused($write, 'confirm after the policy change');
		$this->assertSame('alpha', $this->content(Fixtures::ALICE, 'a.txt'));
		$this->assertSame($etag, $this->etag(Fixtures::ALICE, 'a.txt'));
		$this->assertSame($backups, $this->backupNames(Fixtures::ALICE));
	}

	/**
	 * The admin uid does not bypass the policy. The file is the admin's own, not alice's.
	 *
	 * @return void
	 */
	public function testAdminCannotOverrideThePolicyOnTheirOwnFile(): void {
		$this->allow('txt');
		$login = LoginFactory::basic(self::ADMIN);
		$dav = new DavClient($login);
		$mcp = new McpClient($login);
		$dav->mkcol($this->folder);
		$dav->put($this->folder . '/note.md', 'admin note');
		$etag = $this->etag(self::ADMIN, 'note.md');
		$backups = $this->backupNames(self::ADMIN);
		$reply = $mcp->write('files_edit', ['path' => $this->path('note.md'), 'content' => 'changed']);
		$this->assertRefused($reply, 'admin edit');
		$this->assertSame('admin note', $this->content(self::ADMIN, 'note.md'));
		$this->assertSame($etag, $this->etag(self::ADMIN, 'note.md'));
		$this->assertSame($backups, $this->backupNames(self::ADMIN));
	}

	/**
	 * Two later edits create versions. Tightening the policy so txt is forbidden refuses the restore.
	 *
	 * @return void
	 */
	public function testRestoreOfAForbiddenCurrentNameLeavesBytesEtagAndBackups(): void {
		$this->allow('txt');
		$this->dav->put($this->folder . '/a.txt', 'alpha');
		sleep(2);
		$beta = $this->mcp->write('files_edit', ['path' => $this->path('a.txt'), 'content' => 'beta']);
		$this->assertTrue($beta->ok(), $beta->describe());
		sleep(2);
		$gamma = $this->mcp->write('files_edit', ['path' => $this->path('a.txt'), 'content' => 'gamma']);
		$this->assertTrue($gamma->ok(), $gamma->describe());
		$etag = $this->etag(Fixtures::ALICE, 'a.txt');
		$backups = $this->backupNames(Fixtures::ALICE);
		$versions = $this->mcp->tool('files_versions_list', ['path' => $this->path('a.txt')]);
		$this->assertTrue($versions->ok(), $versions->describe());
		$entries = $versions->json()['versions'] ?? null;
		$this->assertIsArray($entries);
		$this->assertNotEmpty($entries, $versions->describe());
		$revision = (string)($entries[0]['revision'] ?? '');
		$this->assertNotSame('', $revision);

		$this->allow('md');
		$restore = $this->mcp->write('files_version_restore', [
			'path' => $this->path('a.txt'),
			'version' => $revision,
		]);
		$this->assertRefused($restore, 'restore of a.txt');
		$this->assertSame('gamma', $this->content(Fixtures::ALICE, 'a.txt'));
		$this->assertSame($etag, $this->etag(Fixtures::ALICE, 'a.txt'));
		$this->assertSame($backups, $this->backupNames(Fixtures::ALICE));
	}

	/**
	 * A version whose bytes look like PHP is restored because the current name is still a.txt.
	 *
	 * @return void
	 */
	public function testRestoreBringsBackPhpBytesWhileTheNameStaysTxt(): void {
		$this->allow('txt');
		$php = "<?php echo 1;\n";
		$this->dav->put($this->folder . '/a.txt', 'alpha');
		$edited = $this->mcp->write('files_edit', ['path' => $this->path('a.txt'), 'content' => $php]);
		$this->assertTrue($edited->ok(), $edited->describe());
		sleep(2);
		$plain = $this->mcp->write('files_edit', ['path' => $this->path('a.txt'), 'content' => 'plain']);
		$this->assertTrue($plain->ok(), $plain->describe());

		$versions = $this->mcp->tool('files_versions_list', ['path' => $this->path('a.txt')]);
		$this->assertTrue($versions->ok(), $versions->describe());
		$revision = null;
		foreach ($versions->json()['versions'] ?? [] as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$stored = $this->mcp->tool('files_version_read', [
				'path' => $this->path('a.txt'),
				'version' => (string)($entry['revision'] ?? ''),
			]);
			$this->assertTrue($stored->ok(), $stored->describe());
			if (($stored->json()['text'] ?? null) === $php) {
				$revision = (string)$entry['revision'];
				break;
			}
		}
		$this->assertIsString($revision, $versions->describe());

		$restore = $this->mcp->write('files_version_restore', [
			'path' => $this->path('a.txt'),
			'version' => $revision,
		]);
		$this->assertTrue($restore->ok(), $restore->describe());
		$file = $this->node(Fixtures::ALICE, 'a.txt');
		$this->assertSame('a.txt', $file->getName());
		$this->assertSame($php, $file->getContent());
		$this->assertBackupHolds(Fixtures::ALICE, $restore->json()['backup'] ?? null, 'plain');
	}

	/**
	 * A stored value that is not a policy refuses the edit. The bytes stay.
	 *
	 * @return void
	 */
	public function testUnavailablePolicyRefusesTheEdit(): void {
		$this->dav->put($this->folder . '/a.txt', 'alpha');
		$etag = $this->etag(Fixtures::ALICE, 'a.txt');
		$backups = $this->backupNames(Fixtures::ALICE);
		$apps = Server::get(IAppConfig::class);
		$apps->setValueString(FileWritePolicy::APP, FileWritePolicy::KEY, '{', false);
		$apps->clearCache(false);
		$reply = $this->mcp->write('files_edit', ['path' => $this->path('a.txt'), 'content' => 'beta']);
		$this->assertRefused($reply, 'unavailable policy');
		$this->assertSame('alpha', $this->content(Fixtures::ALICE, 'a.txt'));
		$this->assertSame($etag, $this->etag(Fixtures::ALICE, 'a.txt'));
		$this->assertSame($backups, $this->backupNames(Fixtures::ALICE));
	}
}
