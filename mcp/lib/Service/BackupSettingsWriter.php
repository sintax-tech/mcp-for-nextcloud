<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Service\Policy\PolicyWriter;
use OCP\IAppConfig;
use OCP\Files\IFilenameValidator;

/** Saves the entire backup document under the existing policy mutex and revision transaction. */
final class BackupSettingsWriter {
	/**
	 * @param BackupLocation $location live reader of the one canonical document
	 * @param BackupCopies $copies native versioning admission check
	 * @param PolicyWriter $writer existing revisioned transaction, never a second mutex
	 * @param IAppConfig $config official writer sharing PolicyWriter's database connection
	 * @param IFilenameValidator $filenames official server filename restrictions
	 */
	public function __construct(private BackupLocation $location, private BackupCopies $copies, private PolicyWriter $writer,
		private IAppConfig $config, private IFilenameValidator $filenames) {
	}

	/**
	 * @param string $revision canonical revision supplied by the authenticated administrator
	 * @param string $folder single root name, not a path
	 * @param bool $copies whether to retain mandatory pre-write copies
	 * @return array<string, mixed> existing policy command receipt
	 * @throws \InvalidArgumentException for invalid name or unavailable native versioning
	 * @throws \Throwable on a revision conflict or configuration/storage failure
	 */
	public function save(string $revision, string $folder, bool $copies): array {
		$folder = BackupFolderName::normalize($folder);
		BackupLocation::validate($folder, $this->filenames);
		try {
			return $this->writer->runAdmin($revision, [], static fn () => null, function () use ($folder, $copies): bool {
				if (!$copies && !$this->copies->versionsAvailable()) {
					throw new \InvalidArgumentException('Native versioning must be enabled');
				}
				$before = $this->location->current();
				$after = $before;
				$after['folder'] = $folder;
				$after['copies'] = $copies;
				$after['reserved'] = array_values(array_unique([...$before['reserved'], $folder]));
				if ($after === $before) {
					return false;
				}
				$this->config->setValueString('mcp', BackupLocation::KEY, json_encode($after, JSON_THROW_ON_ERROR), false);
				if ($this->location->current() !== $after) {
					throw new \RuntimeException('Backup settings verification failed');
				}
				return true;
			});
		} finally {
			$this->location->invalidate();
		}
	}
}
