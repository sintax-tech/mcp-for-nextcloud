<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Platform\FileAccess\{ActorRoot, NewFileDestination, NodeAccessInfo, SharedWriteGuard, NodeAccess, CommonMessages};
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCP\Files\NotEnoughSpaceException;
use OCP\Lock\LockedException;
use Throwable;
use RuntimeException;

/** Reuses Files creation and its final policy/lock boundary for other modules. No replacement or deletion. */
final class ProtectedFileDestination implements NewFileDestination {
	/**
	 * @param ActorRoot $roots Bound actor roots.
	 * @param FileCreation $creation Existing creation flow.
	 * @param SharedWriteGuard $shared Shared guard.
	 * @param NodeAccessInfo $access Access metadata.
	 * @param FilesAccessGuard $visibility Current scope and visibility.
	 * @param LoggerInterface $logger Safe storage diagnostics.
	 */
	public function __construct(
		private ActorRoot $roots,
		private FileCreation $creation,
		private SharedWriteGuard $shared,
		private NodeAccessInfo $access,
		private FilesAccessGuard $visibility,
		private LoggerInterface $logger,
	) {
	}

	/** {@inheritDoc} */
	public function inspect(string $userId, string $path): array {
		$this->visibility->clearCache();
		$this->visibility->assertScopeReadable();
		$target = $this->creation->target($this->roots->get($userId), $path);
		FileCreation::assertCreatable($target['folder']);
		$shared = $this->shared->guard($target['folder'], $userId, $target['path'], false);
		return [
			'path' => $target['path'],
			'folderId' => (int)$target['folder']->getId(),
			'access' => $this->access->describe($target['folder'], $userId),
			'shared' => $shared === null ? [] : [$shared],
			'requiresSharedConfirmation' => $shared !== null,
		];
	}

	/** {@inheritDoc} */
	public function create(string $userId, string $path, string $content, bool $shared, int $folderId): array {
		$this->visibility->clearCache();
		$this->visibility->assertScopeReadable();
		$target = $this->creation->target($this->roots->get($userId), $path);
		if ((int)$target['folder']->getId() !== $folderId) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		if (($confirmation = $this->shared->guard($target['folder'], $userId, $target['path'], $shared)) !== null) {
			return $confirmation;
		}
		FileCreation::assertCreatable($target['folder']);
		$expected = hash('sha256', $content);
		// Creation failures may leave an empty or partial node. Never delete it or retry the sink.
		try {
			$written = $this->creation->write($target['folder'], $target['name'], $content, $userId);
		} catch (FileCreationFailure) {
			return ['path' => $target['path'], 'outcome' => 'uncertain', 'verified' => false];
		} catch (ToolFailure|ArgumentValidationException|PolicyUnavailable $e) {
			throw $e;
		} catch (LockedException|NotEnoughSpaceException $e) {
			NodeAccess::run(static fn () => throw $e);
		} catch (Throwable $e) {
			$this->logger->error('MCP attachment save write failed: {exception_class} (code {exception_code})', [
				'app' => 'mcp',
				'exception_class' => $e::class,
				'exception_code' => ExceptionDetails::code($e),
			]);
			return ['path' => $target['path'], 'outcome' => 'uncertain', 'verified' => false];
		}
		try {
			$writtenId = (int)$written->getId();
			$file = NodeAccess::requireFile($target['folder']->get($target['name']));
			$this->visibility->assertVisible($file);
			$receipt = $this->creation->receipt($target, $file, $userId);
			if (!str_starts_with($file->getPath(), '/' . $userId . '/files/')) {
				throw new RuntimeException();
			}
			$readPath = '/' . ltrim(substr($file->getPath(), strlen('/' . $userId . '/files')), '/');
			if ($readPath !== $target['path']) {
				throw new RuntimeException();
			}
			$receipt['path'] = $readPath;
			$stream = $file->fopen('r');
			if (!is_resource($stream)) {
				throw new RuntimeException();
			}
			try {
				$hash = hash_init('sha256');
				hash_update_stream($hash, $stream);
				$actual = hash_final($hash);
			} finally {
				fclose($stream);
			}
			$after = NodeAccess::requireFile($target['folder']->get($target['name']));
			$this->visibility->assertVisible($after);
			$verified = (int)$after->getId() === $receipt['fileId']
				&& (int)$after->getSize() === $receipt['size']
				&& (string)$after->getEtag() === $receipt['etag']
				&& $writtenId === $receipt['fileId']
				&& $receipt['size'] === strlen($content)
				&& $receipt['etag'] !== ''
				&& hash_equals($expected, $actual)
				&& (string)$file->getEtag() === $receipt['etag'];
			return $receipt + ['outcome' => $verified ? 'saved' : 'uncertain', 'verified' => $verified, 'sha256' => $actual];
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (Throwable) {
			return ['path' => $target['path'], 'outcome' => 'uncertain', 'verified' => false];
		}
	}
}
