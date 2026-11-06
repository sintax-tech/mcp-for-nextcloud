<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Modules\Deck\Integration\DeckCapabilities;
use OCA\Mcp\Modules\Deck\DeckToolModule;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

final class DependencyCapabilitiesTest extends TestCase {
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
	public function testVersionAndNativeShapeGate(int $major, string $version, bool $expected): void {
		$cap = new DeckCapabilities($this->apps($version), $major, static fn (): bool => true);
		self::assertSame($expected, $cap->dependencies());
	}

	public static function members(): iterable {
		foreach (['assignDependentCard', 'removeDependentCard', 'findDependenciesForCards', 'dependentCards'] as $member) {
			yield [$member];
		}
	}

	#[DataProvider('members')]
	public function testDeclaredVersionWithoutAnyRequiredMemberFailsClosed(string $missing): void {
		$cap = new DeckCapabilities($this->apps('1.18.5'), 34,
			static fn (string $class, string $member): bool => $member !== $missing);
		self::assertFalse($cap->dependencies());
	}

	public function testToolsListAndDirectCallRefuseUnsupportedApiBeforeAnyDeckDi(): void {
		foreach ([[32, '1.16.8'], [33, '1.17.7'], [34, '1.18.5']] as [$major, $version]) {
			$apps = $this->apps($version);
			$cap = new DeckCapabilities($apps, $major, static fn (): bool => false);
			$container = $this->createMock(ContainerInterface::class);
			$container->expects(self::never())->method('get');
			$module = new DeckToolModule($container, $apps, $this->createMock(LoggerInterface::class),
				$this->createMock(\OCP\IURLGenerator::class), $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
				null, null, null, $cap);
			self::assertNotContains('deck_read_card_dependencies', array_column($module->definitions(), 'name'));
			try {
				$module->call('deck_read_card_dependencies', ['cardId' => 7], 'alice');
				self::fail('unsupported direct call accepted');
			} catch (\InvalidArgumentException $e) {
				self::assertSame(\OCA\Mcp\Modules\Deck\Presentation\DeckMessages::dependenciesUnavailable(), $e->getMessage());
			}
		}
	}

	public function testRealRegistryFiltersReadGrantAndPerUserAppOnEveryRequest(): void {
		$data = new \OCA\Mcp\Tests\Unit\InMemoryConfig();
		$policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($data->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore());
		$apps = $this->apps('1.18.5');
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$module = new DeckToolModule($container, $apps, $logger, $this->createMock(\OCP\IURLGenerator::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), $users, null, null,
			new DeckCapabilities($apps, 34, static fn (): bool => true));
		$registry = new ToolRegistry([$module], $policy, $apps, $users, $logger);
		$policy->setGrant('alice', 'deck', 'read', true);
		self::assertContains('deck_read_card_dependencies', array_column($registry->list('alice'), 'name'));
		$policy->setGrant('alice', 'deck', 'read', false);
		self::assertNotContains('deck_read_card_dependencies', array_column($registry->list('alice'), 'name'));
		$this->expectException(\InvalidArgumentException::class);
		$registry->call('deck_read_card_dependencies', ['cardId' => 7], 'alice');
	}
	public function testPerUserDisabledAppHidesAndRefusesOtherwiseSupportedTool(): void {
		$data = new \OCA\Mcp\Tests\Unit\InMemoryConfig();
		$policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($data->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore());
		$policy->setGrant('alice', 'deck', 'read', true);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.19.0');
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(false);
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$module = new DeckToolModule($container, $apps, $logger, $this->createMock(\OCP\IURLGenerator::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), $users, null, null,
			new DeckCapabilities($apps, 35, static fn (): bool => true));
		$registry = new ToolRegistry([$module], $policy, $apps, $users, $logger);
		self::assertNotContains('deck_read_card_dependencies', array_column($registry->list('alice'), 'name'));
		$this->expectException(\InvalidArgumentException::class);
		$registry->call('deck_read_card_dependencies', ['cardId' => 7], 'alice');
	}

	public function testDependencyTitleAndLimitsMessagesAreLocalized(): void {
		try {
			foreach (['en', 'pt_BR', 'es', 'de'] as $language) {
				\OCA\Mcp\L10n\Translator::use(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n($language));
				$title = \OCA\Mcp\Mcp\Tools\ToolPresentation::title('deck_read_card_dependencies');
				$message = \OCA\Mcp\Modules\Deck\Presentation\DeckMessages::dependencyGraphLimit();
				if ($language === 'en') {
					self::assertSame('Read Deck card dependencies', $title);
				} else {
					self::assertNotSame('Read Deck card dependencies', $title);
					self::assertStringNotContainsString('readable dependency graph', $message);
				}
			}
		} finally {
			\OCA\Mcp\L10n\Translator::reset();
		}
	}

	public function testCapabilitiesAreRecheckedRatherThanCachedBetweenCalls(): void {
		$version = '1.19.0';
		$present = true;
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(function () use (&$version): string { return $version; });
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$capability = new DeckCapabilities($apps, 35, function () use (&$present): bool { return $present; });
		self::assertTrue($capability->dependencies());
		$present = false;
		self::assertFalse($capability->dependencies());
		$present = true;
		$version = '';
		self::assertFalse($capability->dependencies());
	}

}
