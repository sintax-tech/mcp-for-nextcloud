<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Backups\AfterCopyFailure;
use OCA\Mcp\Modules\Files\Backups\FileBackup;
use OCA\Mcp\Modules\Files\Writing\ContentTypeGate;
use OCA\Mcp\Modules\Files\Writing\ContentWritePlan;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\FileReplacement;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replaces an existing file for another module with the same guards as files_edit: the pre-write copy of
 * {@see FileBackup} (versioning, updateable, backup folders, ETag, locks, verified copy or the administrator
 * suspension with native versioning on), the current-name type policy, scope and visibility, and confirm_shared.
 *
 * Nothing is ever deleted or retried: a write that fails unexpectedly after the copy is reported as uncertain,
 * naming the copy that keeps the original.
 */
final class ProtectedFileReplacement implements FileReplacement {
	/** Plan keys this port renames or drops; every other key (lock notice, warnings) is passed on. */
	private const PLAN_KEYS = ['action', 'path', 'etag', 'backup', 'access', 'shared', 'requiresSharedConfirmation', 'recoverable', 'consequence', 'message'];

	/**
	 * @param ActorRoot $roots Bound actor roots.
	 * @param FilesAccessGuard $visibility Current scope and exclusion tags.
	 * @param ContentWritePlan $plans Plan fields of files_edit: ETag, backup decision, access, shared, lock notice.
	 * @param SharedWriteGuard $shared Shared-write confirmation at execution.
	 * @param ContentTypeGate $types Current-name type policy, one snapshot per boundary.
	 * @param FileBackup $backup Existing pre-write copy and its preconditions.
	 * @param LockAwareWrite $locks Lock check right before the write.
	 * @param ReplacementReceipt $receipts Read-back of the replaced file and of its copy.
	 * @param LoggerInterface $logger Safe storage diagnostics.
	 */
	public function __construct(
		private ActorRoot $roots,
		private FilesAccessGuard $visibility,
		private ContentWritePlan $plans,
		private SharedWriteGuard $shared,
		private ContentTypeGate $types,
		private FileBackup $backup,
		private LockAwareWrite $locks,
		private ReplacementReceipt $receipts,
		private LoggerInterface $logger,
	) {
	}

	/** {@inheritDoc} */
	public function inspect(string $userId, string $path, ?string $etag): array {
		[$root, $path, $file] = $this->resolve($userId, $path);
		NodeAccess::checkEtag($file, $etag);
		$this->types->assertCurrentName($file);
		if (!$file->isUpdateable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		$plan = $this->plans->describe($file, $userId, $path, ['action' => 'mail_save_attachment']);
		return [
			'path' => $path,
			'access' => $plan['access'],
			'shared' => $plan['shared'],
			'requiresSharedConfirmation' => $plan['requiresSharedConfirmation'],
			'replaces' => [
				'path' => $path,
				'fileId' => (int)$file->getId(),
				'size' => (int)$file->getSize(),
				'etag' => $plan['etag'],
			],
			'backup' => $plan['backup'],
			'recoverable' => $plan['recoverable'],
			'backupNote' => $plan['consequence'],
		] + array_diff_key($plan, array_flip(self::PLAN_KEYS));
	}

	/** {@inheritDoc} */
	public function replace(string $userId, string $path, string $content, bool $shared, int $fileId, string $etag): array {
		[$root, $path, $file] = $this->resolve($userId, $path);
		if ((int)$file->getId() !== $fileId) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		$this->types->assertCurrentName($file);
		if (($confirmation = $this->shared->guard($file, $userId, $path, $shared)) !== null) {
			return $confirmation;
		}
		$previous = ['fileId' => $fileId, 'size' => (int)$file->getSize(), 'etag' => (string)$file->getEtag()];
		// Versioning, updateable, backup folders, ETag and locks, then the verified copy or the authorized suspension.
		$copy = $this->backup->prepare($root, $file, $path, $userId, $etag);
		try {
			$this->visibility->assertVisible($file);
			// After the copy and immediately before the write. A refusal here keeps the copy.
			$this->types->assertCurrentName($file);
			$this->backup->assertWritablePath($path);
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (Throwable $e) {
			throw AfterCopyFailure::explain($e, $copy, $file, $this->visibility);
		}
		$uncertain = $this->write($file, $userId, $path, $content, $copy);
		return $this->receipts->read($root, $userId, $path, $content, $previous, $copy, $uncertain);
	}

	/**
	 * Writes the bytes once. Lock and quota refusals keep their actionable message and name the copy.
	 *
	 * @param File $file The file being replaced.
	 * @param string $userId Actor UID.
	 * @param string $path Normalized actor-relative path.
	 * @param string $content Unmodified bytes.
	 * @param string $copy Verified copy path, or empty for the authorized suspension.
	 * @return bool Whether the write failed unexpectedly, leaving the file in an unknown state.
	 * @throws ToolFailure When a lock, the quota or a now hidden file refuses the write.
	 * @throws PolicyUnavailable When the Files scope cannot be read.
	 */
	private function write(File $file, string $userId, string $path, string $content, string $copy): bool {
		try {
			NodeAccess::mutate(fn () => $this->locks->run(fn () => $file->putContent($content), $file, $userId, $path), $this->visibility);
			return false;
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (ToolFailure|LockedException $e) {
			throw AfterCopyFailure::explain($e, $copy, $file, $this->visibility);
		} catch (NotEnoughSpaceException) {
			throw AfterCopyFailure::explain(new ToolFailure(CommonMessages::insufficientQuota()), $copy, $file, $this->visibility);
		} catch (Throwable $e) {
			// Only the class: the message of a storage exception may carry the path.
			$this->logger->error('MCP attachment replace write failed: {exception_class} (code {exception_code})', [
				'app' => 'mcp',
				'exception_class' => $e::class,
				'exception_code' => ExceptionDetails::code($e),
			]);
			return true;
		}
	}

	/**
	 * Resolves the existing file under the current scope, outside every backup folder.
	 *
	 * @param string $userId Actor UID.
	 * @param string $path Path as the caller wrote it.
	 * @return array{0: Folder, 1: string, 2: File} Actor root, normalized path and the visible file.
	 * @throws ToolFailure When the path is a backup folder, missing, hidden, unreadable or a folder.
	 * @throws PolicyUnavailable When the Files scope cannot be read.
	 */
	private function resolve(string $userId, string $path): array {
		$this->visibility->clearCache();
		$this->visibility->assertScopeReadable();
		$root = $this->roots->get($userId);
		$path = PathGuard::normalize($path);
		$this->backup->assertWritablePath($path);
		$file = NodeAccess::run(fn (): File => NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibility)));
		return [$root, $path, $file];
	}
}
