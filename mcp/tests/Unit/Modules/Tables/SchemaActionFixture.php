<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{SchemaAction, SchemaPreparation, SchemaNativeWriter, SchemaObservation};
use PHPUnit\Framework\TestCase;

/** Builds the actual typed actions for protocol tests, or over a real authorization assembly. */
final class SchemaActionFixture {
	/** @param TestCase $test Mock owner. @param string $name Published suffix. @param SchemaPreparation|null $preparation Real authorization when tested. @return SchemaAction Actual tool-specific action. */
	public static function of(TestCase $test, string $name, ?SchemaPreparation $preparation = null): SchemaAction {
		$mock = new \ReflectionMethod($test, 'createMock');
		$class = 'OCA\\Mcp\\Modules\\Tables\\Integration\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name))) . 'Action';
		return new $class($preparation ?? $mock->invoke($test, SchemaPreparation::class), $mock->invoke($test, SchemaNativeWriter::class), $mock->invoke($test, SchemaObservation::class));
	}
}
