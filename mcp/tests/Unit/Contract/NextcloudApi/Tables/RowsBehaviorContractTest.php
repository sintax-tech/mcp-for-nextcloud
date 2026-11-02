<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables;

use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Modules\Tables\Reading\RowFilterDomain;
use PHPUnit\Framework\TestCase;

/**
 * The domain of user row conditions ({@see RowFilterDomain}) against what each supported release of tables-behavior.json
 * really does: every operator it offers exists in the release's engine, every placeholder it accepts is one the release
 * resolves, and its release gates (is-not-empty, the datetime placeholders with an argument, relation columns) match
 * exactly where the pinned releases gained them.
 */
final class RowsBehaviorContractTest extends TestCase {
	/** One column of each type and subtype the domain knows. */
	private const COLUMNS = [
		['type' => 'text', 'subtype' => 'line'], ['type' => 'number', 'subtype' => ''], ['type' => 'datetime', 'subtype' => 'date'],
		['type' => 'selection', 'subtype' => ''], ['type' => 'selection', 'subtype' => 'multi'], ['type' => 'selection', 'subtype' => 'check'],
		['type' => 'usergroup', 'subtype' => ''], ['type' => 'relation', 'subtype' => ''],
	];

	/** Placeholders the domain accepts in every supported release. */
	private const BASE_PLACEHOLDERS = ['me', 'checked', 'unchecked', 'datetime-date-today', 'datetime-date-start-of-year', 'datetime-date-start-of-month',
		'datetime-date-start-of-week', 'datetime-time-now', 'datetime-now'];

	/**
	 * Every operator offered exists in the engine of the release, for every column type and the meta columns.
	 *
	 * @return void
	 */
	public function testOperatorsExistInTheEngine(): void {
		foreach (self::supported() as $version => $behaviour) {
			$domain = new RowFilterDomain($version);
			$offered = [];
			foreach (self::COLUMNS as $i => $column) {
				$offered = [...$offered, ...$domain->operators(100 + $i, $column)];
			}
			foreach (RowFilterDomain::META_COLUMNS as $meta) {
				$offered = [...$offered, ...$domain->operators($meta, null)];
			}
			$this->assertSame([], array_values(array_diff(array_unique($offered), $behaviour['rowFilterOperators'])), $version);
		}
	}

	/**
	 * The gates match the pinned releases exactly, and the base placeholders exist everywhere.
	 *
	 * @return void
	 */
	public function testTheGatesMatchTheReleases(): void {
		foreach (self::supported() as $version => $behaviour) {
			$names = $behaviour['searchPlaceholders']['names'];
			$this->assertSame([], array_values(array_diff(self::BASE_PLACEHOLDERS, $names)), $version);
			$this->assertSame(in_array('is-not-empty', $behaviour['rowFilterOperators'], true), version_compare($version, RowFilterDomain::IS_NOT_EMPTY_SINCE, '>='), "$version is-not-empty");
			foreach (['datetime-exact-date', 'datetime-days-ahead', 'datetime-days-ago'] as $name) {
				$this->assertSame(in_array($name, $names, true), version_compare($version, RowFilterDomain::DATETIME_ARGUMENTS_SINCE, '>='), "$version $name");
			}
			$this->assertSame(in_array('relation', $behaviour['columnTypes'], true), version_compare($version, RowFilterDomain::RELATION_SINCE, '>='), "$version relation");
		}
	}

	/**
	 * The supported releases of the fixture.
	 *
	 * @return array<string, array<string, mixed>> behaviour by version
	 */
	private static function supported(): array {
		return array_filter(TablesBehavior::versions(), static fn (string $version): bool => version_compare($version, AppEnablement::MINIMUM_VERSIONS['tables'], '>='), ARRAY_FILTER_USE_KEY);
	}
}
