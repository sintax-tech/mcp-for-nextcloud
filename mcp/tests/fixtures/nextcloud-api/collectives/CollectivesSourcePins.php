<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** The audited vendor must be exactly the packages locked in the SHA-pinned Collectives archive. */
final class CollectivesSourcePins {
	/**
	 * @param string $lock the composer.lock bytes from the verified archive
	 * @param array<string, mixed> $pin the release's source pins
	 * @return void rejects a changed lock, version or source reference
	 */
	public static function check(string $lock, array $pin): void {
		if (hash('sha256', $lock) !== $pin['composerLockSha256']) {
			throw new RuntimeException('Collectives composer.lock SHA mismatch');
		}
		$packages = array_column(json_decode($lock, true, 512, JSON_THROW_ON_ERROR)['packages'], null, 'name');
		foreach ($pin['vendor'] ?? [] as $name => $vendor) {
			if (($packages[$name]['version'] ?? null) !== $vendor['version']
				|| ($packages[$name]['source']['reference'] ?? null) !== $vendor['reference']) {
				throw new RuntimeException("$name is not locked at the pinned version and reference");
			}
		}
	}
}
