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

/** Installation-only DAV absence read capability; no server-version comparison and no fallback. */
final class AbsenceCapabilities implements CapabilityProbe {
	/**
	 * @param IAppManager $apps installed DAV app
	 * @param AbsenceSurface $surface verified native read signatures
	 */
	public function __construct(private IAppManager $apps, private AbsenceSurface $surface) {}
	/** @return list<string> this probe's only key */
	public function keys(): array { return ['availability.native']; }
	/**
	 * @param string $key requested key
	 * @return bool DAV enabled for someone and the native read path intact
	 */
	public function supports(string $key): bool {
		return $key === 'availability.native' && AppEnablement::forAnyone($this->apps, 'dav') && $this->surface->supported();
	}
}
