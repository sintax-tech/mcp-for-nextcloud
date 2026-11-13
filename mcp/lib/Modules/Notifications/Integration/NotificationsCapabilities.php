<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Integration;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;

/** Installation-only app 7/8 capability, including the verified NC34 development manifest. */
final class NotificationsCapabilities implements CapabilityProbe {
	/** @param IAppManager $apps native enablement and version @param NotificationsSurface $surface lazy signature guard */
	public function __construct(private IAppManager $apps, private NotificationsSurface $surface) {}
	/** @return list<string> read capability, then the separate dismiss shape */
	public function keys(): array { return ['notifications.native', 'notifications.dismiss']; }
	/** @param string $key requested capability @return bool supported app, and the shape that key names */
	public function supports(string $key): bool {
		if ($key !== 'notifications.native' && $key !== 'notifications.dismiss') return false;
		$version = (string)$this->apps->getAppVersion('notifications');
		$installed = version_compare($version, '7.0.0-dev.1', '>=') && version_compare($version, '9.0.0', '<')
			&& AppEnablement::forAnyone($this->apps, 'notifications');
		if (!$installed) return false;
		return $key === 'notifications.native' ? $this->surface->supported() : $this->surface->dismissSupported();
	}
}
