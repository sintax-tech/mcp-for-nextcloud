<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Writing;

use OCP\IConfig;
use OCA\Mcp\Platform\Php\IniQuantity;

/** Per-call binary budget, independent of the existing text attachment reader. */
final class MailSaveBudget {
	/** Mail 5.10.11 native socket/MIME + fwrite measurement (PHP 8.5): fixed 4 MiB at 256 KiB;
	 * 2 MiB base64 4.3828 copies, QP 5.3203; 8 MiB 2.6211/3.5586.
	 * ceil(5.3203) + one spare = 7. Full IRootFolder/hooks remains an integration proof. */
	public const MEMORY_COPIES = 7;
	/** max(16 MiB, measured fixed 4 MiB + 4 MiB margin); metadata and native hooks also share this reserve. */
	public const RESERVE = 16 * 1024 * 1024;
	/** Conservative decoded budget when PHP declares unlimited memory. */
	public const UNLIMITED_BUDGET = 16 * 1024 * 1024;
	/** @param IConfig $config Existing checkout_max_bytes, with its existing 50 MiB default. */
	public function __construct(private IConfig $config) {
	}

	/** @return int Current effective decoded byte limit. */
	public function effective(): int {
		$max = (int)$this->config->getAppValue('mcp', 'checkout_max_bytes', (string)(50 * 1024 * 1024));
		return self::calculate($max > 0 ? $max : 50 * 1024 * 1024, (string)ini_get('memory_limit'), memory_get_usage(true));
	}

	/**
	 * @param int $checkout Configured limit.
	 * @param string $memory PHP memory limit.
	 * @param int $used Allocated bytes.
	 * @return int Decoded byte limit, zero when no budget remains.
	 */
	public static function calculate(int $checkout, string $memory, int $used): int {
		$quantity = IniQuantity::parse($memory);
		$available = $quantity->rejected() ? 0 : ($quantity->unlimited() ? self::UNLIMITED_BUDGET : max(0, intdiv(max(0, $quantity->bytes() - $used - self::RESERVE), self::MEMORY_COPIES)));
		return max(0, min($checkout, $available));
	}
}
