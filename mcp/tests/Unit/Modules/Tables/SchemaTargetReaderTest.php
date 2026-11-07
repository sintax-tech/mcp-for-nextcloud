<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{SchemaTargetReader, SchemaServices};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native ID boundaries: not-found equivalence, manage, federation and parent ownership. */
final class SchemaTargetReaderTest extends TestCase {
	/** @return iterable<string,array> Every node-ID form used by the tools. */
	public static function kinds(): iterable { foreach (['table', 'column', 'view'] as $kind) yield $kind => [$kind]; }
	/**
	 * @param string $kind Native entity kind.
	 * @return void
	 */
	#[DataProvider('kinds')]
	public function testNoReadAndMissingHaveTheSamePublicAnswer(string $kind): void {
		foreach ([false, true] as $missing) {
			$reader = $this->reader($missing, false, false);
			try { $reader->target($kind, 1, 'alice'); $this->fail('Must refuse'); }
			catch (ToolFailure $e) { $this->assertSame(CommonMessages::notFound(), $e->getMessage()); }
		}
	}
	/**
	 * @param string $kind Native entity kind.
	 * @return void
	 */
	#[DataProvider('kinds')]
	public function testOnlyReadableNodesMayReturnForbidden(string $kind): void {
		$this->expectExceptionMessage(CommonMessages::forbidden());
		$this->reader(false, true, false)->target($kind, 1, 'alice');
	}
	/** @return void Foreign children are not revealed. */
	public function testForeignParentIsNotFound(): void {
		$this->expectExceptionMessage(CommonMessages::notFound());
		$this->reader(false, true, true)->target('column', 1, 'alice', 99);
	}
	/** @return void A manage grant does not authorize federation. */
	public function testFederatedRefused(): void {
		$this->expectException(ToolFailure::class);
		$this->reader(false, true, true, true)->target('view', 1, 'alice');
	}
	/**
	 * @param bool $missing Missing mapper result.
	 * @param bool $read Native read.
	 * @param bool $manage Native manage.
	 * @param bool $federated Federation origin.
	 * @return SchemaTargetReader Fresh boundary.
	 */
	private function reader(bool $missing, bool $read, bool $manage, bool $federated = false): SchemaTargetReader {
		$node = new class($federated) {
			/** @param bool $federated Origin. */
			public function __construct(private bool $federated) {}
			/** @return int Parent. */ public function getTableId(): int { return 1; }
			/** @return bool Origin. */ public function isFederated(): bool { return $this->federated; }
		};
		$mapper = new class($node, $missing) {
			/**
			 * @param object $node Entity.
			 * @param bool $missing Missing flag.
			 */ public function __construct(private object $node, private bool $missing) {}
			/**
			 * @param int $id Target.
			 * @return object Entity.
			 */ public function find(int $id): object { if ($this->missing) throw new ToolFailure(CommonMessages::notFound()); return $this->node; }
		};
		$permissions = new class($read, $manage) {
			/**
			 * @param bool $read Read flag.
			 * @param bool $manage Manage flag.
			 */ public function __construct(private bool $read, private bool $manage) {}
			/**
			 * @param string $method Permission.
			 * @param array $args Context.
			 * @return bool Permission.
			 */ public function __call(string $method, array $args): bool { return str_contains($method, 'Manage') ? $this->manage : $this->read; }
		};
		$services = $this->createMock(SchemaServices::class); $services->method('version')->willReturn('2.3.2');
		$services->method('get')->willReturnCallback(static fn ($name): object => $name === 'permissions' ? $permissions : $mapper);
		return new SchemaTargetReader($services);
	}
}
