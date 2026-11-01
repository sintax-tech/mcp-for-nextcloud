<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Access\FileTypes\FileTypeMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Names, not MIME, user roles or internal flags, decide the active policy. */
final class FileTypeGuardTest extends TestCase {
	/** Reset request translation after each scenario. */
	protected function tearDown(): void {
		Translator::reset();
	}

	/** @return iterable<string, array{string, bool, bool}> Basename, no-extension option, expected allow. */
	public static function names(): iterable {
		foreach (['a.txt', 'FILE.TXT', 'a.php.txt', '.config.json', 'meu relatório.txt', '資料.txt', 'my file.md', '0.txt'] as $name) {
			yield 'allowed-' . $name => [$name, false, true];
		}
		foreach (['a.txt.php', 'a.', 'a.txt.', 'a.txt ', "a.txt\t", "a.txt\n", 'a. txt', 'a.рhp', 'a.ｔｘｔ', 'a.php:x.txt', 'a:txt',
			'folder.php/a.txt', 'folder.php\\a.txt', "a\0.txt", "a.\u{200B}txt", '', '.', '..', "a.\xff"] as $name) {
			yield 'denied-' . bin2hex($name) => [$name, true, false];
		}
		foreach (['README', '.env', 'a．php', 'arquivo sem extensão'] as $name) {
			yield 'no-ext-off-' . $name => [$name, false, false];
			yield 'no-ext-on-' . $name => [$name, true, true];
		}
	}

	/**
	 * @param string $name Actual basename.
	 * @param bool $extensionless Explicit toggle.
	 * @param bool $allowed Expected result.
	 */
	#[DataProvider('names')]
	public function testNameTable(string $name, bool $extensionless, bool $allowed): void {
		$guard = $this->guard('allowlist', ['json', 'md', 'txt'], $extensionless);
		if (!$allowed) {
			$this->expectException(FileTypeDenied::class);
		}
		$guard->assertWritableName($name);
		if ($allowed) {
			self::assertTrue(true);
		}
	}

	/** Off adds no restriction to names Nextcloud or a tool may independently reject. */
	public function testOffDoesNotApplyTheActiveNameRules(): void {
		$guard = $this->guard('off');
		foreach (['a.php:x.txt', 'README', 'a.exe', 'a.txt ', 'a.рhp'] as $name) {
			$guard->assertWritableName($name);
		}
		self::assertTrue(true);
	}

	/** The same guard re-reads policy between checks, including links created under an earlier policy. */
	public function testTheSameGuardObservesAPolicyChange(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->expects(self::exactly(2))->method('clearCache')->with(false);
		$off = FileWritePolicy::defaults();
		$on = $off;
		$on['mode'] = 'allowlist';
		$config->method('getValueString')->willReturnOnConsecutiveCalls(json_encode($off), json_encode($on));
		$guard = new FileTypeGuard(new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)));
		$guard->assertWritableName('a.php');
		$this->expectException(FileTypeDenied::class);
		$guard->assertWritableName('a.php');
	}

	/** A preflight shares one immutable policy; a fresh sink/confirmation boundary sees the next policy. */
	public function testOneSnapshotReadsOnceForManyNames(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$clears = 0;
		$config->expects(self::exactly(2))->method('clearCache')->with(false)->willReturnCallback(static function () use (&$clears): void { $clears++; });
		$config->expects(self::exactly(2))->method('hasKey')->with('mcp', FileWritePolicy::KEY, null)->willReturn(true);
		$on = FileWritePolicy::defaults();
		$on['mode'] = 'allowlist';
		$on['extensions'] = ['txt'];
		$changed = $on;
		$changed['extensions'] = [];
		$config->expects(self::exactly(2))->method('getValueString')->willReturnOnConsecutiveCalls(json_encode($on), json_encode($changed));
		$guard = new FileTypeGuard(new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)));
		$check = $guard->snapshot();
		foreach (range(1, 2000) as $i) {
			$check->assertWritableName('file-' . $i . '.txt');
		}
		self::assertSame(1, $clears, 'Two thousand names must invalidate the cache exactly once for their shared boundary.');
		$nextBoundary = $guard->snapshot();
		$check->assertWritableName('still-the-original-snapshot.txt');
		$this->expectException(FileTypeDenied::class);
		$nextBoundary->assertWritableName('file.txt');
	}

	/** A dot in a resolved parent directory is not part of the basename's extension. */
	public function testCallerSuppliesTheResolvedBasename(): void {
		$this->guard('allowlist', ['txt'])->assertWritableName(basename('/folder.php/a.txt'));
		self::assertTrue(true);
	}

	/** An empty allowlist denies extensions; the explicit no-extension option is independent. */
	public function testEmptyListNeverMeansOff(): void {
		$this->guard('allowlist', [], true)->assertWritableName('README');
		$this->expectException(FileTypeDenied::class);
		$this->guard('allowlist', [], true)->assertWritableName('a.txt');
	}

	/** Invalid configuration is a distinct tool failure and does not use last-known or default policy. */
	public function testConfigurationFailureIsDistinctFromANameRefusal(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueString')->willReturn('invalid');
		$this->expectException(FileWritePolicyUnavailable::class);
		(new FileTypeGuard(new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class))))->assertWritableName('a.txt');
	}

	/** The type, not localized text, lets HTTP callers distinguish 403 from policy unavailability. */
	public function testRefusalDoesNotExposeTheNameOrPolicy(): void {
		try {
			$this->guard('allowlist', ['md'])->assertWritableName('private-secret.php');
			self::fail('Blocked extension accepted');
		} catch (FileTypeDenied $e) {
			self::assertInstanceOf(ToolFailure::class, $e);
			self::assertSame(FileTypeMessages::denied(), $e->getMessage());
			self::assertStringNotContainsString('private-secret', $e->getMessage());
			self::assertStringNotContainsString('md', $e->getMessage());
		}
	}

	/** Each new literal is translated in all shipped catalogs, whose JS and JSON entries agree. */
	public function testAllPolicyMessagesAreTranslated(): void {
		$root = __DIR__ . '/../../../l10n/';
		foreach (['pt_BR', 'es', 'de'] as $locale) {
			$data = json_decode((string)file_get_contents($root . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
			$js = (string)file_get_contents($root . $locale . '.js');
			foreach ([FileTypeMessages::denied(), FileTypeMessages::unavailable(), FileTypeMessages::invalid()] as $source) {
				self::assertArrayHasKey($source, $data['translations']);
				$translated = $data['translations'][$source];
				self::assertNotSame($source, $translated);
				self::assertStringContainsString(json_encode($source, JSON_UNESCAPED_UNICODE) . ' : ' . json_encode($translated, JSON_UNESCAPED_UNICODE), $js);
			}
			Translator::use(new JsonL10n($locale));
			self::assertSame($data['translations']['Invalid file type policy settings.'], FileTypeMessages::invalid());
			Translator::reset();
		}
	}

	/**
	 * @param string $mode Policy mode.
	 * @param list<string> $extensions Canonical extension list.
	 * @param bool $extensionless No-extension choice.
	 * @return FileTypeGuard Guard reading a known configured value, without plumbing users or MIME.
	 */
	private function guard(string $mode, array $extensions = [], bool $extensionless = false): FileTypeGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueString')->willReturn(json_encode(['version' => 1, 'mode' => $mode, 'extensions' => $extensions, 'allowExtensionless' => $extensionless], JSON_THROW_ON_ERROR));
		return new FileTypeGuard(new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)));
	}
}
