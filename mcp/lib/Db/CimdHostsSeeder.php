<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCA\Mcp\OAuth\Clients\ClientMetadataFetcher;
use OCP\{IAppConfig, IConfig};

/** Install/upgrade initialization only: preserves explicit settings and never overwrites a live row or tombstone. */
class CimdHostsSeeder {
	/** @param CimdHostsMapper $hosts Official host table. @param IConfig $config Existing allowlist. @param IAppConfig $apps Cache barrier. */
	public function __construct(private CimdHostsMapper $hosts, private IConfig $config, private IAppConfig $apps) {}

	/** @return void Seed legacy generation 1 only for currently trusted hosts, atomically insert-if-absent. */
	public function seed(): void {
		$this->apps->clearCache(false);
		$value = $this->config->getAppValue('mcp', ClientMetadataFetcher::HOSTS_KEY, ClientMetadataFetcher::DEFAULT_HOSTS);
		$hosts = array_filter(array_map('trim', explode(',', strtolower($value))), static fn (string $host): bool => $host !== '');
		foreach ($hosts === [] ? explode(',', ClientMetadataFetcher::DEFAULT_HOSTS) : array_unique($hosts) as $host) {
			$this->hosts->seed($host);
		}
	}
}
