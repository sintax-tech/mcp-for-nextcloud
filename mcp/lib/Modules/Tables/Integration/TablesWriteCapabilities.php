<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\Util;

/** Evidence-bounded version families plus complete consumed API introspection. */
class TablesWriteCapabilities implements CapabilityProbe {
	/**
	 * @param IAppManager $apps Installation state.
	 * @param int|null $major Test seam; otherwise public NC version.
	 * @param \Closure|null $probe Test seam for a single native member.
	 */
	public function __construct(private IAppManager $apps, private ?int $major = null, private ?\Closure $probe = null) {}
	/** @return list<string> Installation-wide keys, never actor grants. */
	public function keys(): array { return ['tables.native_schema_api', 'tables.native_sharing_api']; }
	/** @return string|null Native family proven by source comparisons; unknown/prerelease versions stay closed. */
	public function profile(): ?string {
		return SchemaVersionFamily::of((string)$this->apps->getAppVersion('tables'), $this->major ?? (int)Util::getVersion()[0]);
	}
	/**
	 * @param string $key Known capability key.
	 * @return bool All native members fit, with no services/database/session constructed.
	 */
	public function supports(string $key): bool {
		if (!in_array($key, $this->keys(), true) || !AppEnablement::forAnyone($this->apps, 'tables')) return false;
		$profile = $this->profile();
		if ($profile === null) return false;
		$members = SchemaApiShape::members($profile);
		if ($members === []) return false;
		foreach ($members as $entry) {
			$isShare = str_contains($entry[0], 'Share');
			$common = SchemaViewShape::common($entry[0]) || str_contains($entry[0], 'PermissionsService') || in_array($entry[0], [
				'OCA\\Tables\\Db\\Table', 'OCA\\Tables\\Db\\View', 'OCA\\Tables\\Db\\TableMapper', 'OCA\\Tables\\Db\\ViewMapper', 'OCA\\Tables\\Db\\ContextMapper'], true);
			if ($key === 'tables.native_schema_api' && $isShare) continue;
			if ($key === 'tables.native_sharing_api' && !$isShare && !$common) continue;
			if (!($this->probe !== null ? ($this->probe)($entry) : SchemaApiShape::matches($entry))) return false;
		}
		return true;
	}
}
