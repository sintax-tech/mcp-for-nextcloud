<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Availability;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;

/** Discovery seam only; the native absence shape is proved separately against the verbatim source pins. */
final class AvailabilityDiscoveryProbe implements CapabilityProbe {
	/** @param bool $enabled simulated capability */
	public function __construct(private bool $enabled = true) {}
	/** @return list<string> test keys */
	public function keys(): array { return ['availability.native', 'availability.write']; }
	/**
	 * @param string $key queried key
	 * @return bool explicit discovery state
	 */
	public function supports(string $key): bool { return in_array($key, $this->keys(), true) && $this->enabled; }
}
