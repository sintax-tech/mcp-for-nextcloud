<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Integration;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;

/** Installation-only user_status shape. No server-version branch and no database read. */
final class StatusCapabilities implements CapabilityProbe {
	/**
	 * @param IAppManager $apps installed native app
	 * @param StatusSurface $surface shared select signature
	 */
	public function __construct(private IAppManager $apps, private StatusSurface $surface) {
	}

	/** @return list<string> this probe's only key */
	public function keys(): array {
		return ['status.native'];
	}

	/**
	 * @param string $key requested key
	 * @return bool app enabled for anyone and the select signature is present
	 */
	public function supports(string $key): bool {
		return $key === 'status.native'
			&& AppEnablement::forAnyone($this->apps, 'user_status')
			&& $this->surface->supported();
	}
}
