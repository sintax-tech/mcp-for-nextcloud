<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\FileTypes;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** Mutable policy backend for production boundaries in unit fixtures. */
final class FileTypePolicyFixture {
	/** @var string|null Absent key preserves the installation default. */
	public ?string $raw = null;

	/**
	 * @param TestCase $test Mock factory.
	 * @return FileTypeGuard Production evaluator over the mutable fixture backend.
	 */
	public function guard(TestCase $test): FileTypeGuard {
		$config = $test->getMockBuilder(IAppConfig::class)->disableOriginalConstructor()->getMock();
		$config->method('hasKey')->willReturnCallback(fn (): bool => $this->raw !== null);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->raw ?? '');
		return new FileTypeGuard(new FileWritePolicy($config, $test->getMockBuilder(IDBConnection::class)->disableOriginalConstructor()->getMock()));
	}

	/**
	 * @param TestCase $test Mock factory.
	 * @return FileTypeGuard Real evaluator with the absent-key default for legacy regressions.
	 */
	public static function off(TestCase $test): FileTypeGuard {
		return (new self())->guard($test);
	}

	/**
	 * @param list<string> $extensions Allowed suffixes.
	 * @return void
	 */
	public function allow(array $extensions): void {
		$this->raw = json_encode(['version' => 1, 'mode' => 'allowlist', 'extensions' => $extensions, 'allowExtensionless' => false], JSON_THROW_ON_ERROR);
	}
}
