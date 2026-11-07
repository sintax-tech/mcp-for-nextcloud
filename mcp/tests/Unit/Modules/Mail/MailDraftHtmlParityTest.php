<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Creation\{MailDraftHtml, MailDraftHtmlPurifier};
use OCA\Mcp\Modules\Mail\Integration\MailDraftHtmlEngine;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Tests\Unit\Tools\Mail\MailDraftHtmlCorpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same expected outcome on legacy libxml and libxml 2.14+ (HTML5 tokenizer); CI runs it on both.
 *
 * Each source either is refused or saves the same body and the same report, except the paragraph legacy parsers
 * insert around bare text (bodyHtml5). The fixture holds the 1.3.1 security review reproductions; refusedByEngine
 * marks sources only the real engine's restructuring refuses, on both parsers alike.
 */
final class MailDraftHtmlParityTest extends TestCase {
	/** @return iterable<string,array{array<string,mixed>}> fixture rows by position */
	public static function rows(): iterable {
		$rows = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/mail-draft-html-parity.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($rows as $index => $row) {
			yield 'parity-' . $index => [$row];
		}
	}

	/** @param array<string,mixed> $row expected outcome of one source @return void */
	#[DataProvider('rows')]
	public function testBothParsersGiveTheSameOutcome(array $row): void {
		$real = $this->real();
		// The real engine restructures some trees the identity engine keeps; the inventory check then refuses them.
		$refused = ($row['refused'] ?? false) || ($real && ($row['refusedByEngine'] ?? false));
		try {
			$result = (new MailDraftHtml($this->engine($real)))->sanitize($row['input']);
		} catch (ToolFailure) {
			$this->assertTrue($refused, 'refused: ' . json_encode($row['input'], JSON_UNESCAPED_UNICODE));
			return;
		}
		$this->assertFalse($refused, 'accepted: ' . json_encode($row['input'], JSON_UNESCAPED_UNICODE));
		$this->assertSame(MailDraftHtmlCorpus::body($row), $result['body']);
		$this->assertSame($row['removed'], array_filter($result['removed']));
		$this->assertSame($row['removedUrls'] ?? [], $result['removedUrls']);
	}

	/** @return bool whether MCP_MAIL_HTML_LIBRARY names the pinned real engine */
	private function real(): bool {
		$library = getenv('MCP_MAIL_HTML_LIBRARY');
		return $library !== false && $library !== '';
	}

	/** @param bool $real whether to load the real engine @return MailDraftHtmlPurifier identity or real engine */
	private function engine(bool $real): MailDraftHtmlPurifier {
		if ($real) {
			require_once getenv('MCP_MAIL_HTML_LIBRARY');
			return new MailDraftHtmlEngine();
		}
		return new class implements MailDraftHtmlPurifier {
			public function purify(string $html): string { return $html; }
		};
	}
}
