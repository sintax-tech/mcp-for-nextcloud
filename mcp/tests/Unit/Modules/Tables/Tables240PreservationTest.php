<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use InvalidArgumentException;
use OCA\Mcp\Modules\Tables\Presentation\SchemaSnapshot;
use OCA\Mcp\Modules\Tables\Presentation\SchemaViewSnapshot;
use OCA\Mcp\Modules\Tables\Writing\SchemaReceiptComparison;
use OCA\Mcp\Modules\Tables\Writing\SchemaViewChanges;
use OCA\Mcp\Modules\Tables\Writing\SchemaViewPreservation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native 2.4.0 card sources are preserved, never silently cleared by a column-selection change. */
final class Tables240PreservationTest extends TestCase {
	/** @return iterable<string,array{string}> Both independent card sources can trigger native cleanup. */
	public static function sources(): iterable {
		yield 'title' => ['cardTitleSource'];
		yield 'background' => ['cardBackgroundSource'];
	}

	/**
	 * @param string $source Native card-source key.
	 * @return void Dropping a referenced column refuses the patch before any DTO or native effect.
	 */
	#[DataProvider('sources')]
	public function testDroppingEitherCardSourceIsRefused(string $source): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('patch.columns');
		SchemaViewChanges::of(['columns' => [2]], ['viewSettings' => [$source => 1]], self::columns(), '2.4.0');
	}

	/** @return void Keeping sources or clearing the explicit selection preserves native managed-column fallback. */
	public function testPreservingSourcesAndEmptySelectionRemainSupported(): void {
		$before = ['layout' => 'gallery', 'viewSettings' => ['cardTitleSource' => 1, 'cardBackgroundSource' => 2]];
		$patch = SchemaViewChanges::of(['columns' => [2, 1]], $before, self::columns(), '2.4.0');
		self::assertSame([2, 1], array_column($patch['columnSettings'], 'columnId'));
		self::assertArrayNotHasKey('layout', $patch);
		self::assertArrayNotHasKey('viewSettings', $patch);
		self::assertSame(['columnSettings' => []], SchemaViewChanges::of(['columns' => []], $before, self::columns(), '2.4.0'));
		self::assertSame(['description' => '0'], SchemaViewChanges::of(['description' => '0'], $before, self::columns(), '2.4.0'));
	}

	/** @return void An orphan cannot pass merely because an empty explicit selection makes native fall back. */
	public function testEmptySelectionStillRefusesAnUnavailableSource(): void {
		$this->expectException(InvalidArgumentException::class);
		SchemaViewChanges::of(['columns' => []], ['viewSettings' => ['cardTitleSource' => 99]], self::columns(), '2.4.0');
	}

	/** @return void Preserved fields enter the plan/readback comparison and detect unintended native changes. */
	public function testSnapshotsVerifyLayoutAndSettingsWithoutAcceptingNewWritableArguments(): void {
		$before = SchemaSnapshot::of(['id' => 3, 'layout' => 'tiles', 'viewSettings' => ['cardTitleSource' => 1, 'cardBackgroundSource' => null]], 'view');
		self::assertSame('tiles', $before['layout']);
		self::assertSame(['cardTitleSource' => 1, 'cardBackgroundSource' => null], $before['viewSettings']);
		self::assertSame([], SchemaReceiptComparison::differences($before, $before));
		$changed = array_replace($before, ['viewSettings' => ['cardTitleSource' => null, 'cardBackgroundSource' => null]]);
		self::assertSame(['viewSettings'], SchemaReceiptComparison::differences($before, $changed));
		self::assertFalse(SchemaViewSnapshot::preserved($before, $changed));
		self::assertFalse(SchemaViewSnapshot::preserved($before, array_replace($before, ['layout' => 'table'])));
		self::assertTrue(SchemaViewSnapshot::preserved($before, $before));
	}

	/** @return void Adding a new explicit view column must not turn all-columns semantics into card-source loss. */
	public function testCreatingAColumnRefusesImplicitCardCleanup(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('view_ids');
		SchemaViewPreservation::columnAdded(['columnSettings' => [], 'viewSettings' => ['cardTitleSource' => 1]], '2.4.0');
	}

	/** @return void Existing explicit sources remain supported when a new column is added. */
	public function testCreatingAColumnPreservesExplicitCardSources(): void {
		$view = ['columnSettings' => [['columnId' => 1]], 'viewSettings' => ['cardTitleSource' => 1]];
		self::assertSame($view, SchemaViewPreservation::columnAdded($view, '2.4.0'));
	}

	/** @return array<int,array<string,mixed>> Authorized native managed columns. */
	private static function columns(): array {
		return [1 => ['type' => 'text', 'subtype' => 'line'], 2 => ['type' => 'text', 'subtype' => 'line']];
	}
}
