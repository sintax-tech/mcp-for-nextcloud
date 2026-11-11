<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables;

use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Modules\Tables\Presentation\ColumnOptions;
use PHPUnit\Framework\TestCase;

/**
 * What the Tables module relies on beyond signatures holds in every supported release of tables-behavior.json, and what
 * keeps 0.9 out still holds there. The facts the row slices will rely on are pinned too, so a regenerated fixture that
 * changes them cannot pass unnoticed.
 */
final class TablesBehaviorContractTest extends TestCase {
	/** Serialized column fields that are not options of a type: what every column has, and who made or changed it. */
	private const GENERAL_FIELDS = ['id', 'uuid', 'tableId', 'title', 'technicalName', 'createdBy', 'createdByDisplayName', 'createdAt',
		'lastEditBy', 'lastEditByDisplayName', 'lastEditAt', 'viewColumnInformation', 'type', 'subtype', 'mandatory', 'description',
		'customSettings'];

	/** Options of a type whose serialized name does not start with the type, by type. */
	private const UNPREFIXED_OPTIONS = ['usergroup' => ['showUserStatus']];

	/**
	 * Keys of `customSettings` the Tables app validates that are not options of a type, each left out on purpose.
	 *
	 * @var array<string, string>
	 */
	private const REVIEWED_DISPLAY_SETTINGS = [
		'width' => 'display width of the column in the Tables interface (50 to 1000 px), layout, not schema',
		'showPreview' => 'whether the interface shows link previews in the column (2.3), layout, not schema',
		'imagePreviewSize' => 'size of those previews in the interface (2.3, 32 to 192 px), layout, not schema',
	];

	/**
	 * The supported releases of the fixture.
	 *
	 * @return array<string, array<string, mixed>> behaviour by version
	 */
	private static function supported(): array {
		return array_filter(TablesBehavior::versions(), static fn (string $version): bool => version_compare($version, AppEnablement::MINIMUM_VERSIONS['tables'], '>='), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * The six compared releases, each from its official tarball.
	 *
	 * @return void
	 */
	public function testTheComparedReleases(): void {
		$this->assertSame(['0.9.12', '1.0.0', '2.0.0', '2.2.2', '2.3.2'], array_keys(TablesBehavior::versions()));
		foreach (TablesBehavior::sources() as $version => $source) {
			$this->assertStringEndsWith("/v$version/tables-v$version.tar.gz", $source['url']);
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $source['sha256']);
		}
	}

	/**
	 * findAll() creates a tutorial table by default for a user without tables, in every release: the module must pass
	 * `createTutorial = false`, at the fourth position.
	 *
	 * @return void
	 */
	public function testFindAllCreatesTheTutorialTableByDefault(): void {
		foreach (TablesBehavior::versions() as $version => $behaviour) {
			$findAll = $behaviour['tableServiceFindAll'];
			$this->assertSame(['userId', 'skipTableEnhancement', 'skipSharedTables', 'createTutorial'], array_column($findAll['parameters'], 'name'), $version);
			$this->assertSame('true', $findAll['createTutorialDefault'], $version);
			$this->assertTrue($findAll['tutorialOnlyForAUserWithoutTables'], $version);
		}
	}

	/**
	 * A view serializes without its filter, a column with every field the column shape reads, a view's column settings
	 * carry read-only and mandatory, and a context node names its node by id and type, a view being type 1.
	 *
	 * @return void
	 */
	public function testTheShapesTheModuleReads(): void {
		$read = ['id', 'tableId', 'title', 'type', 'subtype', 'description', 'mandatory', 'viewColumnInformation', ...array_merge(...array_values(ColumnOptions::TYPE_FIELDS))];
		foreach (self::supported() as $version => $behaviour) {
			$this->assertNotContains('filter', $behaviour['viewSerializedKeys'], $version);
			$this->assertSame([], array_values(array_diff($read, $behaviour['columnSerializedKeys'])), "$version: column fields the shape reads");
			$this->assertSame([], array_values(array_diff(['readonly', 'mandatory'], $behaviour['viewColumnSettingKeys'])), $version);
			$this->assertSame(['id', 'node_id', 'node_type', 'permissions'], $behaviour['contextNodeKeys'], $version);
			$this->assertSame(['table' => 0, 'view' => 1], $behaviour['nodeTypes'], $version);
		}
	}

	/**
	 * Every column type of every supported release has its options listed, and they are exactly the options the release
	 * defines for it: the serialized fields named after the type (plus the reviewed unprefixed ones) and, for a relation,
	 * the Column::RELATION_* keys of `customSettings`. Every serialized field is a general one or an option of a type, so
	 * a new native option cannot go unnoticed; the other validated `customSettings` keys are reviewed display settings.
	 *
	 * @return void
	 */
	public function testTheOptionsAreExactlyTheNativeOnesOfEachType(): void {
		foreach (self::supported() as $version => $behaviour) {
			$fields = $behaviour['columnSerializedKeys'];
			$types = $behaviour['columnTypes'];
			$this->assertSame([], array_values(array_diff($types, array_keys(ColumnOptions::TYPE_FIELDS))), "$version: column types without a list of options");
			$classified = self::GENERAL_FIELDS;
			foreach ($types as $type) {
				$native = [...array_values(array_filter($fields, static fn (string $field): bool => str_starts_with($field, $type))), ...(self::UNPREFIXED_OPTIONS[$type] ?? [])];
				$this->assertEqualsCanonicalizing($native, ColumnOptions::TYPE_FIELDS[$type], "$version: options of $type");
				$classified = [...$classified, ...$native];
			}
			$this->assertSame([], array_values(array_diff($fields, $classified)), "$version: serialized column fields that are neither general nor an option");
			$relation = in_array('relation', $types, true) ? $behaviour['relationSettingKeys'] : [];
			$this->assertEqualsCanonicalizing($relation, in_array('relation', $types, true) ? ColumnOptions::CUSTOM_SETTINGS['relation'] : [], "$version: relation keys of customSettings");
			$this->assertSame([], array_values(array_diff($behaviour['validatedCustomSettingKeys'], array_keys(self::REVIEWED_DISPLAY_SETTINGS))), "$version: customSettings keys not reviewed");
		}
		$this->assertSame(['relation'], array_keys(ColumnOptions::CUSTOM_SETTINGS), 'only relation columns keep keys of customSettings');
	}

	/**
	 * 0.9 stays unsupported: its RowService writes the read-only columns of a view, every supported release skips them.
	 *
	 * @return void
	 */
	public function testOnlyTheSupportedReleasesSkipReadOnlyColumns(): void {
		$this->assertFalse(TablesBehavior::of('0.9.12')['rowServiceSkipsReadOnlyColumns']);
		$this->assertNotContains('0.9.12', array_keys(self::supported()));
		foreach (self::supported() as $version => $behaviour) {
			$this->assertTrue($behaviour['rowServiceSkipsReadOnlyColumns'], $version);
		}
	}

	/**
	 * The facts the row slices will rely on: only 2.3 orders rows by id last and has `contains-item` and `is-not-empty`;
	 * RowService::create() takes the user from 2.2 on.
	 *
	 * @return void
	 */
	public function testTheRowFactsArePinned(): void {
		foreach (TablesBehavior::versions() as $version => $behaviour) {
			$newest = version_compare($version, '2.3.0', '>=');
			$this->assertSame($newest, $behaviour['rowMapperOrdersByIdLast'], $version);
			$this->assertSame($newest, in_array('contains-item', $behaviour['rowFilterOperators'], true), $version);
			$this->assertSame($newest, in_array('is-not-empty', $behaviour['rowFilterOperators'], true), $version);
			$this->assertSame(version_compare($version, '2.2.0', '>=') ? ['tableId', 'viewId', 'data', 'userId'] : ['tableId', 'viewId', 'data'], $behaviour['rowServiceCreateParameters'], $version);
		}
	}
}
