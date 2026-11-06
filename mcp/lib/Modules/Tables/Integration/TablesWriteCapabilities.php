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
		$version = (string)$this->apps->getAppVersion('tables');
		$major = $this->major ?? (int)Util::getVersion()[0];
		if (!preg_match('/^\d+\.\d+\.\d+$/D', $version) || !in_array($major, [32, 33, 34, 35], true)) return null;
		foreach ([['1.0.10', '1.0.11', [32], '1.0.10'], ['2.2.2', '2.2.3', [33, 34, 35], '2.2.2'], ['2.3.1', '2.3.3', [33, 34, 35], '2.3.2']] as [$min, $max, $majors, $profile]) {
			if (in_array($major, $majors, true) && version_compare($version, $min, '>=') && version_compare($version, $max, '<')) return $profile;
		}
		// Positional 1.0.0 / 2.0.0 adapters require supplemental runtime proof before enabling a range.
		return null;
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
			$common = str_contains($entry[0], 'PermissionsService') || in_array($entry[0], [
				'OCA\\Tables\\Db\\Table', 'OCA\\Tables\\Db\\View', 'OCA\\Tables\\Db\\TableMapper', 'OCA\\Tables\\Db\\ViewMapper', 'OCA\\Tables\\Db\\ContextMapper'], true);
			if ($key === 'tables.native_schema_api' && $isShare) continue;
			if ($key === 'tables.native_sharing_api' && !$isShare && !$common) continue;
			if (!($this->probe !== null ? ($this->probe)($entry) : SchemaApiShape::matches($entry))) return false;
		}
		return true;
	}
}
