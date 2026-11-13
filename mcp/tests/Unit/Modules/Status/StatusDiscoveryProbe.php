<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Status;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;

/** Discovery seam only; the native select signature is proved separately against both source pins. */
final class StatusDiscoveryProbe implements CapabilityProbe {
	/** @param bool $enabled simulated capability */
	public function __construct(private bool $enabled = true) {
	}

	/** @return list<string> test key */
	public function keys(): array {
		return ['status.native'];
	}

	/**
	 * @param string $key queried key
	 * @return bool explicit discovery state
	 */
	public function supports(string $key): bool {
		return $key === 'status.native' && $this->enabled;
	}
}
