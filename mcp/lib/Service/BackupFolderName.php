<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

/** Canonical name identity used by the core filesystem's default Unicode normalization. */
final class BackupFolderName {
	/**
	 * @param string $name a single filesystem name, before length/core validation
	 * @return string NFC name, never the unnormalized input on failure
	 * @throws \InvalidArgumentException when normalization is unavailable or rejects the input
	 */
	public static function normalize(string $name): string {
		try {
			$value = \Normalizer::normalize($name, \Normalizer::FORM_C);
			if (!is_string($value)) {
				throw new \RuntimeException('Normalization failed');
			}
			return $value;
		} catch (\Throwable) {
			throw new \InvalidArgumentException('Invalid backup folder name');
		}
	}

	/**
	 * @param string|null $name node name; a nameless ancestor cannot be a named candidate
	 * @param list<string> $reserved already canonical reserved names
	 * @return bool whether this named ancestor is reserved, preserving existing handling of nameless nodes
	 * @throws \InvalidArgumentException if an actual name cannot be normalized
	 */
	public static function reserved(?string $name, array $reserved): bool {
		return $name !== null && in_array(self::normalize($name), $reserved, true);
	}
}
