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
use OCP\Files\NotFoundException;
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
		} catch (Throwable $e) {
			throw $this->reconciled($folder, $name, $userId, $e);
		}
		// A file the visibility rules hide answers as missing, never named: it is left in place, as it always was. A policy
		// that cannot be read here comes after the file exists, so it names the file like any other failure from here on.
		try {
			$this->visibilityGuard->assertVisible($file);
		} catch (PolicyUnavailable $e) {
			throw $this->failedAfterCreation($folder, $name, $file, $userId, $e);
		}
		// From here on the file exists: a failure leaves it, empty or incomplete, and names it (never deleted, never
		// retried), an unreadable policy included, whatever it says of itself. Only another client's file at the name
		// (FileExists) and a vanished one are answered as before.
		try {
			$size = (int)$file->getSize();
			$created = [(int)$file->getId(), (string)$file->getEtag()];
			$found = $content === '' ? $file : $folder->get($name);
			$now = $found instanceof File ? [(int)$found->getId(), (string)$found->getEtag(), (int)$found->getSize()] : null;
		} catch (NotFoundException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw $this->failedAfterCreation($folder, $name, $file, $userId, $e);
		}
		if ($size > 0) {
			throw new FileExists(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::fileExists());
		}
		if ($content === '') {
			return $file;
		}
		$current = NodeAccess::requireFile($found);
		if ($now !== [...$created, 0]) {
			throw new FileExists(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::fileExists());
		}
		// The lock check is told the call already created the file, so it never says that nothing changed.
		try {
			FileWriteBoundary::check($this->fileTypes, $current->getName());
			$put = static fn () => $current->putContent($content);
			NodeAccess::mutate(fn () => $userId === null ? $put() : $this->locks->run($put, $current, $userId, null, $asUser, false), $this->visibilityGuard);
		} catch (Throwable $e) {
			throw $this->failedAfterCreation($folder, $name, $current, $userId, $e);
		}
		return $current;
	}

	/**
	 * What a newFile that threw leaves behind, read again: the name was free before it, so a name still free means
	 * nothing was created and the failure leaves as it always did (a refusal, a 503, the original exception). A name
	 * that now exists may hold a file of this write or of another client: it is named when the actor may see it
	 * ({@see NewFileVisibility}), without an id that would claim it as ours; when it is hidden or cannot be read, the
	 * answer names nothing.
	 *
	 * @param Folder $folder the destination folder
	 * @param string $name the basename the write tried to create
	 * @param string|null $userId the actor, whose own view gives the path
	 * @param Throwable $e what newFile threw
	 * @return Throwable the original failure, or a FileCreationFailure when a file may have been created
	 */
	private function reconciled(Folder $folder, string $name, ?string $userId, Throwable $e): Throwable {
		if (!$e instanceof ToolFailure) {
			// Preserve the original exception for the tool registry and checkout diagnostics.
			$this->logger->error('MCP new file write failed: {exception_class} (code {exception_code})', [
				'app' => 'mcp',
				'exception_class' => $e::class,
				'exception_code' => ExceptionDetails::code($e),
			]);
		}
		$reason = $e instanceof ToolFailure ? $e : null;
		try {
			if (!$folder->nodeExists($name)) {
				return $e;
			}
		} catch (Throwable) {
			// The name cannot be read again: whatever is there is never named.
		}
		if ((new NewFileVisibility($this->visibilityGuard))->of($folder, $name) !== NewFileVisibility::VISIBLE) {
			return new FileCreationFailure(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createUnconfirmed($reason?->getMessage()), null, null, $reason);
		}
		$path = $this->visiblePath($folder, $name, $userId);
		return new FileCreationFailure(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createMayExist($reason?->getMessage(), $path), $path, null, $reason);
	}

	/**
	 * The failure of a step that ran after Nextcloud created the file, with the reason it keeps.
	 *
	 * @param Folder $folder the destination folder
	 * @param string $name the basename of the new file
	 * @param File $file the file Nextcloud created
	 * @param string|null $userId the actor
	 * @param Throwable $e what stopped the write: a readable refusal (type policy, lock check, storage), a native lock or
	 *                     full quota, or an unexplained failure (an unreadable policy included), which is logged by class only
	 * @return FileCreationFailure the reason and the file left behind
	 */
	private function failedAfterCreation(Folder $folder, string $name, File $file, ?string $userId, Throwable $e): FileCreationFailure {
		$reason = match (true) {
			$e instanceof ToolFailure => $e,
			$e instanceof LockedException => NodeAccess::lockFailure($e),
			$e instanceof NotEnoughSpaceException => new ToolFailure(CommonMessages::insufficientQuota()),
			default => null,
		};
		if ($reason === null) {
			// Only the class: the message of a storage exception may carry the path.
			$this->logger->error('MCP new file write failed: {exception_class} (code {exception_code})', ['app' => 'mcp', 'exception_class' => $e::class, 'exception_code' => ExceptionDetails::code($e)]);
		}
		return $this->leftBehind($folder, $name, $file, $userId, $reason);
	}

	/**
	 * The failure of a write that stopped after Nextcloud created the file, naming the file it left unless the rules
	 * hide it now ({@see NewFileVisibility}).
	 *
	 * @param Folder $folder the destination folder
	 * @param string $name the basename of the new file
	 * @param File $file the file Nextcloud created
	 * @param string|null $userId the actor, whose own view gives the path; null names the file by its basename only
	 * @param ToolFailure|null $reason the refusal that stopped the content write; null for an unexplained failure
	 * @return FileCreationFailure the reason, kept as the previous exception, and the file left behind
	 */
	private function leftBehind(Folder $folder, string $name, File $file, ?string $userId, ?ToolFailure $reason): FileCreationFailure {
		if ((new NewFileVisibility($this->visibilityGuard))->of($folder, $name) === NewFileVisibility::HIDDEN) {
			// Hidden since it was written: the failure says a file may exist and names nothing.
			return new FileCreationFailure(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createUnconfirmed($reason?->getMessage()), null, null, $reason);
		}
		$path = $this->visiblePath($folder, $name, $userId);
		$fileId = null;
		try {
			$fileId = (int)$file->getId();
		} catch (Throwable) {
			// The file is still named by its path; never a second failure on top of the first.
		}
		return new FileCreationFailure(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createLeftBehind($reason?->getMessage(), $path), $path, $fileId, $reason);
	}

	/**
	 * @param Folder $folder the destination folder
	 * @param string $name the basename of the file
	 * @param string|null $userId the actor, whose own view gives the path; null names the file by its basename only
	 * @return string the path of the file as the actor sees it, or its basename when that cannot be told
	 */
	private function visiblePath(Folder $folder, string $name, ?string $userId): string {
		try {
			$folderPath = $folder->getPath();
			$prefix = '/' . $userId . '/files';
			if ($userId !== null && ($folderPath === $prefix || str_starts_with($folderPath, $prefix . '/'))) {
				return rtrim(substr($folderPath, strlen($prefix)), '/') . '/' . $name;
			}
		} catch (Throwable) {
			// The basename is all that is known.
		}
		return $name;
	}
}
