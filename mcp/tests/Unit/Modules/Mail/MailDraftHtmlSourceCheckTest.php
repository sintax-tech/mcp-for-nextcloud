<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Creation\{MailDraftHtml, MailDraftHtmlPurifier};
use OCA\Mcp\Modules\Mail\Presentation\{MailDraftHtmlView, MailDraftMessages};
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
		yield 'comment closed by --!>' => ['<!--x--!><p>A&#106;B</p><!--y--><p>end</p>'];
		yield 'abrupt comment' => ['<p>ok</p><!-->secret'];
		yield 'comment with --' => ['<!-- a -- b --><p>ok</p>'];
		yield 'unclosed comment' => ['<p>ok</p><!-- x'];
		yield 'bogus comment' => ['<p>ok</p><!bogus>'];
		yield 'C0 control reference' => ['<p>A&#1;B</p>'];
		yield 'carriage return reference' => ['<pre>A&#13;B</pre>'];
		yield 'DEL reference' => ['<p>A&#127;B</p>'];
		yield 'noncharacter reference' => ['<p>A&#xFFFE;B &#xFDD0;</p>'];
		yield 'raw C0 control' => ["<p>A\u{1}B</p>"];
		yield 'raw noncharacter' => ["<p>A\u{FFFE}B</p>"];
		yield 'slash between attributes' => ['<p/title=a/title=b>ok</p>'];
		yield 'attributes without whitespace' => ['<p title="a"title="b">ok</p>'];
		yield 'form feed between attributes' => ["<p title=one\fid=two>ok</p>"];
		yield 'attribute in an end tag' => ['<p>ok</p x="y">'];
		yield 'duplicate inside a discarded subtree' => ['<script></script><template><p title=one title=two>x</p></template><p>ok</p>'];
		yield 'markup inside raw text' => ['<script><p>x</p></script><p>ok</p>'];
		yield 'raw text without end tag' => ['<p>ok</p><textarea>t'];
		yield 'element void only in HTML5' => ['<source><p>x</p></source><p>end</p>'];
		yield 'foreign content' => ['<svg><p>x</p></svg><p>ok</p>'];
		yield 'loose less-than sign' => ['<p>A < B</p>'];
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
		$this->assertSame('<p>ok</p>', $sanitizer->sanitize('<script>a && b</script><p title="a">ok</p>')['body']);
		$this->assertSame('<p>ok</p>', $sanitizer->sanitize('<p title="&#x41;" lang="pt">ok</p>')['body']);
	}

	/** @return void */
	public function testLooseAmpersandsKeepTheTextAndTheDestinationShownInThePlan(): void {
		$result = $this->sanitizer()->sanitize('<p>A & B, AT&T, R&D, &copy 2026</p><p><a href="https://example.com/?a=1&b=2&lang=pt">x</a></p>');
		$this->assertSame('<p>A &amp; B, AT&amp;T, R&amp;D, &amp;copy 2026</p><p><a href="https://example.com/?a=1&amp;b=2&amp;lang=pt">x</a></p>', $result['body']);
		$this->assertSame(0, array_sum($result['removed']));
		$fields = MailDraftHtmlView::fields($result);
		$this->assertStringContainsString('A & B, AT&T, R&D, &copy 2026', $fields['preview']);
		$this->assertSame([['text' => 'x', 'href' => 'https://example.com/?a=1&b=2&lang=pt', 'differs' => true]], $fields['links']);
		$render = implode("\n", MailDraftHtmlView::lines(['body' => $result['body']] + $fields));
		$this->assertStringContainsString(MailDraftMessages::htmlUnchanged(), $render);
		// The plan escapes Markdown; it shows the decoded destination and text, not the &amp; notation.
		$this->assertStringContainsString('example.com/?a=1\\&b=2\\&lang=pt', $render);
		$this->assertStringContainsString('A \\& B, AT\\&T, R\\&D, \\&copy 2026', $render);
		$this->assertSame($result['body'], $this->sanitizer()->sanitize($result['body'])['body']);
	}

	/** @return MailDraftHtml sanitizer with an identity engine */
	private function sanitizer(): MailDraftHtml {
		return new MailDraftHtml(new class implements MailDraftHtmlPurifier {
			public function purify(string $html): string { return $html; }
		});
	}
}
