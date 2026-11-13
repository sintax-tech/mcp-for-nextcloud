<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Backups\BackupMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCP\Files\File;
use OCP\Files\Folder;
use RuntimeException;
use Throwable;

/**
 * Reads a replaced file and its pre-write copy back. Any doubt is reported as uncertain; nothing is repeated,
 * restored or deleted.
 */
final class ReplacementReceipt {
	/**
	 * @param FilesAccessGuard $visibility Current scope and exclusion tags of the replaced file.
	 * @param NodeAccessInfo $access Access description after the write.
	 */
	public function __construct(private FilesAccessGuard $visibility, private NodeAccessInfo $access) {
	}

	/**
	 * @param Folder $root Actor root.
	 * @param string $userId Actor UID.
	 * @param string $path Normalized actor-relative path of the replaced file.
	 * @param string $content Bytes that were written.
	 * @param array{fileId:int, size:int, etag:string} $previous The file as found right before the copy.
	 * @param string $copy Verified copy path, or empty for the authorized suspension.
	 * @param bool $uncertain Whether the write itself failed unexpectedly.
	 * @return array<string,mixed> Path, size, etag, fileId, access and SHA-256 as read back, the previous file, the
	 *         copy and whether all of it matches.
	 * @throws PolicyUnavailable When the Files scope cannot be read.
	 */
	public function read(Folder $root, string $userId, string $path, string $content, array $previous, string $copy, bool $uncertain): array {
		$base = [
			'path' => $path,
			'replaced' => $previous,
			'backup' => $copy,
			'backupNote' => BackupMessages::originalKept($copy),
		];
		[$backupVerified, $backupSize] = $this->copy($root, $copy, $previous['size']);
		$base += ['backupVerified' => $backupVerified] + ($backupSize === null ? [] : ['backupSize' => $backupSize]);
		if ($uncertain) {
			return $base + ['outcome' => 'uncertain', 'verified' => false];
		}
		try {
			$file = $this->file($root, $path);
			$receipt = [
				'size' => (int)$file->getSize(),
				'etag' => (string)$file->getEtag(),
				'fileId' => (int)$file->getId(),
				'access' => $this->access->describe($file, $userId),
			];
			$actual = $this->hash($file);
			$after = $this->file($root, $path);
			$verified = $receipt['fileId'] === $previous['fileId']
				&& (int)$after->getId() === $receipt['fileId']
				&& (int)$after->getSize() === $receipt['size']
				&& (string)$after->getEtag() === $receipt['etag']
				&& $receipt['size'] === strlen($content)
				&& $receipt['etag'] !== ''
				&& hash_equals(hash('sha256', $content), $actual)
				&& $backupVerified;
			return $base + $receipt + ['sha256' => $actual, 'outcome' => $verified ? 'replaced' : 'uncertain', 'verified' => $verified];
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (Throwable) {
			return $base + ['outcome' => 'uncertain', 'verified' => false];
		}
	}

	/**
	 * The copy is read again by its path: it must still be a file of the original size. A suspension has no copy.
	 *
	 * @param Folder $root Actor root.
	 * @param string $copy Copy path, or empty.
	 * @param int $size Size of the original right before the copy.
	 * @return array{0: bool, 1: int|null} Whether the copy matches, and its size as read back.
	 */
	private function copy(Folder $root, string $copy, int $size): array {
		if ($copy === '') {
			return [true, null];
		}
		try {
			// The copy may lie outside a restricted scope; it is read by the path FileBackup returned, never listed.
			$node = NodeAccess::requireFile($root->get(ltrim($copy, '/')));
			$read = (int)$node->getSize();
			return [$read === $size, $read];
		} catch (Throwable) {
			return [false, null];
		}
	}

	/**
	 * @param Folder $root Actor root.
	 * @param string $path Normalized actor-relative path.
	 * @return File The visible file at the path.
	 * @throws Throwable When it cannot be resolved, is hidden or is not a file.
	 */
	private function file(Folder $root, string $path): File {
		return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibility));
	}

	/**
	 * @param File $file File to hash by stream.
	 * @return string SHA-256 of its current bytes.
	 * @throws RuntimeException When the stream cannot be opened.
	 */
	private function hash(File $file): string {
		$stream = $file->fopen('r');
		if (!is_resource($stream)) {
			throw new RuntimeException();
		}
		try {
			$hash = hash_init('sha256');
			hash_update_stream($hash, $stream);
			return hash_final($hash);
		} finally {
			fclose($stream);
		}
	}
}
