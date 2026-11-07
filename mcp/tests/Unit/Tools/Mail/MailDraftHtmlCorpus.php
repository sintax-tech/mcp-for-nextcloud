<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Mail;

/** Expected sanitized body of a corpus row for the libxml HTML parser running the test. */
final class MailDraftHtmlCorpus {
	/** First libxml version whose HTML parser is the HTML5 tokenizer, which inserts no paragraph around bare text. */
	public const HTML5_PARSER = 21400;

	/** Static helper only. @return void */
	private function __construct() {}

	/**
	 * Legacy parsers wrap bare top-level text in a paragraph; the HTML5 tokenizer keeps it bare. Both are safe.
	 *
	 * @param array<string,mixed> $row corpus row with a saved body
	 * @return string expected saved body on this libxml
	 */
	public static function body(array $row): string {
		return LIBXML_VERSION >= self::HTML5_PARSER && isset($row['bodyHtml5']) ? $row['bodyHtml5'] : $row['body'];
	}
}
