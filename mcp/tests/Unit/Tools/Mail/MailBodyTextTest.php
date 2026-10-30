<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tools\Mail\MailBodyText;
use PHPUnit\Framework\TestCase;

/**
 * The body text: the plain part when present, else the HTML turned into text without rendering; control and
 * bidirectional characters removed, line breaks kept, and a declared cut.
 */
final class MailBodyTextTest extends TestCase {
	/**
	 * The plain part wins over the HTML one, and is cleaned.
	 *
	 * @return void
	 */
	public function testThePlainPartIsUsedAndCleaned(): void {
		$body = MailBodyText::from("Line 1\r\nLine\u{202E} 2\u{0000}\tend", '<p>ignored</p>');
		$this->assertSame(['text' => "Line 1\nLine 2\tend", 'format' => 'plain', 'truncated' => false, 'totalChars' => 17], $body);
	}

	/**
	 * HTML loses scripts, styles, comments, head and tags, keeps the visible text and decodes entities.
	 *
	 * @return void
	 */
	public function testHtmlBecomesVisibleText(): void {
		$html = '<html><head><title>T</title><style>.x{}</style></head><body><!-- hidden --><h1>Agenda</h1>'
			. '<p>Room&nbsp;4 &amp; <a href="https://evil.example/">lunch</a></p><script>steal()</script><ul><li>One</li><li>Two</li></ul>'
			. '<img src="https://tracker.example/pixel.gif"></body></html>';
		$body = MailBodyText::from('', $html);
		$this->assertSame('html', $body['format']);
		$this->assertSame("Agenda\n\nRoom 4 & lunch\n\nOne\n\nTwo", $body['text']);
		$this->assertStringNotContainsString('steal', $body['text']);
		$this->assertStringNotContainsString('tracker', $body['text']);
	}

	/**
	 * A long body is cut to the requested length and the cut is declared, never above the absolute limit.
	 *
	 * @return void
	 */
	public function testALongBodyIsCutAndSaysSo(): void {
		$body = MailBodyText::from(str_repeat('é', 50), null, 10);
		$this->assertSame(str_repeat('é', 10), $body['text']);
		$this->assertTrue($body['truncated']);
		$this->assertSame(50, $body['totalChars']);
		$this->assertSame(MailBodyText::MAX_CHARS, mb_strlen(MailBodyText::from(str_repeat('a', MailBodyText::MAX_CHARS + 5), null, PHP_INT_MAX)['text']));
	}

	/**
	 * A body PCRE cannot process is kept and stripped of tags instead of becoming empty.
	 *
	 * @return void
	 */
	public function testAHostileBodyNeverBecomesEmpty(): void {
		$limit = ini_get('pcre.backtrack_limit');
		ini_set('pcre.backtrack_limit', '10');
		try {
			$text = MailBodyText::htmlToText('<script>' . str_repeat('x', 2000) . '</scrip><p>visible</p>');
		} finally {
			ini_set('pcre.backtrack_limit', (string)$limit);
		}
		$this->assertStringContainsString('visible', $text);
		$this->assertStringNotContainsString('<p>', $text);
	}
}
