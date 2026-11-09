<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Php;

/**
 * A php.ini size, read with {@see ini_parse_quantity()} so the app and PHP agree.
 *
 * An unknown multiplier makes PHP warn and keep the leading number: "256MB" is 256 bytes, not 256 MiB.
 * That warning is swallowed here and does not reach the log. A value that warning reduces to nothing
 * ("nope") is rejected, so a caller must not treat the failure as zero bytes. An empty value and -1
 * name no ceiling.
 */
final class IniQuantity {
	private function __construct(
		private readonly ?int $bytes,
		private readonly bool $rejected,
	) {
	}

	/**
	 * @param string $value directive text, such as "128M", "1G", "4096" or "-1"
	 */
	public static function parse(string $value): self {
		$value = trim($value);
		if ($value === '' || $value === '-1') {
			return new self(null, false);
		}
		$warned = false;
		set_error_handler(static function () use (&$warned): bool {
			$warned = true;
			return true;
		});
		try {
			$parsed = ini_parse_quantity($value);
		} finally {
			restore_error_handler();
		}
		if ($warned && $parsed <= 0) {
			return new self(null, true);
		}
		return new self($parsed, false);
	}

	/** @return bool true for -1 and an empty value, the directives that name no ceiling */
	public function unlimited(): bool {
		return $this->bytes === null && !$this->rejected;
	}

	/** @return bool true when PHP warned and left no positive size */
	public function rejected(): bool {
		return $this->rejected;
	}

	/**
	 * @return int the size in bytes; 0 when the directive is unlimited or was rejected
	 */
	public function bytes(): int {
		return $this->bytes ?? 0;
	}
}
