<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables;

/**
 * What the Tables module uses of the Nextcloud Tables app that a scanner of lib/ cannot see, merged into
 * {@see \OCA\Mcp\Tests\Unit\Contract\NextcloudApi\NextcloudApiUsage}: OCA\Mcp\Modules\Tables\Integration\TablesServices resolves the
 * services by class name and the module holds them, and the entities they return, untyped.
 */
final class TablesApiUsage {
	/** The entities the Tables module receives from the services and reads. */
	public const EXTRA_CLASSES = [
		'OCA\\Tables\\Db\\Table',
		'OCA\\Tables\\Db\\View',
		'OCA\\Tables\\Db\\Column',
		'OCA\\Tables\\Db\\Context',
		// The rows the row reader receives from Row2Mapper::findAll().
		'OCA\\Tables\\Db\\Row2',
		// The view settings of each column (View::getColumnsSettingsArray()), read by the row writes.
		'OCA\\Tables\\Service\\ValueObject\\ViewColumnInformation',
	];

	/**
	 * Calls with the parameters the module fills, in order; every call passes the user explicitly.
	 *
	 * @var array<string, list<string>>
	 */
	public const POSITIONAL = [
		// `createTutorial = false`: the default creates a tutorial table for a user without any.
		'OCA\\Tables\\Service\\TableService::findAll' => ['userId', 'skipTableEnhancement', 'skipSharedTables', 'createTutorial'],
		'OCA\\Tables\\Service\\ViewService::findAll' => ['table', 'userId'],
		'OCA\\Tables\\Service\\ViewService::find' => ['id', 'skipEnhancement', 'userId'],
		'OCA\\Tables\\Service\\ViewService::findSharedViewsWithMe' => ['userId'],
		'OCA\\Tables\\Service\\ColumnService::findAllByTable' => ['tableId', 'userId'],
		'OCA\\Tables\\Service\\ColumnService::findAllByView' => ['viewId', 'userId'],
		'OCA\\Tables\\Service\\PermissionsService::canReadColumnsByTableId' => ['tableId', 'userId'],
		'OCA\\Tables\\Service\\PermissionsService::canManageTable' => ['table', 'userId'],
		'OCA\\Tables\\Service\\PermissionsService::canAccessView' => ['view', 'userId'],
		'OCA\\Tables\\Service\\ContextService::findAll' => ['userId'],
		'OCA\\Tables\\Db\\Table::getId' => [],
		'OCA\\Tables\\Db\\Table::getTitle' => [],
		'OCA\\Tables\\Db\\Table::getEmoji' => [],
		'OCA\\Tables\\Db\\Table::getDescription' => [],
		'OCA\\Tables\\Db\\Table::getArchived' => [],
		'OCA\\Tables\\Db\\Table::getOwnership' => [],
		'OCA\\Tables\\Db\\View::getId' => [],
		'OCA\\Tables\\Db\\View::getTableId' => [],
		'OCA\\Tables\\Db\\View::getTitle' => [],
		'OCA\\Tables\\Db\\View::getEmoji' => [],
		'OCA\\Tables\\Db\\View::getDescription' => [],
		'OCA\\Tables\\Db\\View::getColumnIds' => [],
		'OCA\\Tables\\Db\\Context::getNodes' => [],
		'OCA\\Tables\\Db\\Column::jsonSerialize' => [],
		// Rows (OCA\Mcp\Modules\Tables\Reading\RowReader): the native row permission, the saved rules, the column of a rule,
		// the engine RowService reads rows with, and the row it returns.
		'OCA\\Tables\\Service\\PermissionsService::canReadRowsByElementId' => ['elementId', 'nodeType', 'userId'],
		'OCA\\Tables\\Service\\TableService::find' => ['id', 'skipTableEnhancement', 'userId'],
		'OCA\\Tables\\Db\\View::getFilter' => [],
		'OCA\\Tables\\Db\\View::getSort' => [],
		'OCA\\Tables\\Db\\View::getFilterArray' => [],
		'OCA\\Tables\\Db\\View::getSortArray' => [],
		'OCA\\Tables\\Db\\ColumnMapper::find' => ['id'],
		'OCA\\Tables\\Db\\Row2Mapper::findAll' => ['showColumnIds', 'tableId', 'limit', 'offset', 'filter', 'sort', 'userId'],
		'OCA\\Tables\\Db\\Row2::jsonSerialize' => [],
		// Row writes (OCA\Mcp\Tools\Tables\Write): the native write permissions, the metadata RowService::create() reads
		// under them, the values a view's saved rules add, and the two writing calls, in the form the controllers use.
		'OCA\\Tables\\Service\\PermissionsService::canCreateRows' => ['element', 'nodeType', 'userId'],
		'OCA\\Tables\\Service\\PermissionsService::canUpdateRowsByViewId' => ['viewId', 'userId'],
		'OCA\\Tables\\Service\\PermissionsService::canUpdateRowsByTableId' => ['tableId', 'userId'],
		'OCA\\Tables\\Db\\ViewMapper::find' => ['id'],
		'OCA\\Tables\\Db\\TableMapper::find' => ['id'],
		'OCA\\Tables\\Db\\ColumnMapper::findAll' => ['id'],
		'OCA\\Tables\\Db\\ColumnMapper::findAllByTable' => ['tableId'],
		'OCA\\Tables\\Db\\View::getColumnsSettingsArray' => [],
		'OCA\\Tables\\Service\\ValueObject\\ViewColumnInformation::jsonSerialize' => [],
		'OCA\\Tables\\Helper\\ColumnsHelper::resolveSearchValue' => ['placeholder', 'userId'],
		'OCA\\Tables\\Service\\RowService::create' => ['tableId', 'viewId', 'data'],
		'OCA\\Tables\\Service\\RowService::updateSet' => ['id', 'viewId', 'data', 'userId', 'tableId'],
	];

	/**
	 * Parameters after the ones {@see self::POSITIONAL} passes, each left to its default, with the reason.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const REVIEWED_TRAILING = [
		'OCA\\Tables\\Service\\ColumnService::findAllByTable' => [
			'table' => 'null (2.2+): the service loads the table itself, after its own permission check',
		],
		'OCA\\Tables\\Service\\RowService::create' => [
			'userId' => 'null (2.2.2+): the controllers never pass it; the session user, checked by TablesSessionGuard, is the caller',
		],
		'OCA\\Tables\\Helper\\ColumnsHelper::resolveSearchValue' => [
			'column' => 'null: RowService::enhanceWithViewDefaults() calls it with two arguments, and the check repeats that call',
		],
	];

	/**
	 * Tables APIs a covered release lacks, with the fallback and the class that chooses it.
	 *
	 * @var array<string, array{missing: list<string>, fallback: string, by: string}>
	 */
	public const VERSION_GATED = [
		// The saved sort of a table exists from 2.2.2 on (NC35 pin); before, RowReader reads the rows of a table with no
		// saved sort, as RowService::findAllByTable() does there. Kept out of POSITIONAL, which has no member gate.
		'OCA\\Tables\\Db\\Table::getSort' => [
			'missing' => ['31', '32', '33', '34'],
			'fallback' => 'OCA\\Tables\\Db\\Row2Mapper::findAll',
			'by' => \OCA\Mcp\Modules\Tables\Reading\RowReader::class,
			'elsewhere' => [
				'Tools/Tables/Write/RelationWriteGate.php' => 'OCA\\Tables\\Db\\View::getSort',
				'Tools/Tables/Write/RowWriteState.php' => 'OCA\\Tables\\Db\\View::getSort',
				'Tools/Tables/Write/ViewDefaultsGate.php' => 'OCA\\Tables\\Db\\View::getSort',
			],
		],
		'OCA\\Tables\\Db\\Table::getSortArray' => [
			'missing' => ['31', '32', '33', '34'],
			'fallback' => 'OCA\\Tables\\Db\\Row2Mapper::findAll',
			'by' => \OCA\Mcp\Modules\Tables\Reading\RowReader::class,
			'elsewhere' => [
				'Tools/Tables/Write/RelationWriteGate.php' => 'OCA\\Tables\\Db\\View::getSortArray',
				'Tools/Tables/Write/ViewDefaultsGate.php' => 'OCA\\Tables\\Db\\View::getSortArray',
			],
		],
		// Federated tables and views exist from Tables 2.3, after every pin; before, the node TableMapper::find() or
		// ViewMapper::find() returns is the local one, and RowWriteColumns asks nothing.
		'OCA\\Tables\\Db\\Table::isFederated' => [
			'missing' => ['31', '32', '33', '34', '35'],
			'fallback' => 'OCA\\Tables\\Db\\TableMapper::find',
			'by' => \OCA\Mcp\Modules\Tables\Writing\RowWriteColumns::class,
		],
		'OCA\\Tables\\Db\\View::isFederated' => [
			'missing' => ['31', '32', '33', '34', '35'],
			'fallback' => 'OCA\\Tables\\Db\\ViewMapper::find',
			'by' => \OCA\Mcp\Modules\Tables\Writing\RowWriteColumns::class,
		],
	];

	/**
	 * Method names the scanner sees on another object.
	 *
	 * @var array<string, string>
	 */
	public const NAME_CLASHES = [
		'OCA\\Tables\\Db\\Row2Mapper::setUserId' => 'OCA\\Deck\\Service\\BoardService::setUserId, in Tools/Deck/DeckServiceContext',
	];

	/** Constants the module reads by name. */
	public const CONSTANTS = [
		'OCA\\Tables\\AppInfo\\Application' => ['NODE_TYPE_VIEW'],
	];

	/**
	 * Constructor of a class with constants only.
	 *
	 * @return void
	 */
	private function __construct() {
	}
}
