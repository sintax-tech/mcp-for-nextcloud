<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Writes tables-write.json: what each supported Tables release does with a written row that the row write tools rely on,
 * read from the sources of the official tarballs (checked by SHA256, only read as text, never executed):
 * - the parsers RowService::parseValueByColumnType() calls (yes/no, stars, progress, single and multiple selection,
 *   users and groups, number, link, relation): what they make of null and of a valid value;
 * - RowService::create(): validation before RowService::enhanceWithViewDefaults(), which adds the hidden values with the
 *   two-argument ColumnsHelper::resolveSearchValue(), first match per column, without the parser;
 * - the cell mappers every value reaches (number, selection, text): whether the number mapper keeps a missing value empty;
 * - whether RowService applies the column's numberMin/numberMax to every numeric value, stars and progress included;
 * - Row2Mapper::insert()/update() without a transaction, and the events of updateSet() after the write;
 * - the relation parser and validator reading the target before anything else, null included.
 *
 * Usage: php tests/fixtures/nextcloud-api/tables/generate-tables-write.php <archive directory with tables-<version>.tar.gz>
 */

/** The supported releases: the pins of tables-sources.json and the newest of each line. */
const RELEASES = [
	'1.0.0' => 'd03bf0e4147985f556fdb999e2c0fa45ac0bdb98c7bfd6e56bfabb6db8754604',
	'1.0.10' => '6a8e8294b8467c9c36a724d5d6f6d62b9b97b285b8de291712812958dfa1b727',
	'2.0.0' => '75003cd8abde806c5ab56432dd2aba009b3f84c9a40ab3429eb0bd1486665dc9',
	'2.2.2' => 'c2806c1c40b58efdfd1b1f2e2d6f37a19fb0ee34bcf9ee7f26cecb170233ac4f',
	'2.3.2' => '29953270010587fc8c0a95cfaba7b167adafb93d2f1a0a5a89e42f2d2be1cf28',
];

[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	fwrite(STDERR, "usage: php generate-tables-write.php <verified archive directory>\n");
	exit(2);
}

/**
 * The text of a file of the app, '' when the release does not have it.
 *
 * @param string $root app root
 * @param string $path path inside the app
 * @return string the text
 */
function write_source(string $root, string $path): string {
	return is_file($root . '/' . $path) ? (string)file_get_contents($root . '/' . $path) : '';
}

/**
 * The body of a method with whitespace collapsed, '' when the class does not declare it.
 *
 * @param string $text class source
 * @param string $method method name
 * @return string the body
 */
function write_body(string $text, string $method): string {
	if (preg_match('~function ' . $method . '\([^{]*\{~', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
		return '';
	}
	$start = (int)$match[0][1] + strlen($match[0][0]);
	$depth = 1;
	for ($i = $start; $i < strlen($text) && $depth > 0; $i++) {
		$depth += $text[$i] === '{' ? 1 : ($text[$i] === '}' ? -1 : 0);
	}
	$body = (string)preg_replace('~//[^\n]*~', '', substr($text, $start, $i - $start - 1));
	return trim((string)preg_replace('~\s+~', ' ', $body));
}

/**
 * Whether a body holds every fragment, in this order.
 *
 * @param string $body collapsed body
 * @param list<string> $fragments literal fragments
 * @return bool true when they all appear in order
 */
function write_in_order(string $body, array $fragments): bool {
	$offset = 0;
	foreach ($fragments as $fragment) {
		$position = strpos($body, $fragment, $offset);
		if ($position === false) {
			return false;
		}
		$offset = $position + strlen($fragment);
	}
	return true;
}

$versions = [];
foreach (RELEASES as $version => $sha256) {
	$archive = $archives . '/tables-' . $version . '.tar.gz';
	if (!is_file($archive) || hash_file('sha256', $archive) !== $sha256) {
		throw new RuntimeException('Source SHA256 mismatch: ' . $archive);
	}
	$tree = sys_get_temp_dir() . '/mcp-tables-write-' . bin2hex(random_bytes(6));
	mkdir($tree, recursive: true);
	try {
		(new PharData($archive))->extractTo($tree);
		$roots = glob($tree . '/*', GLOB_ONLYDIR) ?: [];
		if (count($roots) !== 1) {
			throw new RuntimeException('Expected one official app root');
		}
		$root = $roots[0];
		$info = simplexml_load_string(write_source($root, 'appinfo/info.xml'));
		if ((string)$info->version !== $version) {
			throw new RuntimeException('Source app version mismatch: ' . $info->version);
		}
		$type = static fn (string $class): string => write_source($root, 'lib/Service/ColumnTypes/' . $class . '.php');
		$check = $type('SelectionCheckBusiness');
		$stars = $type('NumberStarsBusiness');
		$progress = $type('NumberProgressBusiness');
		$single = $type('SelectionBusiness');
		$multi = $type('SelectionMultiBusiness');
		$usergroup = $type('UsergroupBusiness');
		$number = $type('NumberBusiness');
		$link = $type('TextLinkBusiness');
		$relation = $type('RelationBusiness');
		$rowService = write_source($root, 'lib/Service/RowService.php');
		$rowMapper = write_source($root, 'lib/Db/Row2Mapper.php');
		$create = write_body($rowService, 'create');
		$enhancer = write_body($rowService, 'enhanceWithViewDefaults');
		$updateSet = write_body($rowService, 'updateSet');
		$linkParse = write_body($link, 'parseValue');
		$versions[$version] = [
			'checkParse' => [
				'positiveOnly' => str_contains(write_body($check, 'parseValue'), 'in_array($value, self::PATTERN_POSITIVE, true)'),
				'givesTrueOrFalse' => str_contains(write_body($check, 'parseValue'), "json_encode(\$found ? 'true' : 'false')"),
				'acceptsNull' => str_contains(write_body($check, 'canBeParsed'), 'if ($value === null) { return true; }'),
			],
			'starsParseCastsToInt' => str_contains(write_body($stars, 'parseValue'), 'json_encode((int)$value)')
				&& str_starts_with(write_body($stars, 'canBeParsed'), 'return !$value ||'),
			'progressParseCastsToInt' => str_contains(write_body($progress, 'parseValue'), 'json_encode((int)$value)')
				&& str_starts_with(write_body($progress, 'canBeParsed'), 'return !$value ||'),
			'singleParse' => str_contains(write_body($single, 'parseValue'), 'json_encode((string)$option->key())')
				|| str_contains(write_body($single, 'parseValue'), "json_encode((string)\$option['id'])") ? 'string'
				: (str_contains(write_body($single, 'parseValue'), "json_encode(\$option['id'])") ? 'integer' : 'unknown'),
			'multiParse' => [
				'nullIsEmpty' => str_contains(write_body($multi, 'parseValue'), 'if ($value === null) { return json_encode([]); }'),
				'sorts' => str_contains(write_body($multi, 'parseValue'), 'sort($result, SORT_NUMERIC);'),
			],
			'usergroupParseNullIsEmpty' => str_contains(write_body($usergroup, 'parseValue'), 'if ($value === null) { return json_encode([]); }'),
			'usergroupValidatesTeamsForTheCaller' => str_contains(write_body($usergroup, 'validateValue'), 'circleExists($userGroupEntry[\'id\'], $userId)'),
			'numberParseNullIsEmpty' => str_contains(write_body($number, 'parseValue'), "if (\$value === null) { return ''; }")
				|| str_contains(write_body($number, 'parseValue'), "if (\$value === null || \$value === '') { return ''; }"),
			'linkParse' => [
				'titlePatternBeforeJson' => write_in_order($linkParse, ["preg_match('/(.*) \\((http.*)\\)/'", 'json_decode(']),
				'keepsCompleteJson' => str_contains($linkParse, "if (isset(\$data['title']) && isset(\$data['value'])) { return json_encode(\$value); }"),
				'checksProviders' => str_contains(write_body($link, 'canBeParsed'), 'allowedProviders'),
			],
			'relationReadsTargetFirst' => $relation === '' ? null : [
				'parse' => str_contains(write_body($relation, 'parseValue'), '$relationData = $this->relationService->getRelationData($column);'),
				'validateSkipsNull' => str_starts_with(write_body($relation, 'validateValue'), "if (\$value === null || \$value === '') { return; }"),
			],
			'create' => [
				'validatesBeforeEnhancing' => write_in_order($create, ['cleanupAndValidateData(', 'enhanceWithViewDefaults(', 'row2Mapper->insert(']),
				'eventsAfterInsert' => write_in_order($create, ['row2Mapper->insert(', 'dispatchTyped(new RowAddedEvent(']),
			],
			'enhancer' => [
				'operators' => str_contains($enhancer, "if (!in_array(\$filter['operator'], ['is-equal', 'is-not-equal'])) { continue; }"),
				'skipsVisible' => str_contains($enhancer, "if (in_array(\$filter['columnId'], \$view->getColumnIds())) { continue; }"),
				'firstMatchWins' => write_in_order($enhancer, ["if (!\$data->hasColumn(\$filter['columnId'])) {", "\$data->add(\$filter['columnId'], \$this->columnsHelper->resolveSearchValue((string)\$filter['value'], \$this->userId));"]),
			],
			'updateSet' => [
				'readsAndUpdates' => write_in_order($updateSet, ['canReadRowsByElementId(', 'canUpdateRowsBy']),
				'eventAfterUpdate' => write_in_order($updateSet, ['row2Mapper->update(', 'dispatchTyped(new RowUpdatedEvent(']),
			],
			'insertWithoutTransaction' => write_body($rowMapper, 'insert') !== '' && !str_contains(write_body($rowMapper, 'insert'), 'beginTransaction'),
			'updateWithoutTransaction' => write_body($rowMapper, 'update') !== '' && !str_contains(write_body($rowMapper, 'update'), 'beginTransaction'),
			'numberLimits' => [
				'applied' => str_contains(write_body($rowService, 'validateColumnValueLimits'), '$numberMin = $column->getNumberMin();'),
				'everySubtype' => write_body($rowService, 'validateColumnValueLimits') === '' ? null
					: !str_contains(write_body($rowService, 'validateColumnValueLimits'), 'getSubtype()'),
			],
			'mappers' => [
				'numberFloats' => str_contains(write_body(write_source($root, 'lib/Db/RowCellNumberMapper.php'), 'applyDataToEntity'), '$cell->setValueWrapper((float)$data);'),
				'numberNullStaysEmpty' => str_contains(write_body(write_source($root, 'lib/Db/RowCellNumberMapper.php'), 'applyDataToEntity'),
					'if (!is_numeric($data)) { $cell->setValueWrapper(null); return; }'),
				'singleKeepsRaw' => str_contains(write_body(write_source($root, 'lib/Db/RowCellSelectionMapper.php'), 'valueToJsonDbValue'), "if (\$column->getSubtype() === '' || \$column->getSubtype() === null) { return \$value ?? ''; }"),
			],
		];
	} finally {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $entry) {
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($tree);
	}
}
file_put_contents(__DIR__ . '/tables-write.json', json_encode(['sha256' => RELEASES, 'versions' => $versions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Generated tables-write.json from ' . implode(', ', array_keys(RELEASES)) . "\n";
