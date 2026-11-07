<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

/** Makes user text valid UTF8 and removes controls; it remains untrusted plain data, never rendered or fetched. */
final class FormsText {
	/** Cleans user-controlled text without cutting; the response budget reports any later cut.
	 * @param mixed $value Text from a native field.
	 * @return string Plain UTF8 data, not HTML execution or instructions.
	 */
	public static function clean(mixed $value): string {
		$text = mb_scrub(is_scalar($value) ? (string)$value : '', 'UTF-8');
		return (string)preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
	}

	/** Wraps the complete read shape as untrusted data, including titles and nested options/messages.
	 * @param array<string,mixed> $data Whitelisted form data.
	 * @return array<string,mixed> Explicit untrusted envelope.
	 */
	public static function envelope(array $data): array { return ['untrusted' => ['notice' => FormsMessages::NOTICE] + $data]; }
}
