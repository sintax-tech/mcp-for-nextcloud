<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Projects the complete new pin's SourceParser parameter surfaces onto the proven service-call inventory. */
final class TablesPinSignatures {
	/** @return void Persist exact parameter counts/types for the independent 2.4.0 runtime profile. */
	public static function run(): void {
		$fixture = json_decode(file_get_contents(__DIR__ . '/tables-2.4.0.json'), true, 512, JSON_THROW_ON_ERROR);
		$file = __DIR__ . '/../../../../appinfo/tables-schema-api-signatures.json';
		$data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		$data['2.4.0'] = [];
		foreach ($data['2.3.2'] as [$class, $method, $oldParameters, $return]) {
			$surface = $fixture['surfaces'][$class];
			$parameters = [];
			foreach ($surface['methods'][$method] as $i => $name) {
				$parameters[] = ['name' => rtrim($name, '?'), 'type' => $surface['types'][$method][$i], 'optional' => str_ends_with($name, '?')];
			}
			// Return declarations of every consumed method are unchanged; the full pinned source contracts bind them.
			$data['2.4.0'][] = [$class, $method, $parameters, $return];
		}
		foreach ([
			'OCA\\Tables\\Db\\View' => ['getLayoutNormalized' => 'string', 'getViewSettingsObject' => 'OCA\\Tables\\Model\\ViewSettings'],
			'OCA\\Tables\\Model\\ViewSettings' => ['__construct' => '', 'createFromStoredArray' => 'OCA\\Tables\\Model\\ViewSettings', 'jsonSerialize' => 'array'],
			'OCA\\Tables\\Constants\\ViewLayout' => ['normalize' => 'OCA\\Tables\\Constants\\ViewLayout'],
		] as $class => $methods) {
			foreach ($methods as $method => $return) {
				$parameters = [];
				foreach ($fixture['surfaces'][$class]['methods'][$method] as $i => $name) {
					$parameters[] = ['name' => rtrim($name, '?'), 'type' => $fixture['surfaces'][$class]['types'][$method][$i], 'optional' => str_ends_with($name, '?')];
				}
				$data['2.4.0'][] = [$class, $method, $parameters, $return];
			}
		}
		file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		$optional = json_decode(file_get_contents(__DIR__ . '/schema-api-surfaces.json'), true, 512, JSON_THROW_ON_ERROR);
		$surfaces = [];
		foreach ($optional['classes'] as $class) $surfaces[$class] = $fixture['surfaces'][$class];
		$optional['pins']['2.4.0'] = ['archiveSha256' => $fixture['archiveSha256'], 'server' => $fixture['server'], 'surfaces' => $surfaces];
		file_put_contents(__DIR__ . '/schema-api-surfaces.json', json_encode($optional, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	}
}

TablesPinSignatures::run();
