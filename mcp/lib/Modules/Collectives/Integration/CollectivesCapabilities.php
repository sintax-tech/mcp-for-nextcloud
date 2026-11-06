<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use Closure;
use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\Util;

/**
 * Whether this installation runs a Collectives release whose native shape this module was built against. Lazy and
 * fail-closed: it reads the installed version, the Nextcloud major and introspects classes, never builds a service.
 * A negative answer only hides the tools; grants and native permissions still decide every call.
 */
final class CollectivesCapabilities implements CapabilityProbe {
	/** The capability every Collectives tool requires. */
	public const KEY = 'collectives.native';
	/** Lowest release per Nextcloud major: what the release itself declares for that major (info.xml). */
	public const MINIMUM = [32 => '4.0.0', 33 => '4.0.0', 34 => '4.4.0', 35 => '4.5.0'];
	/** First release not checked yet; reaching it hides the tools until its shape is proven. */
	public const CEILING = '4.8.0';

	private ?Closure $shape;

	/**
	 * @param IAppManager $apps installed and enabled Collectives version
	 * @param int|null $nextcloudMajor server major, null to read the public version API
	 * @param (callable(): bool)|null $shape test seam; default checks {@see CollectivesSurface::supported()}
	 */
	public function __construct(private IAppManager $apps, private ?int $nextcloudMajor = null, ?callable $shape = null) {
		$this->shape = $shape === null ? null : Closure::fromCallable($shape);
	}

	/** {@inheritDoc} */
	public function keys(): array {
		return [self::KEY];
	}

	/**
	 * {@inheritDoc}
	 * An unknown key is false. What the version checks or the introspection throw is left to the registry, which
	 * counts it as false.
	 */
	public function supports(string $key): bool {
		return $key === self::KEY && $this->native();
	}

	/** @return bool whether the installed Collectives is enabled, inside the proven range and of the proven shape */
	public function native(): bool {
		$version = (string)$this->apps->getAppVersion('collectives');
		$major = $this->nextcloudMajor ?? (int)Util::getVersion()[0];
		$minimum = self::MINIMUM[$major] ?? null;
		if ($version === '' || $minimum === null || version_compare($version, $minimum, '<')
			|| version_compare($version, self::CEILING, '>=') || !AppEnablement::forAnyone($this->apps, 'collectives')) {
			return false;
		}
		return $this->shape !== null ? (bool)($this->shape)() : (new CollectivesSurface())->supported();
	}
}
