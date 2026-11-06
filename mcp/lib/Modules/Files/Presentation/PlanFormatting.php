<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Formats inert Markdown names, excerpts, sizes and dates for Files plans. */
final class PlanFormatting {
	/** Longest excerpt of file content shown, in characters. */
	private const EXCERPT = 200;
	/** Longest path or name shown in bold, in characters. */
	private const NAME = 400;

	/**
	 * @return string the instant as the person reads it, in their timezone when the plan names one
	 * @param string $iso iso
	 * @param string|null $timezone timezone
	 */
	public static function moment(string $iso, ?string $timezone): string {
		try {
			$date = new \DateTimeImmutable($iso);
			if ($timezone !== null) {
				$date = $date->setTimezone(new \DateTimeZone($timezone));
			}
			return $date->format(Translator::t('m/d/Y H:i'));
		} catch (\Throwable) {
			return PlanText::inline($iso);
		}
	}

	/**
	 * @return string the text shortened to the excerpt limit, free of Markdown and line breaks
	 * @param string $text text
	 */
	public static function excerpt(string $text): string {
		return PlanText::inline($text, self::EXCERPT);
	}

	/**
	 * @param string $text text
	 * @return string message text
	 */
	public static function quote(string $text): string {
		return '«' . self::excerpt($text) . '»';
	}

	/**
	 * @return string a file, folder or person name in bold, inert as Markdown
	 * @param string $text text
	 */
	public static function bold(string $text): string {
		return PlanText::strong($text, self::NAME);
	}

	/**
	 * @param int $bytes bytes
	 * @return string message text
	 */
	public static function bytes(int $bytes): string {
		foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
			if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
				return ($i === 0 ? (string)$bytes : rtrim(rtrim(number_format($bytes / 1024 ** $i, 1, '.', ''), '0'), '.')) . ' ' . $unit;
			}
		}
		return $bytes . ' B';
	}

	/**
	 * @return string|null the non-empty string under $key
	 * @param array<string, mixed> $plan plan
	 * @param string $key key
	 */
	public static function text(array $plan, string $key): ?string {
		return isset($plan[$key]) && is_string($plan[$key]) && $plan[$key] !== '' ? $plan[$key] : null;
	}

	/**
	 * @return list<string> the string members of a list
	 * @param mixed $value value
	 */
	public static function strings(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}
}
