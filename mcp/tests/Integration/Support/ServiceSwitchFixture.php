<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;
use Throwable;

/** Enables MCP for one HTTP test and restores the prior raw switch without issuing a global credential cut. */
final class ServiceSwitchFixture {
	private ?string $before = null;
	private bool $active = false;

	/**
	 * @param IConfig $config Official raw configuration, used for lossless restoration as in McpTaskStatusTest.
	 * @param IAppConfig $apps Official key-presence and cache interface.
	 * @param GrantPolicy $policy Official writer used to enable the service for the test.
	 */
	public function __construct(private IConfig $config, private IAppConfig $apps, private GrantPolicy $policy) {
	}

	/** @return self Enabled scope over the installed disposable test server. */
	public static function open(): self {
		$scope = new self(Server::get(IConfig::class), Server::get(IAppConfig::class), Server::get(GrantPolicy::class));
		$scope->enable();
		return $scope;
	}

	/** @return void Capture presence and value before enabling; a failed enable restores the captured switch. */
	public function enable(): void {
		if ($this->active) {
			return;
		}
		$this->apps->clearCache(false);
		$this->before = $this->apps->hasKey('mcp', GrantPolicy::SERVICE_KEY, null)
			? $this->config->getAppValue('mcp', GrantPolicy::SERVICE_KEY) : null;
		$this->active = true;
		try {
			$this->policy->setGlobalEnabled(true);
			$this->apps->clearCache(false);
		} catch (Throwable $error) {
			$this->restore();
			throw $error;
		}
	}

	/** @return void Restore the exact raw value or absent key, including after an assertion fails. */
	public function restore(): void {
		if (!$this->active) {
			return;
		}
		if ($this->before === null) {
			$this->config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
		} else {
			$this->config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->before);
		}
		$this->apps->clearCache(false);
		$this->active = false;
	}
}
