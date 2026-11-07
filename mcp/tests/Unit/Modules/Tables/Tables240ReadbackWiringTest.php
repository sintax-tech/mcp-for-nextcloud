<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\SchemaObservation;
use OCA\Mcp\Modules\Tables\Integration\SchemaTargetReader;
use OCA\Mcp\Modules\Tables\Presentation\SchemaSnapshot;
use OCA\Mcp\Modules\Tables\Writing\SchemaReceiptComparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises preserved native metadata through the real observation entry points. */
final class Tables240ReadbackWiringTest extends TestCase {
	/** @return iterable<string,array{string,mixed}> Each preserved field can independently differ. */
	public static function changes(): iterable {
		yield 'layout' => ['layout', 'table'];
		yield 'card-settings' => ['viewSettings', ['cardTitleSource' => null, 'cardBackgroundSource' => null]];
	}

	/**
	 * @param string $field Native field changed after column creation.
	 * @param mixed $value Fresh unauthorized difference from the preserved expectation.
	 * @return void Removing the preserved() call in columnCreated must make this assertion fail.
	 */
	#[DataProvider('changes')]
	public function testColumnReadbackRejectsChangedPreservedMetadata(string $field, mixed $value): void {
		$old = ['columnId' => 1, 'order' => 0, 'readonly' => false, 'mandatory' => false];
		$new = ['columnId' => 2, 'order' => 1, 'readonly' => false, 'mandatory' => false];
		$before = self::view() + ['columnSettings' => [$old]];
		$view = new SchemaNativeEntity(array_replace($before, ['columnSettings' => [$old, $new], $field => $value]));
		$column = new SchemaNativeEntity(['id' => 2, 'tableId' => 1, 'type' => 'text', 'subtype' => 'line']);
		$reader = $this->createMock(SchemaTargetReader::class);
		$reader->method('target')->willReturnCallback(static fn (string $kind): object => $kind === 'column' ? $column : $view);
		$observed = (new SchemaObservation($reader))->columnCreated(['kind' => 'column', 'arguments' => ['table_id' => 1, 'view_ids' => [3]],
			'views' => [3 => $before]], 2, 'alice');
		self::assertFalse($observed['viewsVerified']);
		self::assertSame([$old, $new], $observed['views'][3]['columnSettings'], 'Membership alone is correct and cannot explain the refusal');
	}

	/**
	 * @param string $field Native field changed during update_view.
	 * @param mixed $value Fresh native result.
	 * @return void node() retains the changed field so the receipt must report it rather than fabricate preservation.
	 */
	#[DataProvider('changes')]
	public function testUpdateViewNodeReadbackDetectsChangedMetadata(string $field, mixed $value): void {
		$expected = SchemaSnapshot::of(self::view(), 'view');
		$reader = $this->createMock(SchemaTargetReader::class);
		$reader->expects(self::once())->method('target')->with('view', 3, 'alice', 1)
			->willReturn(new SchemaNativeEntity(array_replace(self::view(), [$field => $value])));
		$observed = (new SchemaObservation($reader))->node(['kind' => 'view', 'arguments' => ['table_id' => 1]], 3, 'alice');
		self::assertArrayHasKey($field, $observed);
		self::assertSame($value, $observed[$field]);
		self::assertSame([$field], SchemaReceiptComparison::differences($expected, $observed));
	}

	/** @return array<string,mixed> Authorized native 2.4.0 metadata before the operation. */
	private static function view(): array {
		return ['id' => 3, 'tableId' => 1, 'layout' => 'gallery', 'viewSettings' => ['cardTitleSource' => 1, 'cardBackgroundSource' => null]];
	}
}
