<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Modules\Files\Writing\FileWriteBoundary;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use Throwable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotEnoughSpaceException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Final creation boundary; no empty node is created for a denied name, and a node created by a failed write is named. */
final class FileCreationWriter {
	/**
	 * @param FilesAccessGuard $visibilityGuard Existing visibility checks.
	 * @param LoggerInterface $logger Safe storage diagnostics.
	 * @param LockAwareWrite $locks Existing lock checks.
	 * @param FileTypeGuard|null $fileTypes Policy evaluator.
	 */
	public function __construct(private FilesAccessGuard $visibilityGuard, private LoggerInterface $logger,
		private LockAwareWrite $locks, private ?FileTypeGuard $fileTypes) {
	}

	/**
	 * @param Folder $folder Resolved destination folder.
	 * @param string $name Final basename.
	 * @param string|resource $content Bytes to write.
	 * @param string|null $userId Owner for lock checks.
	 * @param bool $asUser Whether the request acts as owner.
	 * @return File Created node.
	 * @throws ToolFailure If the destination, policy, lock or storage refuses before the file exists.
	 * @throws FileCreationFailure If anything fails after Nextcloud created the file; it names the file left behind.
	 * @throws ArgumentValidationException If storage refuses the final basename.
	 */
	public function write(Folder $folder, string $name, mixed $content, ?string $userId, bool $asUser): File {
		FileWriteBoundary::check($this->fileTypes, $name);
		if ($folder->nodeExists($name)) {
			throw new FileExists(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::fileExists());
		}
		try {
			$file = NodeAccess::requireFile(NodeAccess::run(fn () => NodeAccess::mutate(fn () => $folder->newFile($name), $this->visibilityGuard)));
		} catch (InvalidPathException) {
			throw new ArgumentValidationException('Invalid argument: path', 'path', \OCA\Mcp\Modules\Files\Presentation\CreationMessages::newFileNameRule());
		} catch (ToolFailure $e) {
			throw $e;
		} catch (Throwable $e) {
			// Preserve the original exception for the tool registry and checkout diagnostics.
			$this->logger->error('MCP new file write failed: {exception_class} (code {exception_code})', [
				'app' => 'mcp',
				'exception_class' => $e::class,
				'exception_code' => ExceptionDetails::code($e),
			]);
			throw $e;
		}
		// A file the visibility rules hide answers as missing, never named: it is left in place, as it always was.
		$this->visibilityGuard->assertVisible($file);
		if ((int)$file->getSize() > 0) {
			throw new FileExists(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::fileExists());
		}
		if ($content === '') {
			return $file;
		}
		$created = [(int)$file->getId(), (string)$file->getEtag()];
		$current = NodeAccess::requireFile($folder->get($name));
		if ([(int)$current->getId(), (string)$current->getEtag()] !== $created || (int)$current->getSize() > 0) {
			throw new FileExists(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::fileExists());
		}
		// From here on the file exists: a failure leaves it, empty or incomplete, and names it (never deleted, never
		// retried). The lock check is told so, and never says that nothing changed.
		try {
			FileWriteBoundary::check($this->fileTypes, $current->getName());
			$put = static fn () => $current->putContent($content);
			NodeAccess::mutate(fn () => $userId === null ? $put() : $this->locks->run($put, $current, $userId, null, $asUser, false), $this->visibilityGuard);
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (ToolFailure $e) {
			// Already a readable refusal: the type policy, a lock found by the check, or one the storage raised, explained.
			throw $this->leftBehind($folder, $name, $current, $userId, $e);
		} catch (LockedException $e) {
			throw $this->leftBehind($folder, $name, $current, $userId, NodeAccess::lockFailure($e));
		} catch (NotEnoughSpaceException) {
			throw $this->leftBehind($folder, $name, $current, $userId, new ToolFailure(CommonMessages::insufficientQuota()));
		} catch (Throwable $e) {
			// Only the class: the message of a storage exception may carry the path.
			$this->logger->error('MCP new file write failed: {exception_class} (code {exception_code})', ['app' => 'mcp', 'exception_class' => $e::class, 'exception_code' => ExceptionDetails::code($e)]);
			throw $this->leftBehind($folder, $name, $current, $userId, null);
		}
		return $current;
	}

	/**
	 * The failure of a write that stopped after Nextcloud created the file, naming the file it left.
	 *
	 * @param Folder $folder the destination folder
	 * @param string $name the basename of the new file
	 * @param File $file the file Nextcloud created
	 * @param string|null $userId the actor, whose own view gives the path; null names the file by its basename only
	 * @param ToolFailure|null $reason the refusal that stopped the content write; null for an unexplained failure
	 * @return FileCreationFailure the reason, kept as the previous exception, and the file left behind
	 */
	private function leftBehind(Folder $folder, string $name, File $file, ?string $userId, ?ToolFailure $reason): FileCreationFailure {
		$path = $name;
		$fileId = null;
		try {
			$prefix = '/' . $userId . '/files';
			$folderPath = $folder->getPath();
			if ($userId !== null && ($folderPath === $prefix || str_starts_with($folderPath, $prefix . '/'))) {
				$path = rtrim(substr($folderPath, strlen($prefix)), '/') . '/' . $name;
			}
			$fileId = (int)$file->getId();
		} catch (Throwable) {
			// The file is still named by what is known; never a second failure on top of the first.
		}
		return new FileCreationFailure(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createLeftBehind($reason?->getMessage(), $path), $path, $fileId, $reason);
	}
}
