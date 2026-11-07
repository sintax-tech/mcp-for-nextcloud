<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{SchemaPreparation, SchemaNativeWriter, SchemaObservation};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Each concrete tool chooses its own native sink and observation; strings never dispatch the effect. */
final class SchemaActionTest extends TestCase {
	/** @return iterable<string,array> Explicit tool-specific effects and observation strategies. */
	public static function actions(): iterable {
		foreach (['CreateTable' => 'node', 'UpdateTable' => 'node', 'CreateColumn' => 'columnCreated', 'UpdateColumn' => 'node', 'CreateView' => 'node', 'UpdateView' => 'node', 'ShareNode' => 'sharing', 'UnshareNode' => 'revocation'] as $class => $observation) yield $class => [$class, $observation];
	}
	/** @param string $class Concrete action stem. @param string $observe Exact readback method. @return void */
	#[DataProvider('actions')]
	public function testEachActionCallsOnlyItsOwnNativeEffectAndReadback(string $class, string $observe): void {
		$preparation = $this->createMock(SchemaPreparation::class);
		$writer = $this->createMock(SchemaNativeWriter::class);
		$observation = $this->createMock(SchemaObservation::class);
		$method = lcfirst($class);
		foreach (['createTable', 'updateTable', 'createColumn', 'updateColumn', 'createView', 'updateView', 'shareNode', 'unshareNode'] as $sink) {
			if ($sink === $method) $writer->expects($this->once())->method($sink)->with(['id' => 2], 'alice')->willReturn(3);
			else $writer->expects($this->never())->method($sink);
		}
		$observation->expects($this->once())->method($observe)->willReturn(['title' => 'Observed']);
		$fqcn = 'OCA\\Mcp\\Modules\\Tables\\Integration\\' . $class . 'Action';
		$action = new $fqcn($preparation, $writer, $observation);
		$this->assertSame(3, $action->apply(['id' => 2], 'alice'));
		$this->assertSame(['title' => 'Observed'], $action->observe(['id' => 2], 3, 'alice'));
	}
}
