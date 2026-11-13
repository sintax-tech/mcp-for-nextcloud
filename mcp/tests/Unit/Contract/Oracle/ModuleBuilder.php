<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Modules\Deck\Integration\DeckCapabilities;
use OCA\Mcp\Modules\Forms\Integration\StructureCapabilities;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Builds the real module classes of {@see \OCA\Mcp\AppInfo\Application::MODULES} for discovery: every app collaborator
 * of the app itself is built for real, recursively, and every Nextcloud or native-app type is a PHPUnit mock.
 *
 * Discovery reads only static definitions, so mocks are enough; the two collaborators that decide which tools exist
 * are set explicitly: the Deck capability probe (dependency tools on or off), the Forms structure probe (always on)
 * and the logs role gate (always open, so the logs tools are listed and frozen like the others).
 */
final class ModuleBuilder {
	/**
	 * @param TestCase $test the running test, owner of the mocks
	 * @param bool $deckDependencies whether the Deck probe reports the native dependency API
	 * @param bool $collectivesNative whether the Collectives probe reports the proven native shape
	 */
	public function __construct(private TestCase $test, private bool $deckDependencies = true, private bool $collectivesNative = true) {
	}

	/**
	 * @param list<class-string> $classes module classes, in tools/list order
	 * @return list<object> the built modules, same order
	 */
	public function modules(array $classes): array {
		return array_map(fn (string $class): object => $this->build($class), $classes);
	}

	/**
	 * @param class-string $class concrete class to build
	 * @return object the instance, with real app collaborators and mocks for the rest
	 */
	public function build(string $class): object {
		if ($class === \OCA\Mcp\Modules\Polls\Integration\PollsMutationProbe::class) {
			return \OCA\Mcp\Tests\Unit\Modules\Polls\PollsDiscoveryProbe::build($this->test);
		}
		if ($class === \OCA\Mcp\Modules\Activity\Integration\ActivityCapabilities::class) return new \OCA\Mcp\Tests\Unit\Modules\Activity\ActivityDiscoveryProbe();
		if ($class === \OCA\Mcp\Modules\Notifications\Integration\NotificationsCapabilities::class) return new \OCA\Mcp\Tests\Unit\Modules\Notifications\NotificationsDiscoveryProbe();
		if ($class === \OCA\Mcp\Modules\Availability\Integration\AbsenceCapabilities::class) return new \OCA\Mcp\Tests\Unit\Modules\Availability\AvailabilityDiscoveryProbe();
		if ($class === DeckCapabilities::class) {
			$apps = $this->mock(IAppManager::class);
			$apps->method('getAppVersion')->willReturn('1.19.0');
			$apps->method('isEnabledForAnyone')->willReturn(true);
			$native = $this->deckDependencies;
			return new DeckCapabilities($apps, 35, static fn (): bool => $native);
		}
		if ($class === StructureCapabilities::class) {
			$apps = $this->mock(IAppManager::class);
			$apps->method('getAppVersion')->willReturn('5.4.1');
			$apps->method('isEnabledForAnyone')->willReturn(true);
			return new StructureCapabilities($apps, 35, static fn (): bool => true);
		}
		if ($class === \OCA\Mcp\Modules\Tables\Integration\TablesWriteCapabilities::class) return \OCA\Mcp\Tests\Unit\Modules\Tables\SchemaCapabilityFixture::of($this->mock(IAppManager::class));
		if ($class === \OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities::class) {
			$apps = $this->mock(IAppManager::class);
			$apps->method('getAppVersion')->willReturn('4.7.1');
			$apps->method('isEnabledForAnyone')->willReturn(true);
			$native = $this->collectivesNative;
			return new \OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities($apps, 35, static fn (): bool => $native);
		}
		if ($class === \OCA\Mcp\Modules\Collectives\Integration\FoundingCapabilities::class) {
			$apps = $this->mock(IAppManager::class);
			$apps->method('getAppVersion')->willReturnCallback(static fn (string $app): string => $app === 'circles' ? '35.0.0' : '4.7.1');
			$apps->method('isEnabledForAnyone')->willReturn(true);
			$native = $this->collectivesNative;
			return new \OCA\Mcp\Modules\Collectives\Integration\FoundingCapabilities($apps,
				$this->build(\OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities::class), 35, static fn (): bool => $native);
		}
		if ($class === \OCA\Mcp\Modules\Collectives\Integration\TeamsCapabilities::class) {
			$apps = $this->mock(IAppManager::class);
			$apps->method('getAppVersion')->willReturnCallback(static fn (string $app): string => $app === 'circles' ? '35.0.0' : '4.7.1');
			$apps->method('isEnabledForAnyone')->willReturn(true);
			$native = $this->collectivesNative;
			return new \OCA\Mcp\Modules\Collectives\Integration\TeamsCapabilities($apps,
				$this->build(\OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities::class), 35, static fn (): bool => $native);
		}
		$constructor = (new ReflectionClass($class))->getConstructor();
		$args = [];
		foreach ($constructor?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$name = $type instanceof ReflectionNamedType ? $type->getName() : null;
			if ($name === null || $type->isBuiltin()) {
				$args[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : ($name === 'string' ? 'mcp' : null);
				continue;
			}
			if ($name === LogsAccess::class) {
				$access = $this->mock(LogsAccess::class);
				$access->method('permits')->willReturn(true);
				$args[] = $access;
				continue;
			}
			$instantiable = (new ReflectionClass($name))->isInstantiable();
			$args[] = str_starts_with($name, 'OCA\\Mcp\\') && $instantiable ? $this->build($name) : $this->mock($name);
		}
		return new $class(...$args);
	}

	/**
	 * @param class-string $class type to mock
	 * @return \PHPUnit\Framework\MockObject\MockObject mock owned by the running test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
