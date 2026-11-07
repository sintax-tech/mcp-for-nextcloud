<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesMarkdownPolicy;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The DI of the Collectives module. Its tools and work classes are built by the container from their constructors;
 * native Collectives services are only resolved by the gateway, after admission. The module answers the
 * `collectives.native` capability through its probe, and binds its page writes to {@see CollectivesMarkdownPolicy}
 * (decision C4 of #22, contract §4): only `.md` UTF-8 pages the page service resolved, in place of the Files
 * allowlist of #15, which `files_*` tools keep for the same folder.
 */
final class CollectivesRegistration implements ModuleRegistration {
	/**
	 * {@inheritDoc}
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(CollectivesMarkdownPolicy::class, static fn (): CollectivesMarkdownPolicy => new CollectivesMarkdownPolicy());
	}

	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> the probe of the native Collectives shape
	 */
	public function probes(): array {
		return [CollectivesCapabilities::class];
	}
}
