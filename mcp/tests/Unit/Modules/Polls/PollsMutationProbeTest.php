<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Integration\PollsMutationProbe;
use OCA\Mcp\Modules\Polls\Integration\PollsMutationSurface;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Installation boundaries never fall back to trying a native mutation. */
final class PollsMutationProbeTest extends TestCase {
	/**
	 * @return iterable<string,array{int,string,bool,bool}> Supported, below minimum, ceiling, unknown major and disabled app.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function versions(): iterable {
		foreach (PollsMutationProbe::VERSIONS as $major => [$min, $ceiling]) {
			yield $major . ' minimum' => [$major, $min, true, true];
			yield $major . ' below' => [$major, '8.0.0', true, false];
			yield $major . ' ceiling' => [$major, $ceiling, true, false];
			yield $major . ' above' => [$major, '99.0.0', true, false];
		}
		yield 'unknown major' => [36, '9.3.0', true, false];
		yield 'disabled app' => [35, '9.3.0', false, false];
	}

	/**
	 * @param int $major Nextcloud major.
	 * @param string $v Polls version.
	 * @param bool $on Installation enablement.
	 * @param bool $expected Native compatibility.
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	#[DataProvider('versions')]
	public function testVersionAndAppGatePrecedesNativeIntrospection(int $major, string $v, bool $on, bool $expected): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($v);
		$apps->method('isEnabledForAnyone')->willReturn($on);
		$surface = $this->createMock(PollsMutationSurface::class);
		$surface->expects($expected ? self::once() : self::never())->method('check');
		$probe = new PollsMutationProbe($apps, $surface, $major);
		self::assertSame($expected, $probe->supports('polls.native_mutation_api'));
		self::assertFalse($probe->supports('polls.unknown'));
	}

	/**
	 * @return void Missing registration and thrown introspection fail closed, with one safe warning per request.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testMissingProbeAndThrownProbeAreClosedAndCached(): void {
		$logger = $this->createMock(LoggerInterface::class);
		self::assertFalse((new CapabilityRegistry([], $logger))->supports('polls.native_mutation_api'));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('9.3.0');
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$surface = $this->createMock(PollsMutationSurface::class);
		$surface->expects(self::once())->method('check')->willThrowException(new \RuntimeException('SECRET_NATIVE_MESSAGE'));
		$logger->expects(self::once())->method('warning')->with(
			'MCP capability probe failed: {probe}: {exception_class} (code {exception_code})',
			self::callback(static fn(array $c): bool => !str_contains(json_encode($c), 'SECRET_NATIVE_MESSAGE'))
		);
		$registry = new CapabilityRegistry([new PollsMutationProbe($apps, $surface, 35)], $logger);
		self::assertFalse($registry->supports('polls.native_mutation_api'));
		self::assertFalse($registry->supports('polls.native_mutation_api'));
	}
}
