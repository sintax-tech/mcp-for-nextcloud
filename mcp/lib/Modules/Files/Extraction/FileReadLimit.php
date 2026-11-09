<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Extraction;

use OCP\IConfig;

/**
 * Administrator ceiling for reading one file into memory.
 *
 * The value is stored in bytes under {@see self::MAX_BYTES_KEY} and edited in whole MiB on the
 * administration page, so it never needs occ. {@see self::DEFAULT_MAX_BYTES} applies when nothing
 * valid is stored.
 *
 * The effective ceiling is that value, or lower when PHP's memory_limit cannot pay for a PDF.
 * Peak use is budgeted as {@see self::MEMORY_FACTOR} times the file: the raw bytes (1×), the decode
 * budget {@see TextExtractor} sets to four times the file, and about one further copy for the parser
 * object graph. {@see self::MEMORY_RESERVE_BYTES} stays free for Nextcloud, the request and the
 * extracted text. A memory_limit of -1, or an empty value, means PHP imposes no ceiling and the
 * configured value is used whole. A limit that leaves nothing after the reserve caps the read at
 * zero bytes. A value that is not a size is not treated as unlimited: the read is capped at zero
 * rather than guessing.
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
	 * Copies of the file a PDF read is budgeted to hold at once: the bytes, a 4× decode budget and
	 * about one more copy for the object graph.
	 */
	public const MEMORY_FACTOR = 6;
	/** Bytes left free for Nextcloud, the request and the extracted text. */
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
	 * @return int effective file-read ceiling in bytes: the configured one, never above what memory_limit can hold
	 */
	public function maxBytes(): int {
		$configured = $this->configuredMaxBytes();
		if ($this->phpUnlimited()) {
			return $configured;
		}
		return min($configured, self::ceilingBytes($this->phpMaxBytes()));
	}

	/**
	 * @return int memory_limit in bytes, 0 when PHP sets none or the directive cannot be parsed
	 */
	public function phpMaxBytes(): int {
		return self::parseMemoryLimit((string)ini_get('memory_limit'));
	}

	/**
	 * @return bool true when memory_limit is -1 or empty, so PHP adds no ceiling of its own
	 */
	public function phpUnlimited(): bool {
		return self::isUnlimited((string)ini_get('memory_limit'));
	}

	/**
	 * @param string $value php.ini memory_limit, such as "-1", "128M" or "1G"
	 * @return bool true when the directive means PHP imposes no memory ceiling
	 */
	public static function isUnlimited(string $value): bool {
		$value = trim($value);
		return $value === '' || $value === '-1';
	}

	/**
	 * @param string $value php.ini memory_limit, such as "-1", "128M", "256MB" or "1G"
	 * @return int the size in bytes, 0 when unlimited, empty or not a size
	 */
	public static function parseMemoryLimit(string $value): int {
		$value = trim($value);
		if (self::isUnlimited($value) || !preg_match('/^(\d+)\s*([KMG]?)B?$/i', $value, $matches)) {
			return 0;
		}
		return (int)$matches[1] * match (strtoupper($matches[2])) {
			'K' => 1024,
			'M' => 1024 ** 2,
			'G' => 1024 ** 3,
			default => 1,
		};
	}

	/**
	 * Largest file that still fits in a process whose memory_limit is $memoryLimitBytes.
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
}
