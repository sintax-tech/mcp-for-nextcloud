<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Extraction;

use OCA\Mcp\Platform\Php\IniQuantity;
use OCP\IConfig;

/**
 * Administrator ceiling for reading one file into memory.
 *
 * The value is stored in bytes under {@see self::MAX_BYTES_KEY} and edited in whole MiB on the
 * administration page, so it never needs occ. {@see self::DEFAULT_MAX_BYTES} applies when nothing
 * valid is stored.
 *
 * The memory ceiling only limits a setting above that default. A configured value at or below the
 * default is used as stored, so a small memory_limit cannot make 1.3.4 read less than 1.3.3 did.
 * A higher setting is capped by the heuristic below, and never below the default:
 * configured <= default ? configured : max(default, min(configured, ceiling)).
 *
 * {@see self::MEMORY_FACTOR} and {@see self::MEMORY_RESERVE_BYTES} are a heuristic, not a measurement.
 * The factor of six stands for the raw bytes, the decode allowance {@see TextExtractor} passes as four
 * times the file, and about one more copy for the parser. smalot/pdfparser applies that decode allowance
 * to each stream (FilterHelper), not as one budget for the whole file. The reserve is space left for
 * Nextcloud, the request and the extracted text. A memory_limit of -1, or an empty value, means PHP
 * imposes no ceiling and the configured value is used whole. A directive PHP cannot read falls back to
 * the default and never to zero bytes.
 *
 * The zip-entry cap is not this ceiling. {@see self::ZIP_ENTRY_MAX_BYTES} stays fixed so raising the
 * file limit cannot inflate one DOCX or ODT entry without bound.
 */
final class FileReadLimit {
	/** File-read ceiling used when the administrator configured none, in bytes. */
	public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;
	/** Largest zip entry inflated from a DOCX or ODT, in bytes. Independent of the file ceiling. */
	public const ZIP_ENTRY_MAX_BYTES = 20 * 1024 * 1024;
	/** App config key holding the file-read ceiling in bytes. */
	public const MAX_BYTES_KEY = 'files_read_max_bytes';
	/**
	 * Heuristic copies of the file a PDF read may hold. Not measured. The decode allowance counted here
	 * is applied by the parser per stream, not once for the file.
	 */
	public const MEMORY_FACTOR = 6;
	/** Heuristic bytes left free for Nextcloud, the request and the extracted text. Not measured. */
	public const MEMORY_RESERVE_BYTES = 32 * 1024 * 1024;

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @return int file-read ceiling the administrator set, in bytes; {@see self::DEFAULT_MAX_BYTES} when none or an invalid one is stored
	 */
	public function configuredMaxBytes(): int {
		$configured = (int)$this->config->getAppValue('mcp', self::MAX_BYTES_KEY, (string)self::DEFAULT_MAX_BYTES);
		return $configured > 0 ? $configured : self::DEFAULT_MAX_BYTES;
	}

	/**
	 * @return int effective file-read ceiling in bytes
	 */
	public function maxBytes(): int {
		return self::effective($this->configuredMaxBytes(), (string)ini_get('memory_limit'));
	}

	/**
	 * @param int $configured stored ceiling in bytes, already known to be positive
	 * @param string $memoryLimit php.ini memory_limit text
	 * @return int effective ceiling; a setting at or below the default is kept, and an unreadable directive never lowers a higher setting to zero
	 */
	public static function effective(int $configured, string $memoryLimit): int {
		$parsed = IniQuantity::parse($memoryLimit);
		if ($parsed->unlimited()) {
			return $configured;
		}
		if ($parsed->rejected() || $parsed->bytes() <= 0) {
			return $configured <= self::DEFAULT_MAX_BYTES ? $configured : self::DEFAULT_MAX_BYTES;
		}
		return self::cap($configured, self::ceilingBytes($parsed->bytes()));
	}

	/**
	 * @return int memory_limit in bytes, 0 when PHP sets none, rejects the directive, or it is not a positive size
	 */
	public function phpMaxBytes(): int {
		$parsed = IniQuantity::parse((string)ini_get('memory_limit'));
		if ($parsed->unlimited() || $parsed->rejected() || $parsed->bytes() <= 0) {
			return 0;
		}
		return $parsed->bytes();
	}

	/**
	 * @return bool true when memory_limit is -1 or empty, so PHP adds no ceiling of its own
	 */
	public function phpUnlimited(): bool {
		return IniQuantity::parse((string)ini_get('memory_limit'))->unlimited();
	}

	/**
	 * Largest file the heuristic still allows in a process whose memory_limit is $memoryLimitBytes.
	 *
	 * @param int $memoryLimitBytes parsed memory_limit, never the unlimited sentinel
	 * @return int bytes, 0 when the reserve already uses the whole limit
	 */
	public static function ceilingBytes(int $memoryLimitBytes): int {
		if ($memoryLimitBytes <= self::MEMORY_RESERVE_BYTES) {
			return 0;
		}
		return intdiv($memoryLimitBytes - self::MEMORY_RESERVE_BYTES, self::MEMORY_FACTOR);
	}

	/** @return int $configured when it is at or below the default, otherwise $configured capped at $ceiling but not below the default */
	private static function cap(int $configured, int $ceiling): int {
		if ($configured <= self::DEFAULT_MAX_BYTES) {
			return $configured;
		}
		return max(self::DEFAULT_MAX_BYTES, min($configured, $ceiling));
	}
}
