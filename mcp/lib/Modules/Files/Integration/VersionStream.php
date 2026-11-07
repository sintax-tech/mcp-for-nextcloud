<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Integration;

use OCP\Files\File;
use OCP\Files\NotFoundException;

/**
 * Opens the bytes of a version that files_versions listed.
 *
 * Since Nextcloud 26 the legacy backend lists the current content as a version whose revision is the file's
 * mtime (`LegacyVersionsBackend::getVersionsForFile`, `$currentVersion`), but it keeps no `.v<mtime>` file for
 * it, so `read()` of that version throws NotFoundException. That version's bytes are the file itself. Every
 * other version is read through files_versions, as before.
 */
final class VersionStream {
	/**
	 * @param object $manager the files_versions IVersionManager
	 * @param object $version the IVersion to read, as getVersionsForFile() returned it
	 * @param File $file the file the version belongs to
	 * @return resource|false the stream the backend hands out, or the file's own stream for the current version
	 * @throws NotFoundException when a version other than the current one has no stored file
	 */
	public static function open(object $manager, object $version, File $file) {
		try {
			return $manager->read($version);
		} catch (NotFoundException $e) {
			if (!self::isCurrent($version, $file)) {
				throw $e;
			}
			return $file->fopen('r');
		}
	}

	/**
	 * @param object $version the IVersion, as getVersionsForFile() returned it
	 * @param File $file the file the version belongs to
	 * @return bool whether the version is the current content, listed under the file's mtime
	 */
	public static function isCurrent(object $version, File $file): bool {
		return (string)$version->getRevisionId() === (string)$file->getMTime();
	}
}
