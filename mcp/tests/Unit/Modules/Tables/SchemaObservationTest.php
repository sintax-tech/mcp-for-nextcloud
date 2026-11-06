<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{SchemaObservation, SchemaTargetReader};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A new column being present is insufficient if an existing view setting was lost. */
final class SchemaObservationTest extends TestCase {
	/** @return iterable<string,array{bool}> Preserved versus lost prior settings. */
	public static function outcomes(): iterable { yield 'preserved' => [false]; yield 'lost' => [true]; }
	/**
	 * @param bool $lost Whether a concurrent/native change removed a prior visible column.
	 * @return void
	 */
	#[DataProvider('outcomes')]
	public function testReadbackVerifiesTheCompleteViewSettings(bool $lost): void {
		$old = ['columnId' => 1, 'order' => 0, 'readonly' => true, 'mandatory' => true];
		$new = ['columnId' => 2, 'order' => 1, 'readonly' => false, 'mandatory' => false];
		$view = new class($lost ? [$new] : [$old, $new]) {
			/** @param array $settings Fresh native view information. */
			public function __construct(private array $settings) {}
			/** @return array<string,mixed> Native metadata. */
			public function jsonSerialize(): array { return ['id' => 7, 'tableId' => 1, 'columnSettings' => $this->settings]; }
			/** @return list<int> Visible native columns. */
			public function getColumnIds(): array { return array_column($this->settings, 'columnId'); }
		};
		$column = new class {
			/** @return array<string,mixed> Created native column. */
			public function jsonSerialize(): array { return ['id' => 2, 'tableId' => 1, 'type' => 'text', 'subtype' => 'line']; }
		};
		$reader = $this->createMock(SchemaTargetReader::class);
		$reader->method('target')->willReturnCallback(static fn ($kind): object => $kind === 'column' ? $column : $view);
		$observed = (new SchemaObservation($reader))->columnCreated(['arguments' => ['table_id' => 1, 'view_ids' => [7]], 'kind' => 'column', 'views' => [7 => ['columnSettings' => [$old]]]], 2, 'alice');
		$this->assertSame(!$lost, $observed['viewsVerified']);
	}
}
