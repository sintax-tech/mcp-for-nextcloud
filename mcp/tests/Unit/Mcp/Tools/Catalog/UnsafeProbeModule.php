<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog;

use OCA\Mcp\Mcp\Tools\CatalogModule;

/** A catalog with a write that does not implement WriteTool. */
final class UnsafeProbeModule extends CatalogModule {
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the probe catalog */
	public static function catalog(): array {
		return [ReadProbeTool::class, PlainWriteProbeTool::class];
	}
}
