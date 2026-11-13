<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Notifications;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;

/** Discovery-only seam; native range and shape are tested against both pinned sources. */
final class NotificationsDiscoveryProbe implements CapabilityProbe {
	/** @param bool $enabled simulated installation capability */
	public function __construct(private bool $enabled = true) {}
	/** @return list<string> native metadata key */
	public function keys(): array { return ['notifications.native']; }
	/** @param string $key requested capability @return bool explicit test state */
	public function supports(string $key): bool { return $key === 'notifications.native' && $this->enabled; }
}
