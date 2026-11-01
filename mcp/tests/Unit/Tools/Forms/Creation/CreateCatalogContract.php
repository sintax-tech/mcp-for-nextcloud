<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tools\Forms\FormsModule;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/** Updates the existing registry test through one delegation, preserving its read and default-deny assertions. */
final class CreateCatalogContract {
	/** Checks the full catalog and separate creation grant without constructing native services.
	 * @param TestCase $test Existing registry test's fixture owner.
	 * @return void
	 */
	public static function check(TestCase $test): void {
		\OCA\Mcp\Tests\Unit\Tools\Forms\Editing\EditCatalogContract::check($test);
	}
}
