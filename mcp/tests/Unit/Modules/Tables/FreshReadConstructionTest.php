<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{SchemaServices, TablesServices};
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** Caller-bound primitive construction and no reuse of cached read-boundary objects. */
final class FreshReadConstructionTest extends TestCase {
	/** @return void The native PermissionsService's required userId/isCLI shape is supported without request-global cache reuse. */
	public function testFreshHttpActorAndNoCachedReader(): void {
		$services = new SchemaServices($this->createMock(TablesServices::class), $this->createMock(ContainerInterface::class), $this->createMock(TablesSessionGuard::class));
		$constructor = new \ReflectionMethod($services, 'fresh');
		$first = $constructor->invoke($services, HttpReadBoundary::class, 'alice');
		$second = $constructor->invoke($services, HttpReadBoundary::class, 'alice');
		$this->assertNotSame($first, $second);
		$this->assertSame('alice', $first->userId);
		$this->assertFalse($first->isCLI);
	}
}

/** Test-only primitive shape, matching the native read boundary's non-object dependencies. */
final class HttpReadBoundary {
	/**
	 * @param string|null $userId Request actor.
	 * @param bool $isCLI HTTP boundary flag.
	 */
	public function __construct(public ?string $userId, public bool $isCLI) {}
}
