<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Modules\Mail\Creation\{MailDraftHtml, MailDraftHtmlPurifier, MailDraftRequest};
use OCA\Mcp\Modules\Mail\Integration\MailDraftHtmlEngine;
use OCA\Mcp\Modules\Mail\Presentation\{MailDraftHtmlView, MailDraftMessages};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\Attributes\{DataProvider, RunInSeparateProcess};
use PHPUnit\Framework\TestCase;

/** Unit policy corpus; MCP_MAIL_HTML_LIBRARY also exercises the same corpus with the real pinned engine. */
final class MailDraftHtmlTest extends TestCase {
	/** @return iterable<string,array<array<string,mixed>>> named adversarial fragments */
	public static function corpus(): iterable {
		foreach (json_decode(file_get_contents(__DIR__ . '/../../../fixtures/mail-draft-html-corpus.json'), true, 512, JSON_THROW_ON_ERROR) as $row) {
			yield $row['name'] => [$row];
		}
	}

	/** @param array<string,mixed> $row corpus case @return void */
	#[DataProvider('corpus')]
	public function testEveryPayloadIsNeutralizedOrRefused(array $row): void {
		$library = getenv('MCP_MAIL_HTML_LIBRARY');
		if ($library !== false && $library !== '') {
			require_once $library;
			$engine = new MailDraftHtmlEngine();
		} else {
			// Identity tests isolate the complementary policy; real engines run separately and in both Mail cells.
			$engine = new class implements MailDraftHtmlPurifier {
				public function purify(string $html): string { return $html; }
			};
		}
		try {
			$result = (new MailDraftHtml($engine))->sanitize($row['input']);
		} catch (ToolFailure $error) {
			if (isset($row['body'])) {
				$this->fail($row['name'] . ': ' . $error->getMessage());
			}
			$this->assertNotSame('', $error->getMessage());
			return;
		}
		$this->assertFalse($row['refused'] ?? false, $row['name'] . ' must be refused');
		if (isset($row['body'])) {
			$this->assertSame($row['body'], $result['body']);
		}
		$document = new \DOMDocument();
		$document->loadHTML('<?xml encoding="UTF-8"><body>' . $result['body'] . '</body>');
		$xpath = new \DOMXPath($document);
		$this->assertSame(0, $xpath->query('//script|//style|//svg|//math|//form|//img|//iframe')->length);
		$this->assertSame(0, $xpath->query('//@*[starts-with(name(),"on") or name()="style" or name()="src" or name()="srcset"]')->length);
		$this->assertSame($result['body'], (new MailDraftHtml($engine))->sanitize($result['body'])['body']);
	}

	/** @return void */
	public function testCompleteReportAndWhitespacePreview(): void {
		$engine = new class implements MailDraftHtmlPurifier {
			public function purify(string $html): string { return $html; }
		};
		$result = (new MailDraftHtml($engine))->sanitize('<p hidden title="secret">ok</p><pre>A  B' . "\n" . ' C</pre><a href="/x">bad</a><a href="https://example.com">label</a>');
		$this->assertSame(2, $result['removed']['attribute']);
		$this->assertSame(1, $result['removed']['unsafe-url']);
		$this->assertSame(['/x'], $result['removedUrls']);
		$fields = MailDraftHtmlView::fields($result);
		$this->assertStringContainsString("A  B\n C", $fields['preview']);
		$this->assertSame([['text' => 'label', 'href' => 'https://example.com', 'differs' => true]], $fields['links']);
		$render = implode("\n", MailDraftHtmlView::lines(['body' => $result['body']] + $fields));
		$this->assertStringContainsString(MailDraftMessages::htmlRemoved(), $render);
		$this->assertStringNotContainsString(MailDraftMessages::htmlUnchanged(), $render);
		$this->assertStringNotContainsString('<p>', $render);
	}

	/** @return void */
	public function testNoEngineMeansNoHtmlFallback(): void {
		$engine = new class implements MailDraftHtmlPurifier {
			public function purify(string $html): string { throw new ToolFailure(MailDraftMessages::htmlEngineMissing()); }
		};
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage(MailDraftMessages::htmlEngineMissing());
		(new MailDraftHtml($engine))->sanitize('<p>ok</p>');
	}

	/** @return void */
	public function testUnreportedEngineRemovalIsRefused(): void {
		$engine = new class implements MailDraftHtmlPurifier {
			public function purify(string $html): string { return '<p>different</p>'; }
		};
		$this->expectException(ToolFailure::class);
		(new MailDraftHtml($engine))->sanitize('<p>approved</p>');
	}

	/** @return void */
	public function testExpansionAndOversizedSourceAreRefused(): void {
		$engine = new class implements MailDraftHtmlPurifier {
			public function purify(string $html): string { return $html; }
		};
		foreach ([str_repeat('x', 50001), '<p>' . str_repeat('&', 11000) . '</p>'] as $source) {
			try {
				(new MailDraftHtml($engine))->sanitize($source);
				$this->fail('oversized source/output accepted');
			} catch (ToolFailure $error) {
				$this->assertNotSame('', $error->getMessage());
			}
		}
	}
	/** @return void */
	#[RunInSeparateProcess]
	public function testRealAdapterRefusesAnAbsentLibrary(): void {
		$this->assertFalse(class_exists('HTMLPurifier', false));
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage(MailDraftMessages::htmlEngineMissing());
		(new MailDraftHtmlEngine())->purify('<p>ok</p>');
	}

}
