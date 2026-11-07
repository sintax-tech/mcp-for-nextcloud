<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Creation\{MailDraftHtml, MailDraftHtmlPurifier};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The source check gives the same answer on legacy and HTML5 libxml parsers, whose recovery differs silently. */
final class MailDraftHtmlSourceCheckTest extends TestCase {
	/** @return iterable<string,array{string}> sources both parsers would otherwise recover differently */
	public static function refused(): iterable {
		yield 'duplicate attribute' => ['<p title="one" title="two">ok</p>'];
		yield 'duplicate attribute in another case' => ['<p title="one" TITLE="two">ok</p>'];
		yield 'duplicate unquoted attribute' => ['<p><a href=https://a.example HREF=https://b.example>ok</a></p>'];
		yield 'hex reference without semicolon' => ['<p><a href="jav&#x61script:x()">ok</a></p>'];
		yield 'decimal reference without semicolon' => ['<p>A&#66 C</p>'];
		yield 'reference without digits' => ['<p>&#; &#x;</p>'];
		yield 'C1 control remapped by HTML5' => ['<p>&#x80;</p>'];
		yield 'C1 control in decimal' => ['<p>&#159;</p>'];
		yield 'NUL' => ['<p>&#0;</p>'];
		yield 'surrogate' => ['<p>&#xD800;</p>'];
		yield 'beyond Unicode' => ['<p>&#x110000;</p>'];
		yield 'beyond Unicode with many digits' => ['<p>&#99999999;</p>'];
	}

	/** @param string $source refused source @return void */
	#[DataProvider('refused')]
	public function testAmbiguousSourcesAreRefusedOnEveryParser(string $source): void {
		$this->expectException(ToolFailure::class);
		$this->sanitizer()->sanitize($source);
	}

	/** @return void */
	public function testUnambiguousReferencesAndIgnoredContentStillPass(): void {
		$sanitizer = $this->sanitizer();
		$this->assertSame('<p>A € 😀</p>', $sanitizer->sanitize('<p>&#x41; &#8364; &#x1F600;</p>')['body']);
		$this->assertSame('<p>ok</p>', $sanitizer->sanitize('<!-- &#x80; title title --><p>ok</p>')['body']);
		$this->assertSame('<p>ok</p>', $sanitizer->sanitize('<script>a&#x80;</script><p title="a">ok</p>')['body']);
		$this->assertSame('<p>ok</p>', $sanitizer->sanitize('<p title="&#x41;" lang="pt">ok</p>')['body']);
	}

	/** @return MailDraftHtml sanitizer with an identity engine */
	private function sanitizer(): MailDraftHtml {
		return new MailDraftHtml(new class implements MailDraftHtmlPurifier {
			public function purify(string $html): string { return $html; }
		});
	}
}
