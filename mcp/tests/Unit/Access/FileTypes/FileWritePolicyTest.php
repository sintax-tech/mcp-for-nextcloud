<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\FileTypes;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The single policy value is fresh, strictly validated and never implicitly opened on failure. */
final class FileWritePolicyTest extends TestCase {
	/** Absence is distinct from an empty stored value, including a key marked lazy elsewhere. */
	public function testOnlyAnAbsentKeyUsesTheFactoryDefault(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())->method('clearCache')->with(false);
		$config->expects(self::once())->method('hasKey')->with('mcp', FileWritePolicy::KEY, null)->willReturn(false);
		$config->expects(self::never())->method('getValueString');
		self::assertSame(FileWritePolicy::defaults(), (new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)))->current());
	}

	/** Saving normalizes admin input and uses matching non-lazy flags for both directions. */
	public function testSaveUsesOneCanonicalValueAndReadsItBack(): void {
		$config = $this->createMock(IAppConfig::class);
		$expected = ['version' => 1, 'mode' => 'allowlist', 'extensions' => ['0', 'md', 'txt'], 'allowExtensionless' => true];
		$json = json_encode($expected, JSON_THROW_ON_ERROR);
		$config->expects(self::once())->method('setValueString')->with('mcp', FileWritePolicy::KEY, $json, false)->willReturn(true);
		$config->expects(self::once())->method('clearCache')->with(false);
		$config->method('hasKey')->willReturn(true);
		$config->expects(self::once())->method('getValueString')->with('mcp', FileWritePolicy::KEY, '', false)->willReturn($json);
		self::assertSame($expected, (new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)))->save('allowlist', " .TXT,md\nTxt,0", true));
	}

	/** @return iterable<string, array{string}> Textarea delimiters around whitespace do not create phantom extensions. */
	public static function validText(): iterable {
		yield 'trailing newline' => ["txt\nmd\n"];
		yield 'comma newline' => ["txt,\nmd"];
		yield 'trailing comma' => ['txt,md,'];
		yield 'blank line whitespace' => ["txt\n\nmd"];
	}

	/** @param string $text Raw admin textarea input. */
	#[DataProvider('validText')]
	public function testTextareaSeparatorsAreNormalized(string $text): void {
		$config = $this->createMock(IAppConfig::class);
		$expected = ['version' => 1, 'mode' => 'allowlist', 'extensions' => ['md', 'txt'], 'allowExtensionless' => false];
		$config->method('hasKey')->willReturn(true);
		$config->expects(self::once())->method('setValueString')->with('mcp', FileWritePolicy::KEY, json_encode($expected), false)->willReturn(true);
		$config->method('getValueString')->willReturn(json_encode($expected));
		self::assertSame($expected, (new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)))->save('allowlist', $text, false));
	}

	/** Every check of the same instance observes current data, not a remembered policy. */
	public function testReadsAreFreshAndNeverFallBackToTheLastGoodValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->expects(self::exactly(3))->method('clearCache')->with(false);
		$off = FileWritePolicy::defaults();
		$on = $off;
		$on['mode'] = 'allowlist';
		$config->method('getValueString')->willReturnOnConsecutiveCalls(json_encode($off), json_encode($on), 'bad');
		$policy = new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class));
		self::assertSame($off, $policy->current());
		self::assertSame($on, $policy->current());
		$this->expectException(FileWritePolicyUnavailable::class);
		$policy->current();
	}

	/** @return iterable<string, array{string}> Invalid persisted values never mean off. */
	public static function invalidStored(): iterable {
		foreach (['', 'null', '[]', 'false', '{', '"off"', '{}', '{"version":2,"mode":"off","extensions":[],"allowExtensionless":false}',
			'{"version":1,"mode":"unknown","extensions":[],"allowExtensionless":false}',
			'{"version":1,"mode":"off","extensions":[],"allowExtensionless":0}',
			'{"version":1,"mode":"off","extensions":[],"allowExtensionless":false,"extra":1}',
			'{"version":1,"mode":"allowlist","extensions":["TXT"],"allowExtensionless":false}',
			'{"version":1,"mode":"allowlist","extensions":["txt","txt"],"allowExtensionless":false}',
			'{"version":1,"mode":"off","extensions":["tar.gz"],"allowExtensionless":false}'] as $i => $raw) {
			yield 'invalid-' . $i => [$raw];
		}
	}

	/** @param string $raw Invalid JSON or policy schema; no raw value escapes in the exception. */
	#[DataProvider('invalidStored')]
	public function testInvalidStoredValueFailsClosed(string $raw): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueString')->willReturn($raw);
		try {
			(new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)))->current();
			self::fail('Invalid stored policy accepted');
		} catch (FileWritePolicyUnavailable $e) {
			self::assertNull($e->getPrevious());
			self::assertSame('The MCP file type policy is unavailable. Ask an administrator to review its settings.', $e->getMessage());
		}
	}

	/** @return iterable<string, array{string, string|array<mixed>}> Invalid settings must not reach storage. */
	public static function invalidInput(): iterable {
		yield 'mode' => ['denylist', []];
		foreach (['a,,b', ',txt', '*', 'tar.gz', '..txt', 'a/php', 'php:txt', 'рhp', str_repeat('a', 33)] as $i => $text) {
			yield 'token-' . $i => ['allowlist', $text];
		}
		yield 'non-string' => ['allowlist', [1]];
		yield 'map' => ['allowlist', ['ext' => 'txt']];
		yield 'too-many' => ['allowlist', array_map(static fn (int $i): string => 'x' . $i, range(0, 128))];
	}

	/**
	 * @param string $mode Submitted mode.
	 * @param string|array<mixed> $extensions Invalid input.
	 */
	#[DataProvider('invalidInput')]
	public function testInvalidSaveDoesNotTouchTheStoredValue(string $mode, string|array $extensions): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::never())->method('setValueString');
		$this->expectException(InvalidArgumentException::class);
		(new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)))->save($mode, $extensions, false);
	}

	/** Unchanged writes may return false; successful read-back, not that flag, establishes state. */
	public function testMaximumUniqueExtensionsAndUnchangedSaveAreAccepted(): void {
		$config = $this->createMock(IAppConfig::class);
		$stored = '';
		$config->method('hasKey')->willReturn(true);
		$config->method('setValueString')->willReturnCallback(static function ($app, $key, $value, $lazy) use (&$stored): bool {
			self::assertFalse($lazy);
			$stored = $value;
			return false;
		});
		// Use a reference-capturing reader: a worker sees the updated value after clearCache.
		$config->method('getValueString')->willReturnCallback(static function () use (&$stored): string { return $stored; });
		$extensions = array_map(static fn (int $i): string => 'x' . $i, range(0, 127));
		self::assertCount(128, (new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)))->save('allowlist', [...$extensions, 'x0'], false)['extensions']);
	}

	/** Every backend failure stage is an unavailable policy, not a leaked exception or an implicit off state. */
	public function testBackendAndTypeConflictsAreSanitized(): void {
		foreach (['clearCache', 'hasKey', 'getValueString'] as $method) {
			$config = $this->createMock(IAppConfig::class);
			if ($method !== 'hasKey') {
				$config->method('hasKey')->willReturn(true);
			}
			$config->method($method)->willThrowException(new AppConfigTypeConflictException('secret backend detail'));
			try {
				(new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)))->current();
				self::fail('Backend failure accepted');
			} catch (FileWritePolicyUnavailable $e) {
				self::assertStringNotContainsString('secret', $e->getMessage());
				self::assertNull($e->getPrevious());
			}
		}
	}

	/** Saving must not claim success when storage throws or read-back disagrees. */
	public function testFailedSaveOrReadBackNeverReportsSuccess(): void {
		foreach ([true, false] as $throws) {
			$config = $this->createMock(IAppConfig::class);
			$config->method('hasKey')->willReturn(false);
			if ($throws) {
				$config->method('setValueString')->willThrowException(new \RuntimeException('secret'));
			}
			try {
				(new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class)))->save('allowlist', [], false);
				self::fail('Unverified save accepted');
			} catch (FileWritePolicyUnavailable $e) {
				self::assertNull($e->getPrevious());
			}
		}
	}
}
