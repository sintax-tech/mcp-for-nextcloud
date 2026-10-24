<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Service\FileWritePolicyUnavailable;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tools\Common\FileTypeDenied;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Brings a stored version back over the file's current name, after the same backup an edit takes.
 *
 * The version supplies bytes, not a new authorized name. The policy is read against `$file->getName()`
 * before the backup and again immediately before the rollback or the write. Listing and reading versions
 * stay on {@see VersionTools}; this class has its own copies of the files_versions lookup so those reads
 * do not gain a write dependency.
 */
final class VersionRestore {
	/** Nextcloud app that owns the versions. */
	private const APP = 'files_versions';

	/** Bytes of a version held in memory while it is copied; more spills to a temporary file. */
	private const COPY_MEMORY = 8388608;

	/** Class resolved lazily; a string so this file autoloads with files_versions absent. */
	private const MANAGER = 'OCA\Files_Versions\Versions\IVersionManager';

	/**
	 * @param IAppManager $appManager Tells whether files_versions is enabled for the user.
	 * @param IUserManager $userManager Resolves the account the versions belong to.
	 * @param ContentTypeGate $types Current-name policy. A fresh snapshot at each boundary.
	 * @param NodeAccessInfo $access Access description returned after a successful restore.
	 * @param ContainerInterface $container Resolves the files_versions manager without a hard constructor dependency.
	 * @param LockAwareWrite|null $locks When its provider is available, the version is written through the file.
	 * @param FilesAccessGuard|null $visibilityGuard Scope and exclusion tags around the write and the re-read.
	 */
	public function __construct(
		private IAppManager $appManager,
		private IUserManager $userManager,
		private ContentTypeGate $types,
		private NodeAccessInfo $access,
		private ContainerInterface $container,
		private ?LockAwareWrite $locks = null,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Restores one version after a verified backup of the current bytes.
	 *
	 * With a lock provider (files_lock) there, the version is read through files_versions and written through the
	 * file, like any edit: files_lock refuses the write when the file is locked, and the write goes through the normal
	 * versioning of the file, as the versions backend decides it. What this method guarantees about the current
	 * content is the backup in /MCP backups, taken before. The core rollback is not used then, because VersionManager::rollback()
	 * (Nextcloud 32 to 35, handleAppLocks) catches the refusal of a Text or Office lock and repeats the write inside
	 * that app's lock scope, over the document still open in the editor. The file then shows the restore time as its
	 * modification time and the change as an edit, where the core rollback would show the version's time and a restore.
	 * Without a lock provider no lock can exist, and the core rollback runs as before.
	 *
	 * The shared-write guard runs in the handler before this call, so the confirmation payload is a
	 * non-error result produced there and never reaches this class.
	 *
	 * @param Folder $root The user's folder.
	 * @param File $file File to restore. Its current basename is what the policy authorizes.
	 * @param string $path Normalized user-relative path of `$file`.
	 * @param string $revision Version identifier as returned by list().
	 * @param string $viewerUid Authenticated user.
	 * @param FileBackup $backup Backup service of the caller. This class does not ask it to read the policy.
	 * @return array{path:string, size:int, etag:string, access:array<string, mixed>, backup:string, version:string}
	 * @throws ToolFailure When versioning is off, the version does not exist or cannot be read, the backup fails, a
	 *         lock refuses the write ({@see LockWriteFailure}), the rollback reports failure, or the type policy refuses the name.
	 */
	public function restore(Folder $root, File $file, string $path, string $revision, string $viewerUid, FileBackup $backup): array {
		$user = $this->requireVersioning($viewerUid);
		$version = $this->find($user, $file, $revision);
		// Before the copy: a refused current name must not create a backup. The version name is not an exemption.
		$this->types->assertCurrentName($file);
		// The backup refuses a locked file before copying it.
		$copy = $backup->prepare($root, $file, $path, $viewerUid, null);
		$this->visibilityGuard?->assertVisible($file);
		$manager = $this->manager();
		try {
			BackupWriteBoundary::afterCopy($backup, $path, $copy);
			if ($this->locks !== null && $this->locks->providerAvailable()) {
				$this->writeVersion($manager, $version, $file, $path, $viewerUid, $copy);
			} else {
				// Immediately before the native rollback, still after the backup.
				$this->types->assertCurrentName($file);
				if (NodeAccess::mutate(fn () => $manager->rollback($version), $this->visibilityGuard) === false) {
					throw new ToolFailure(FilesMessages::versionRestoreFailed());
				}
			}
		} catch (FileTypeDenied | FileWritePolicyUnavailable) {
			// The copy already exists. Do not roll back, do not write, and do not delete the backup.
			throw new ToolFailure(FilesMessages::writeFailed($copy));
		}
		$node = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
		$this->visibilityGuard?->assertVisible($node);
		return [
			'path' => $path,
			'size' => (int)$node->getSize(),
			'etag' => (string)$node->getEtag(),
			'access' => $this->access->describe($node, $viewerUid),
			'backup' => $copy,
			'version' => $revision,
		];
	}

	/**
	 * Writes the content of a version over the file through the node, checking the locks right before.
	 *
	 * The version is read into a copy first and its own stream is closed before the write starts. A stream open on the
	 * version while the file is written holds the version's file open on the storage; where files_lock is installed
	 * (the Nextcloud 34 and 35 images ship it), taking the lock the write needs then fails with "locked by another
	 * operation", as the restore of a team folder file showed. The copy lives in memory up to {@see self::COPY_MEMORY}
	 * bytes and spills to a temporary file beyond that, so a large version costs no more memory than that.
	 *
	 * The type policy is read again after that stream is closed and immediately before putContent.
	 *
	 * @param object $manager The files_versions manager.
	 * @param object $version The IVersion to bring back.
	 * @param File $file File to restore.
	 * @param string $path Normalized user-relative path of `$file`.
	 * @param string $viewerUid Authenticated user.
	 * @param string $copy User-relative path of the backup taken before.
	 * @return void
	 * @throws ToolFailure When the version cannot be read or copied (nothing is written then) or a lock refuses the write.
	 * @throws FileTypeDenied When the current name is refused after the backup. The caller keeps the backup.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read after the backup. The caller keeps the backup.
	 */
	private function writeVersion(object $manager, object $version, File $file, string $path, string $viewerUid, string $copy): void {
		$locks = $this->locks ?? throw new \LogicException('LockAwareWrite is not wired');
		$stream = $manager->read($version);
		if (!is_resource($stream)) {
			throw new ToolFailure(FilesMessages::versionRestoreFailed());
		}
		$content = null;
		try {
			try {
				$content = fopen('php://temp/maxmemory:' . self::COPY_MEMORY, 'w+');
				if ($content === false || stream_copy_to_stream($stream, $content) === false) {
					throw new ToolFailure(FilesMessages::versionRestoreFailed());
				}
			} finally {
				// Before the write: the version's file must not be open while the file is written.
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
			$this->types->assertCurrentName($file);
			NodeAccess::mutate(fn () => $locks->run(static function () use ($file, $content): void {
				// Rewound inside, so a repeated write by the lock check starts from the first byte.
				rewind($content);
				$file->putContent($content);
			}, $file, $viewerUid, $path), $this->visibilityGuard);
		} catch (LockWriteFailure $e) {
			throw $e->withNote(FilesMessages::originalKept($copy));
		} finally {
			if (is_resource($content)) {
				fclose($content);
			}
		}
	}

	/**
	 * @param string $viewerUid Authenticated user.
	 * @return IUser The user the versions belong to.
	 * @throws ToolFailure When files_versions is not enabled for them.
	 */
	private function requireVersioning(string $viewerUid): IUser {
		$user = $this->userManager->get($viewerUid);
		if ($user === null || !$this->appManager->isEnabledForUser(self::APP, $user)) {
			throw new ToolFailure(FilesMessages::versionsOff());
		}
		return $user;
	}

	/**
	 * @param IUser $user Owner of the versions.
	 * @param File $file File the version belongs to.
	 * @param string $revision Version identifier.
	 * @return object The IVersion whose revision matches.
	 * @throws ToolFailure When no version carries that identifier.
	 */
	private function find(IUser $user, File $file, string $revision): object {
		foreach ($this->manager()->getVersionsForFile($user, $file) as $version) {
			if ((string)$version->getRevisionId() === $revision) {
				return $version;
			}
		}
		throw new ToolFailure(FilesMessages::versionMissing($revision));
	}

	/**
	 * @return object The files_versions manager, resolved on first use.
	 * @throws ToolFailure When the app is enabled but its classes cannot be resolved.
	 */
	private function manager(): object {
		try {
			$manager = $this->container->get(self::MANAGER);
		} catch (NotFoundExceptionInterface | \RuntimeException) {
			$manager = null;
		}
		if (!is_object($manager)) {
			throw new ToolFailure(FilesMessages::versionsOff());
		}
		return $manager;
	}
}
