<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\McpDeckProbe\AppInfo;

use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Registers only a test notification delivery component with the real native manager. */
final class Application extends \OCP\AppFramework\App implements IBootstrap {
	/** Bind the disposable app namespace. */
	public function __construct() { parent::__construct('mcp_deck_probe'); }
	/** @param IRegistrationContext $context Native bootstrap context; no replacement services. */
	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(\OCA\McpDeckProbe\PrepareFault::class);
	}
	/** @param IBootContext $context Native boot with the real IManager. */
	public function boot(IBootContext $context): void {
		$context->injectFn(static function (\OCP\Notification\IManager $manager): void {
			$manager->registerApp(\OCA\McpDeckProbe\Delivery::class);
		});
	}
}
