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
 * Whether this installation can manage the members of a collective through MCP: everything `collectives.native`
 * asks, Teams (Circles) enabled in the release of a Nextcloud 33 to 35 (decision D7 of C04), and the native shapes and
 * constant values of {@see TeamsSurface}. Lazy and fail-closed; a negative answer only hides the member tools.
 */
final class TeamsCapabilities implements CapabilityProbe {
	/** The capability every member tool requires. */
	public const KEY = 'collectives.teams';

	private ?Closure $shape;

	/**
	 * @param IAppManager $apps installed and enabled Collectives and Teams versions
	 * @param CollectivesCapabilities $native the page capability every Collectives tool also requires
	 * @param int|null $nextcloudMajor server major, null to read the public version API
	 * @param (callable(): bool)|null $shape test seam; default checks {@see TeamsSurface::supported()}
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
		return $key === self::KEY && $this->teams();
	}

	/** @return bool whether Collectives is supported, Teams runs the release of a proven major and the shapes match */
	public function teams(): bool {
		$major = $this->nextcloudMajor ?? (int)Util::getVersion()[0];
		$teams = (string)$this->apps->getAppVersion('circles');
		if (!in_array($major, FoundingCapabilities::MAJORS, true) || $teams === '' || (int)explode('.', $teams)[0] !== $major
			|| !AppEnablement::forAnyone($this->apps, 'circles') || !$this->native->native()) {
			return false;
		}
		return $this->shape !== null ? (bool)($this->shape)() : TeamsSurface::supported();
	}
}
