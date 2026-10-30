<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Writes tables-behavior.json: what each compared Tables release does that a signature does not say, read from the
 * sources of the official tarballs (checked by SHA256, only read as text, never executed):
 * - the Nextcloud range the release declares;
 * - TableService::findAll(): its parameters, the default of `createTutorial` and whether the tutorial table is created
 *   only for a user without tables when it is true;
 * - the keys View::jsonSerialize() and Column::jsonSerialize() return, and the keys of a view's column settings;
 * - the column types, the relation keys of a column's `customSettings` (Column::RELATION_*) and the keys of
 *   `customSettings` ColumnService validates;
 * - the keys of a context node and the node type constants;
 * - the placeholders ColumnsHelper::resolveSearchValue() resolves (case names and prefixes);
 * - the filter operators of Row2Mapper, whether it orders by row id last, whether RowService skips the read-only
 *   columns of a view on a write, and the parameters of RowService::create().
 *
 * Usage: php tests/fixtures/nextcloud-api/tables/generate-tables-behavior.php <archive directory with tables-<version>.tar.gz>
 */

/** The compared releases: the pins of tables-sources.json, the newest of each line and the last 0.9 one. */
const RELEASES = [
	'0.9.12' => '4a775e18a5ac6683f1e2e2748335ae293d7eea70b6d88c54066caab15655efeb',
	'1.0.0' => 'd03bf0e4147985f556fdb999e2c0fa45ac0bdb98c7bfd6e56bfabb6db8754604',
	'1.0.10' => '6a8e8294b8467c9c36a724d5d6f6d62b9b97b285b8de291712812958dfa1b727',
	'2.0.0' => '75003cd8abde806c5ab56432dd2aba009b3f84c9a40ab3429eb0bd1486665dc9',
	'2.2.2' => 'c2806c1c40b58efdfd1b1f2e2d6f37a19fb0ee34bcf9ee7f26cecb170233ac4f',
	'2.3.2' => '29953270010587fc8c0a95cfaba7b167adafb93d2f1a0a5a89e42f2d2be1cf28',
];

[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	fwrite(STDERR, "usage: php generate-tables-behavior.php <verified archive directory>\n");
	exit(2);
}

/**
 * The text of a file of the app.
 *
 * @param string $root app root
 * @param string $path path inside the app
 * @return string the text
 */
function source(string $root, string $path): string {
	$text = @file_get_contents($root . '/' . $path);
	if ($text === false) {
		throw new RuntimeException('Missing ' . $path);
	}
	return $text;
}

/**
 * The body of a method, from its signature to its closing brace.
 *
 * @param string $text class source
 * @param string $signature regular expression of the signature up to the opening brace
 * @return string the body
 */
function body(string $text, string $signature): string {
	if (preg_match('~' . $signature . '~', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
		throw new RuntimeException('Missing ' . $signature);
	}
	$start = (int)$match[0][1] + strlen($match[0][0]);
	$depth = 1;
	for ($i = $start; $i < strlen($text) && $depth > 0; $i++) {
		$depth += $text[$i] === '{' ? 1 : ($text[$i] === '}' ? -1 : 0);
	}
	return substr($text, $start, $i - $start);
}

/**
 * The parameters of a method: name, and the default when it has one.
 *
 * @param string $text class source
 * @param string $method method name
 * @return list<array{name:string, default?:string}> the parameters
 */
function parameters(string $text, string $method): array {
	if (preg_match('~public function ' . $method . '\(([^)]*)\)~', $text, $match) !== 1) {
		throw new RuntimeException('Missing ' . $method);
	}
	$parameters = [];
	foreach (array_filter(array_map('trim', explode(',', $match[1]))) as $parameter) {
		preg_match('~\$(\w+)(?:\s*=\s*(.+))?$~', $parameter, $parts);
		$parameters[] = ['name' => $parts[1]] + (isset($parts[2]) ? ['default' => trim($parts[2])] : []);
	}
	return $parameters;
}

/**
 * The string keys of the array literals in a piece of code, in order, once each.
 *
 * @param string $code the code
 * @return list<string> the keys
 */
function keys(string $code): array {
	preg_match_all("~^\s*'(\w+)'\s*=>~m", $code, $matches);
	return array_values(array_unique($matches[1]));
}

/**
 * The integer value of a class constant.
 *
 * @param string $text class source
 * @param string $name constant name
 * @return int the value
 */
function constant_value(string $text, string $name): int {
	if (preg_match('~const ' . $name . '\s*=\s*(\d+);~', $text, $match) !== 1) {
		throw new RuntimeException('Missing ' . $name);
	}
	return (int)$match[1];
}

/**
 * The column types of a release: the cases of ColumnType (1.0.10 on), or the TYPE_ string constants of Column before.
 *
 * @param string $root app root
 * @return list<string> the types
 */
function column_types(string $root): array {
	$enum = $root . '/lib/Constants/ColumnType.php';
	if (is_file($enum)) {
		preg_match_all("~case \\w+ = '([a-z]+)';~", source($root, 'lib/Constants/ColumnType.php'), $matches);
	} else {
		preg_match_all("~const TYPE_[A-Z]+ = '([a-z]+)';~", source($root, 'lib/Db/Column.php'), $matches);
	}
	$types = array_values(array_unique($matches[1]));
	sort($types);
	return $types;
}

/**
 * The keys of `customSettings` ColumnService validates, when it does.
 *
 * @param string $columnService ColumnService source
 * @return list<string> the keys
 */
function validated_custom_settings(string $columnService): array {
	if (!str_contains($columnService, 'function validateCustomSettings(')) {
		return [];
	}
	preg_match_all("~\\\$settings\\['(\\w+)'\\]~", body($columnService, 'private function validateCustomSettings\\(\\?string \\$customSettings\\): void \\{'), $matches);
	return array_values(array_unique($matches[1]));
}

$versions = [];
$sources = [];
foreach (RELEASES as $version => $sha256) {
	$archive = $archives . '/tables-' . $version . '.tar.gz';
	if (!is_file($archive) || hash_file('sha256', $archive) !== $sha256) {
		throw new RuntimeException('Source SHA256 mismatch: ' . $archive);
	}
	$tree = sys_get_temp_dir() . '/mcp-tables-behavior-' . bin2hex(random_bytes(6));
	mkdir($tree, recursive: true);
	try {
		(new PharData($archive))->extractTo($tree);
		$roots = glob($tree . '/*', GLOB_ONLYDIR) ?: [];
		if (count($roots) !== 1) {
			throw new RuntimeException('Expected one official app root');
		}
		$root = $roots[0];
		$info = simplexml_load_string(source($root, 'appinfo/info.xml'));
		if ((string)$info->version !== $version) {
			throw new RuntimeException('Source app version mismatch: ' . $info->version);
		}
		$tableService = source($root, 'lib/Service/TableService.php');
		$findAll = parameters($tableService, 'findAll');
		$tutorial = array_values(array_filter($findAll, static fn (array $p): bool => $p['name'] === 'createTutorial'))[0] ?? [];
		$viewColumnInformation = is_file($root . '/lib/Service/ValueObject/ViewColumnInformation.php')
			? source($root, 'lib/Service/ValueObject/ViewColumnInformation.php') : '';
		preg_match_all("~const KEY_\w+\s*=\s*'(\w+)';~", $viewColumnInformation, $settingKeys);
		$contextMapper = source($root, 'lib/Db/ContextMapper.php');
		$nodes = substr($contextMapper, (int)strpos($contextMapper, "\$formatted['nodes']"));
		$nodes = substr($nodes, 0, (int)strpos($nodes, "\$formatted['pages']"));
		$application = source($root, 'lib/AppInfo/Application.php');
		$rowMapper = source($root, 'lib/Db/Row2Mapper.php');
		preg_match_all("~case '([a-z-]+)'~", $rowMapper, $operators);
		$operators = array_values(array_unique($operators[1]));
		sort($operators);
		$rowService = source($root, 'lib/Service/RowService.php');
		$column = source($root, 'lib/Db/Column.php');
		$resolver = body(source($root, 'lib/Helper/ColumnsHelper.php'), 'public function resolveSearchValue\\([^)]*\\): string(\\|array)? \\{');
		preg_match_all("~case '([a-z0-9-]+)'~", $resolver, $placeholderNames);
		preg_match_all("~str_starts_with\\(\\\$placeholder, '(@[a-z-]+)'\\)~", $resolver, $placeholderPrefixes);
		preg_match_all("~const RELATION_\\w+ = '(\\w+)';~", $column, $relationKeys);
		$versions[$version] = [
			'nextcloud' => ['min' => (int)$info->dependencies->nextcloud['min-version'], 'max' => (int)$info->dependencies->nextcloud['max-version']],
			'tableServiceFindAll' => [
				'parameters' => $findAll,
				'createTutorialDefault' => $tutorial['default'] ?? null,
				'tutorialOnlyForAUserWithoutTables' => preg_match('~if \(count\(\$allTables\) === 0 && \$createTutorial\)~', $tableService) === 1,
			],
			'viewSerializedKeys' => keys(body(source($root, 'lib/Db/View.php'), 'public function jsonSerialize\(\): array \{')),
			'columnSerializedKeys' => keys(body($column, 'public function jsonSerialize\(\): array \{')),
			'columnTypes' => column_types($root),
			'relationSettingKeys' => $relationKeys[1],
			'validatedCustomSettingKeys' => validated_custom_settings(source($root, 'lib/Service/ColumnService.php')),
			'viewColumnSettingKeys' => $settingKeys[1],
			'contextNodeKeys' => keys($nodes),
			'nodeTypes' => ['table' => constant_value($application, 'NODE_TYPE_TABLE'), 'view' => constant_value($application, 'NODE_TYPE_VIEW')],
			'rowFilterOperators' => $operators,
			'searchPlaceholders' => ['names' => $placeholderNames[1], 'prefixes' => $placeholderPrefixes[1]],
			'rowMapperOrdersByIdLast' => str_contains($rowMapper, "\$qb->addOrderBy('sleeves.id', 'ASC');"),
			'rowServiceSkipsReadOnlyColumns' => preg_match("~if \(!empty\(\\\$readOnlyColumns\[\\\$entry\['columnId'\]\]\)\) \{\s*continue;~", $rowService) === 1,
			'rowServiceCreateParameters' => array_column(parameters($rowService, 'create'), 'name'),
		];
		$sources[$version] = ['url' => "https://github.com/nextcloud-releases/tables/releases/download/v$version/tables-v$version.tar.gz", 'sha256' => $sha256];
	} finally {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $entry) {
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($tree);
	}
}
file_put_contents(__DIR__ . '/tables-behavior.json', json_encode(['sources' => $sources, 'versions' => $versions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Generated tables-behavior.json from ' . implode(', ', array_keys(RELEASES)) . "\n";
