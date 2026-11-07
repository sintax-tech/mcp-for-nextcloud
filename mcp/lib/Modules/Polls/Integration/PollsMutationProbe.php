<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\Util;

/** Installation-only lazy shape/version gate; CapabilityRegistry catches and logs one failure per request. */
final class PollsMutationProbe implements CapabilityProbe {
	/** Minimum/tight line ceiling for each proved Nextcloud major. */
	public const VERSIONS = [32 => ['8.6.3', '8.7.0'], 33 => ['9.0.7', '9.1.0'], 34 => ['9.1.4', '9.2.0'], 35 => ['9.3.0', '9.4.0']];
	/**
	 * @param IAppManager $apps Installed version and installation enablement.
	 * @param PollsMutationSurface $surface Lazy introspection only.
	 * @param int|null $nextcloudMajor Explicit test server major, or the public runtime version.
	 * @return void
	 */
	public function __construct(private IAppManager $apps, private PollsMutationSurface $surface, private ?int $nextcloudMajor = null) {
	}

	/**
	 * {@inheritDoc}
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function keys(): array {
		return ['polls.native_mutation_api'];
	}

	/**
	 * {@inheritDoc}
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function supports(string $key): bool {
		if ($key !== 'polls.native_mutation_api') {
			return false;
		}
		$v = $this->apps->getAppVersion('polls');
		$bounds = self::VERSIONS[$this->nextcloudMajor ?? (int) Util::getVersion()[0]] ?? null;
		if ($bounds === null
			|| !AppEnablement::forAnyone($this->apps, 'polls')
			|| version_compare($v, $bounds[0], '<')
			|| version_compare($v, $bounds[1], '>=')) {
			return false;
		}
		$this->surface->check(version_compare($v, '9.0.0', '>='));
		return true;
	}
}
