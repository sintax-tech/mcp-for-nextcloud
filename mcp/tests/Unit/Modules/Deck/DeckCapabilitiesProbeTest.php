<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Modules\Deck\DeckRegistration;
use OCA\Mcp\Modules\Deck\Integration\DeckCapabilities;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The unwired Deck probe answers the same condition as dependencies(), and only the registry catches a throw. */
final class DeckCapabilitiesProbeTest extends TestCase {
	private function apps(string $version): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($version);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);
		return $apps;
	}

	public static function cells(): iterable {
		foreach ([[32, '1.16.8', false], [33, '1.17.7', false], [34, '1.18.5', true], [35, '1.19.0', true],
			[34, '1.18.4', false], [35, '', false], [34, '1.19.0', false], [35, '1.20.0', false],
			[36, '1.20.0', false], [35, '1.19.1', true]] as $cell) {
			yield $cell;
		}
	}

	#[DataProvider('cells')]
	public function testSupportsUsesTheDependenciesCondition(int $major, string $version, bool $expected): void {
		$cap = new DeckCapabilities($this->apps($version), $major, static fn (): bool => true);
		self::assertSame(['deck.dependencies'], $cap->keys());
		self::assertSame($expected, $cap->dependencies());
		self::assertSame($expected, $cap->supports('deck.dependencies'));
		self::assertFalse($cap->supports('deck.unknown'));
	}

	public static function members(): iterable {
		foreach (['assignDependentCard', 'removeDependentCard', 'findDependenciesForCards', 'dependentCards'] as $member) {
			yield [$member];
		}
	}

	#[DataProvider('members')]
	public function testAMissingMemberIsFalseWithoutThrowing(string $missing): void {
		$cap = new DeckCapabilities($this->apps('1.18.5'), 34, static fn (string $class, string $member): bool => $member !== $missing);
		self::assertFalse($cap->supports('deck.dependencies'));
		self::assertFalse($cap->dependencies());
	}

	public function testAnUnknownKeyDoesNotAskTheCondition(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects(self::never())->method('getAppVersion');
		$cap = new DeckCapabilities($apps, 34, static fn (): bool => true);
		self::assertFalse($cap->supports('deck.other'));
	}

	public function testDependenciesStillThrowsTheSameException(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willThrowException(new \RuntimeException('version unavailable'));
		$cap = new DeckCapabilities($apps, 34);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('version unavailable');
		$cap->dependencies();
	}

	public function testSupportsDoesNotCatchTheDependenciesException(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willThrowException(new \RuntimeException('version unavailable'));
		$cap = new DeckCapabilities($apps, 34);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('version unavailable');
		$cap->supports('deck.dependencies');
	}

	public function testTheRegistryClosesAThrowWithOneWarningAndNoMessage(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willThrowException(new \RuntimeException('version unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('MCP capability probe failed: {probe}: {exception_class} (code {exception_code})', [
			'app' => 'mcp',
			'probe' => DeckCapabilities::class,
			'exception_class' => \RuntimeException::class, 'exception_code' => 0,
		]);
		$registry = new CapabilityRegistry([new DeckCapabilities($apps, 34)], $logger);
		self::assertFalse($registry->supports('deck.dependencies'));
		self::assertFalse($registry->supports('deck.dependencies'));
		self::assertFalse($registry->supports('deck.unknown'));
	}

	public function testAFailingLoggerStillLeavesTheRegistryFalse(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willThrowException(new \RuntimeException('version unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willThrowException(new \RuntimeException('log down'));
		$registry = new CapabilityRegistry([new DeckCapabilities($apps, 34)], $logger);
		self::assertFalse($registry->supports('deck.dependencies'));
	}

	public function testRegistrationNamesTheProbeAndIsNotAModule(): void {
		$registration = new DeckRegistration();
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::exactly(3))->method('registerService');
		$registration->register($context);
		self::assertSame([DeckCapabilities::class], $registration->probes());
		self::assertNotContains(DeckRegistration::class, Application::MODULES);
	}
}
