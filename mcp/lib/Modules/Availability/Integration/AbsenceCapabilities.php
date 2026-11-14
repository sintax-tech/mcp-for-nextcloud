<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Integration;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;

/** Installation-only DAV absence capabilities, read and write; no server-version comparison and no fallback. */
final class AbsenceCapabilities implements CapabilityProbe {
	/**
	 * @param IAppManager $apps installed DAV app
	 * @param AbsenceSurface $surface verified native read signatures
	 */
	public function __construct(private IAppManager $apps, private AbsenceSurface $surface) {}
	/** @return list<string> the read key and the create-or-update key */
	public function keys(): array { return ['availability.native', 'availability.write']; }
	/**
	 * @param string $key requested key
	 * @return bool DAV enabled for someone and the native path of that key intact
	 */
	public function supports(string $key): bool {
		if (!in_array($key, $this->keys(), true) || !AppEnablement::forAnyone($this->apps, 'dav')) return false;
		return $key === 'availability.native' ? $this->surface->supported() : $this->surface->writable();
	}
}
