<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Modules\Routines\Preparation\ReferenceTexts;
use OCA\Mcp\Platform\Meetings\LocalInstanceLink;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** Forms that must not become a card or a file of this instance, and the forms that still do. */
final class PrepareMeetingEscapeTest extends TestCase {
	use BuildsCloudLink;

	/**
	 * Every escape from the two reviews is refused whole: no sliced path and no local host.
	 *
	 * @dataProvider escapes
	 * @param string $sample one reference or one blob of text
	 * @return void
	 */
	public function testAnEscapeIsNotALocalReference(string $sample): void {
		$local = $this->link();
		self::assertFalse($local->accepts($sample), $sample);
		self::assertSame([], ReferenceTexts::fileReferences($sample, $local), $sample);
		self::assertSame([], ReferenceTexts::deckCardIds($sample, $local), $sample);
	}

	/**
	 * A trusted host keeps its port and a wildcard. A quoted or spaced relative path still counts.
	 *
	 * @return void
	 */
	public function testATrustedHostWithAPortOrAWildcardStaysLocal(): void {
		$local = $this->link();
		self::assertTrue($local->accepts('15'));
		self::assertTrue($local->accepts('/f/15'));
		self::assertTrue($local->accepts('https://cloud.example:8443/f/15'));
		self::assertSame([8], ReferenceTexts::deckCardIds('//cloud.example/apps/deck/board/4/card/8', $local));
		self::assertSame(['/f/15'], ReferenceTexts::fileReferences(' /f/15', $local));
		self::assertSame(['/f/15'], ReferenceTexts::fileReferences('"/f/15"', $local));
		self::assertSame(['/f/15'], ReferenceTexts::fileReferences("'/f/15'", $local));
		$wild = $this->trusted(['*.example']);
		self::assertTrue($wild->accepts('https://files.example/f/2'));
		self::assertFalse($wild->accepts('https://evil.test/f/2'));
		self::assertTrue($this->trusted(['cloud.example:8443'])->accepts('https://cloud.example:8443/apps/files/?fileid=4'));
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function escapes(): iterable {
		yield 'slash inside a foreign file url' => ['https://evil.com//f/123'];
		yield 'dash before a file path' => ['https://evil.com/share/-/f/123'];
		yield 'query hides a file path' => ['https://evil.com/?x=/f/9'];
		yield 'slash hides a files app url' => ['https://evil.com/x//apps/files/?fileid=5'];
		yield 'scheme relative deck url' => ['//evil.com/apps/deck/board/1/card/3'];
		yield 'ftp deck url' => ['ftp://evil.com/apps/deck/board/1/card/3'];
		yield 'host without a scheme' => ['partner.cloud/apps/deck/#/board/1/card/2'];
		yield 'backslash before a trusted looking host' => ['https://evil.com\@cloud.example.org/f/1'];
		yield 'apostrophe inside a file url' => ["https://evil.com/a'/f/7"];
		yield 'apostrophe before a deck path' => ["https://evil.com/'/apps/deck/board/1/card/7"];
		yield 'apostrophe before a file path' => ["https://evil.com/'/f/27"];
		yield 'quote inside a file url' => ['https://evil.com/a"/f/8'];
		yield 'scheme with a backslash' => ['https:\\evil.com/f/1'];
		yield 'scheme with one slash' => ['https:/cloud.example.org/f/1'];
		yield 'scheme with no slash' => ['http:evil.com/f/1'];
		yield 'bare host and a file path' => ['evil.com/f/6'];
		yield 'userinfo on a foreign host' => ['https://user@evil.com/f/1'];
		yield 'foreign host with a port' => ['https://evil.com:443/f/1'];
		yield 'data uri' => ['data:text/html,https://cloud.example/f/1'];
		yield 'suffix of the trusted host' => ['https://cloud.example.evil/f/1'];
		yield 'javascript scheme' => ['javascript:/f/1'];
		yield 'file scheme' => ['file:///f/15'];
		yield 'apostrophe before a deck card' => ["https://evil.com/a'/apps/deck/board/1/card/7"];
		yield 'plain http foreign host' => ['http://evil.com/f/1'];
		yield 'scheme relative file url' => ['//evil.com/f/15'];
		yield 'backslash and the instance host' => ['https://evil.com\@cloud.example/f/1'];
		yield 'http scheme without slashes' => ['http:\\evil.com/f/1'];
		yield 'https scheme and a colon only' => ['https:evil.com/f/1'];
		yield 'backslash as the second slash' => ['/\\evil.com/f/1'];
		yield 'two backslashes as slashes' => ['/\\\\evil.com/f/2'];
		yield 'backslash before a deck path' => ['/\\evil.com/apps/deck/board/1/card/5'];
		yield 'backslash before a file id query' => ['/\\evil.com/index.php/apps/files/?fileid=7'];
		yield 'leading backslash' => ['\\/evil.com/f/3'];
	}

	/**
	 * @param list<string> $trusted trusted_domains
	 * @return LocalInstanceLink an instance limited to those domains
	 */
	private function trusted(array $trusted): LocalInstanceLink {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static function (string $key, mixed $default = '') use ($trusted): mixed {
			return match ($key) {
				'trusted_domains' => $trusted,
				'overwrite.cli.url' => '',
				default => $default,
			};
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('');
		return new LocalInstanceLink($urls, $config);
	}
}
