<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Service\BackupSettingsDocument;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** Adapts existing flow fixtures; the real SQL reader is exercised separately against SQLite and Nextcloud. */
final class BackupDocumentFixture {
	/**
	 * @param TestCase $test owner of the mock
	 * @param IAppConfig $config mutable in-memory fixture, never production configuration
	 * @return BackupSettingsDocument document double reflecting the fixture's current bytes
	 */
	public static function fromConfig(TestCase $test, IAppConfig $config): BackupSettingsDocument {
		$document = (new \ReflectionMethod($test, 'createMock'))->invoke($test, BackupSettingsDocument::class);
		$document->method('read')->willReturnCallback(static fn (): ?string => $config->hasKey('mcp', BackupLocation::KEY, null)
			? $config->getValueString('mcp', BackupLocation::KEY, '', false) : null);
		return $document;
	}

	/**
	 * @param TestCase $test owner of the contract fixture
	 * @param class-string $class collaborator selected by its existing autowiring helper
	 * @param array<mixed> $arguments existing constructor arguments
	 * @param \OCA\Mcp\Tests\Unit\InMemoryConfig $config mutable contract configuration
	 * @return object real collaborator, substituting only the document's backend in the in-memory contract
	 */
	public static function construct(TestCase $test, string $class, array $arguments, \OCA\Mcp\Tests\Unit\InMemoryConfig $config): object {
		return $class === BackupSettingsDocument::class ? self::fromConfig($test, $config->appConfig($test)) : new $class(...$arguments);
	}
}
