<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;

use PHPUnit\Framework\TestCase;

/** Decodes the saved native MIME independently of the confirmation renderer. */
final class MailDraftHtmlMime {
	/**
	 * @param TestCase $test running cell test
	 * @param string $raw message read from Dovecot
	 * @param string $expected complete sanitized fragment approved in the plan
	 * @return void
	 */
	public static function assertSaved(TestCase $test, string $raw, string $expected): void {
		$mime = \Horde_Mime_Part::parseMessage($raw, ['forcemime' => true]);
		$test->assertSame('multipart/alternative', $mime->getType());
		$parts = [];
		foreach ($mime->getParts() as $part) {
			$parts[$part->getType()] = (string)$part->getContents();
		}
		$test->assertArrayHasKey('text/plain', $parts);
		$test->assertArrayHasKey('text/html', $parts);
		// Horde serializes MIME with CRLF. It does not otherwise change the fragment.
		$test->assertSame(str_replace("\r\n", "\n", $expected), str_replace("\r\n", "\n", $parts['text/html']));
		$test->assertStringNotContainsString('<script', $parts['text/html']);
		$test->assertStringNotContainsString('<img', $parts['text/html']);
		$test->assertStringNotContainsString('style=', $parts['text/html']);
		$test->assertStringContainsString('Olá', $parts['text/plain']);
	}
}
