<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Integration\PollsMutationProbe;
use OCA\Mcp\Modules\Polls\Integration\PollsMutationSurface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Discovery-only fixture: native shape is explicit and no optional native service is constructed. */
final class PollsDiscoveryProbe {
	/**
	 * @param TestCase $test Mock owner.
	 * @param string $version Installed Polls version.
	 * @param int $major Nextcloud major.
	 * @param bool $enabled Installation enablement.
	 * @return PollsMutationProbe Actual version/enablement probe over an inert shape double.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function build(TestCase $test, string $version = '9.3.0', int $major = 35, bool $enabled = true): PollsMutationProbe {
		$mock = static fn(string $class): object => (new ReflectionMethod($test, 'createMock'))->invoke($test, $class);
		$apps = $mock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($version);
		$apps->method('isEnabledForAnyone')->willReturn($enabled);
		$surface = $mock(PollsMutationSurface::class);
		return new PollsMutationProbe($apps, $surface, $major);
	}
}
