<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Runs in the disposable container, before enabling the release. Never use app:enable --force.
[$script, $path, $major, $version] = $argv;
$xml = simplexml_load_file($path);
if ($xml === false || (string)$xml->id !== 'deck' || (string)$xml->version !== $version
	|| (string)$xml->dependencies->nextcloud['min-version'] !== $major
	|| (string)$xml->dependencies->nextcloud['max-version'] !== $major) {
	fwrite(STDERR, "Deck manifest does not match pinned version $version / Nextcloud $major\n");
	exit(1);
}
echo "Deck manifest verified: $version / Nextcloud $major\n";
