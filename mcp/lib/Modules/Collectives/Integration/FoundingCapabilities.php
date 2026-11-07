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
 * Whether this installation can create a collective through MCP: everything `collectives.native` asks, Teams (Circles)
 * enabled in the release of the running Nextcloud, and the native shapes of {@see FoundingSurface}. Only Nextcloud 33
 * to 35 qualify (decision D7 of C04): on 32 nothing tells, without writing, whether the actor already has a Teams
 * identity. Lazy and fail-closed like every probe; a negative answer only hides the tool.
 */
final class FoundingCapabilities implements CapabilityProbe {
	/** The capability collectives_create_collective requires. */
	public const KEY = 'collectives.founding';
	/** Nextcloud majors whose Teams release and native shapes were proven. */
	public const MAJORS = [33, 34, 35];

	private ?Closure $shape;

	/**
	 * @param IAppManager $apps installed and enabled Collectives and Teams versions
	 * @param CollectivesCapabilities $native the page capability every Collectives tool also requires
	 * @param int|null $nextcloudMajor server major, null to read the public version API
	 * @param (callable(): bool)|null $shape test seam; default checks {@see FoundingSurface::supported()}
	 */
	public function __construct(
		private IAppManager $apps,
		private CollectivesCapabilities $native,
		private ?int $nextcloudMajor = null,
		?callable $shape = null,
	) {
		$this->shape = $shape === null ? null : Closure::fromCallable($shape);
	}

	/** {@inheritDoc} */
	public function keys(): array {
		return [self::KEY];
	}

	/**
	 * {@inheritDoc}
	 * An unknown key is false; what the checks throw is left to the registry, which counts it as false.
	 */
	public function supports(string $key): bool {
		return $key === self::KEY && $this->founding();
	}

	/** @return bool whether Collectives is supported, Teams runs the release of a proven major and the shapes match */
	public function founding(): bool {
		$major = $this->nextcloudMajor ?? (int)Util::getVersion()[0];
		$teams = (string)$this->apps->getAppVersion('circles');
		if (!in_array($major, self::MAJORS, true) || $teams === '' || (int)explode('.', $teams)[0] !== $major
			|| !AppEnablement::forAnyone($this->apps, 'circles') || !$this->native->native()) {
			return false;
		}
		return $this->shape !== null ? (bool)($this->shape)() : FoundingSurface::supported();
	}
}
