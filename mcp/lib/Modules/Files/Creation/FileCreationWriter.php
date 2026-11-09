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

/** Final creation boundary; no empty node is created for a denied name. */
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
	 * @throws ToolFailure If the destination, policy, lock or storage refuses.
	 * @throws ArgumentValidationException If storage refuses the final basename.
	 */
	public function write(Folder $folder, string $name, mixed $content, ?string $userId, bool $asUser): File {
		FileWriteBoundary::check($this->fileTypes, $name);
		if ($folder->nodeExists($name)) {
			throw new FileExists(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::fileExists());
		}
		try {
			$file = NodeAccess::requireFile(NodeAccess::mutate(fn () => $folder->newFile($name), $this->visibilityGuard));
		} catch (InvalidPathException) {
			throw new ArgumentValidationException('Invalid argument: path', 'path', \OCA\Mcp\Modules\Files\Presentation\CreationMessages::newFileNameRule());
		}
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
		try {
			FileWriteBoundary::check($this->fileTypes, $current->getName());
			$put = static fn () => $current->putContent($content);
			NodeAccess::mutate(fn () => $userId === null ? $put() : $this->locks->run($put, $current, $userId, null, $asUser), $this->visibilityGuard);
		} catch (ToolFailure $e) {
			// Already a readable refusal: a lock found by the check, or one the storage raised, explained.
			throw $e;
		} catch (LockedException|NotEnoughSpaceException $e) {
			// The two failures the agent can act on keep the message NodeAccess::run() gives them everywhere else.
			NodeAccess::run(static fn () => throw $e);
		} catch (Throwable $e) {
			// Only the class: the message of a storage exception may carry the path.
			$this->logger->error('MCP new file write failed: {exception_class} (code {exception_code})', ['app' => 'mcp', 'exception_class' => $e::class, 'exception_code' => ExceptionDetails::code($e)]);
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createFailed());
		}
		return $current;
	}
}
