<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Tasks\Locating\TaskStore;
use OCA\Mcp\Modules\Tasks\Integration\DavTaskStore;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/** Registers the Tasks query port, resolving the native backend lazily as before. */
final class TasksRegistration implements ModuleRegistration {
	/** {@inheritDoc} */
	public function register(IRegistrationContext $context): void {
		$context->registerService(TaskStore::class, static fn (ContainerInterface $c) => new DavTaskStore($c));
	}

	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> no capability probe is required */
	public function probes(): array {
		return [];
	}
}
