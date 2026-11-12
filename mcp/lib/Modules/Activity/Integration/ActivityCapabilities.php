<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Integration;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;

/** Installation-only, lazy Activity 7/8 surface capability; no server-version fallback. */
final class ActivityCapabilities implements CapabilityProbe {
	/**
	 * @param IAppManager $apps installed native app
	 * @param ActivitySurface $surface shared read signature
	 */
	public function __construct(private IAppManager $apps, private ActivitySurface $surface) {}
	/** @return list<string> this probe's only key */
	public function keys(): array { return ['activity.native']; }
	/**
	 * @param string $key requested key
	 * @return bool app within the verified range and safe shape
	 */
	public function supports(string $key): bool {
		$version = (string)$this->apps->getAppVersion('activity');
		return $key === 'activity.native' && version_compare($version, '7.0.0', '>=') && version_compare($version, '9.0.0', '<')
			&& AppEnablement::forAnyone($this->apps, 'activity') && $this->surface->supported();
	}
}
