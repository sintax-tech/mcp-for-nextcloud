<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog;

use OCA\Mcp\Mcp\Tools\CatalogModule;

/** A catalog whose registration names a class that is not a ModuleRegistration. */
final class WrongRegistrationModule extends CatalogModule {
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the probe catalog */
	public static function catalog(): array {
		return [ReadProbeTool::class];
	}

	/** @return class-string a tool class instead of a registration */
	public static function registration(): ?string {
		return ReadProbeTool::class;
	}
}
