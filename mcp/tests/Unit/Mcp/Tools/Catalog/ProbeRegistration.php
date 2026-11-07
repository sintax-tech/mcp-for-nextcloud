<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Registration of {@see RegisteredProbeModule}: one binding and the {@see FeatureProbe}. */
final class ProbeRegistration implements ModuleRegistration {
	/** @param IRegistrationContext $context registration context of the app */
	public function register(IRegistrationContext $context): void {
		$context->registerService(ReadProbeTool::class, static fn (): ReadProbeTool => new ReadProbeTool());
	}

	/** @return list<class-string<FeatureProbe>> the probe of this module */
	public function probes(): array {
		return [FeatureProbe::class];
	}
}
