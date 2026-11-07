<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Writing\CellValueDomain;
use OCA\Mcp\Modules\Tables\Writing\UsergroupWhitelist;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\LibScanner;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables\TablesApiUsage;
use PHPUnit\Framework\TestCase;

/** Binds the additional release to complete official-source surfaces without changing minimum-version contracts. */
final class Tables240EvidenceTest extends TestCase {
	/** @return void Every consumed class and positional read/row call is present in the SourceParser output. */
	public function testConsumedReadAndRowSurfacesAreComplete(): void {
		$data = self::fixture();
		self::assertSame('47847b64ee65a58afd2a8ac3f943c53128a927a1b321ed94c4a322daa6b5eb99', $data['archiveSha256']);
		foreach (array_keys((new LibScanner(dirname(__DIR__, 4) . '/lib'))->classes()) as $class) {
			if (str_starts_with($class, 'OCA\\Tables\\')) self::assertNotEmpty($data['surfaces'][$class] ?? null, $class);
		}
		foreach (TablesApiUsage::POSITIONAL as $call => $arguments) {
			[$class, $method] = explode('::', $call);
			$surface = $data['surfaces'][$class];
			if (!isset($surface['methods'][$method]) && str_starts_with($method, 'get')) {
				self::assertSame([], $arguments, $call);
				self::assertContains(lcfirst(substr($method, 3)), $surface['fields'], $call . ': native Entity getter');
				continue;
			}
			$native = $surface['methods'][$method] ?? throw new \RuntimeException('Missing native method: ' . $call);
			self::assertSame($arguments, array_map(static fn (string $name): string => rtrim($name, '?'), array_slice($native, 0, count($arguments))), $call);
			foreach (array_slice($native, count($arguments)) as $parameter) self::assertStringEndsWith('?', $parameter, $call);
		}
	}

	/** @return void All row parsers, validation/enhancement and cell persistence are byte-identical to the proven pin. */
	public function testRowFactsAreBoundToIdenticalSourceFiles(): void {
		$data = self::fixture();
		$previous = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/tables/tables-write.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($previous['sha256']['2.3.2'], $data['rowWrite']['referenceArchiveSha256']);
		self::assertSame($previous['versions']['2.3.2'], $data['rowWrite']['facts']);
		self::assertCount(22, $data['identicalRowSources']);
		foreach ($data['identicalRowSources'] as $file => $hash) {
			self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $hash, $file);
			if (str_starts_with($file, 'Service/ColumnTypes/')) {
				$class = 'OCA\\Tables\\' . str_replace('/', '\\', substr($file, 0, -4));
				self::assertNotEmpty($data['surfaces'][$class]);
			}
		}
		$domain = new CellValueDomain($this->createMock(UsergroupWhitelist::class));
		foreach ([
			[['type' => 'text', 'subtype' => 'rich'], 'Text', 'Text'],
			[['type' => 'number', 'subtype' => ''], 12, 12.0],
			[['type' => 'number', 'subtype' => ''], null, null],
			[['type' => 'number', 'subtype' => 'stars'], 2, 2],
			[['type' => 'number', 'subtype' => 'progress'], 20, 20],
			[['type' => 'selection', 'subtype' => '', 'selectionOptions' => [['id' => 2, 'label' => 'Closed']]], 2, '2'],
			[['type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => [['id' => 1], ['id' => 10]]], [10, 1], [1, 10]],
			[['type' => 'selection', 'subtype' => 'check'], false, 'false'],
			[['type' => 'datetime', 'subtype' => 'date'], '2026-11-07', '2026-11-07'],
			[['type' => 'usergroup', 'subtype' => ''], null, []],
			[['type' => 'relation', 'subtype' => ''], 2, 2],
		] as [$column, $input, $effect]) self::assertSame($effect, $domain->cell($column, $input, '2.4.0', 'actor')['effect']);
	}

	/** @return void Factory bytes and newly added DTO parameters come from the official release, never doubles. */
	public function testSchemaFactoriesAndNewViewMembersAreBound(): void {
		$data = self::fixture();
		$manifest = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/tables/schema-api.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($data['archiveSha256'], $manifest['sources']['2.4.0']['archiveSha256']);
		foreach ($manifest['factorySources']['2.4.0'] as $file => $source) self::assertSame($manifest['sources']['2.4.0']['files'][$file], hash('sha256', $source));
		self::assertSame(['layout?', 'viewSettings?'], array_slice($data['surfaces']['OCA\\Tables\\Model\\ViewUpdateInput']['methods']['__construct'], -2));
		self::assertSame('layout?', array_slice($data['surfaces']['OCA\\Tables\\Service\\ViewService']['methods']['create'], -1)[0]);
	}

	/** @return array<string, mixed> Additional SHA-pinned official SourceParser fixture. */
	private static function fixture(): array {
		return json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/tables/tables-2.4.0.json'), true, 512, JSON_THROW_ON_ERROR);
	}
}
