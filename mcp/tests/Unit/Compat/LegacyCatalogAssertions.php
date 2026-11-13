<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Compat;

use OCA\Mcp\Tests\Integration\Compat\LegacyCatalogContract;
use OCA\Mcp\Tests\Integration\Compat\RoutineCatalogAdditions;
use PHPUnit\Framework\Assert;
use OCA\Mcp\Access\Grants\GrantCatalog;
use UnexpectedValueException;

/** Keeps the unit upgrade guards aligned with only the reviewed 1.2.2 and approved 1.3.0 F00 and Collectives C01–C04 and denied 1.4.0 opt-in catalog/default additions. */
final class LegacyCatalogAssertions {
	/** @var array<string, list<string>> Explicit operations added after the released baseline. */
	private const MODULES = [
		'mail' => ['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'],
		'tables' => ['read', 'create', 'edit', 'schema', 'share'],
		'forms' => ['read', 'results', 'create', 'edit'],
		'polls' => ['read', 'results', 'links', 'create', 'configure', 'options', 'options.remove', 'share', 'invite'],
		// C01–C04: each reviewed operation is explicit and every write starts denied.
		'collectives' => ['read', 'create', 'edit', 'move', 'collective.create', 'members'],
	];

	/** @return void Legacy operations remain exact; missing and unreviewed additions fail. */
	public static function catalog(): void {
		// Exact public catalog from v1.0.8: exercise the real candidate, not only the synthetic fixture.
		$released = [
			'files' => ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'],
			'notes' => ['read', 'create', 'edit', 'move', 'delete'],
			'deck' => ['read', 'create', 'edit', 'move', 'delete'],
			'contacts' => ['read', 'create', 'edit', 'delete'],
			'tasks' => ['read', 'create', 'edit', 'delete'],
			'calendar' => ['read', 'create', 'edit', 'move', 'delete', 'transfer'],
			'people' => ['read'], 'talk' => ['read', 'reply', 'attach', 'quote', 'create'], 'logs' => ['read'],
		];
		Assert::assertSame($released, LegacyCatalogContract::baseline($released, GrantCatalog::CATALOG));

		$baseline = ['files' => ['read', 'edit'], 'notes' => ['read'], 'calendar' => ['read']];
		$current = RoutineCatalogAdditions::catalog(['files' => ['read', 'edit', 'delete'], 'notes' => ['read'], 'calendar' => ['read']] + self::MODULES);
		Assert::assertSame($baseline, LegacyCatalogContract::baseline($baseline, $current));
		$missing = $current;
		$missing['files'] = ['read', 'delete'];
		$unknown = $current;
		$unknown['mail'][] = 'write';
		$reordered = $current;
		$reordered['files'] = ['edit', 'read', 'delete'];
		$modulesReordered = array_reverse($current, true);
		foreach ([$missing, $unknown, $reordered, $modulesReordered, $current + ['unknown' => ['read']], $baseline + ['mail' => ['read']], ['mail' => ['read']]] as $invalid) {
			try {
				LegacyCatalogContract::baseline($baseline, $invalid);
				Assert::fail('Unexpected catalog accepted');
			} catch (UnexpectedValueException $error) {
				Assert::assertStringContainsString('1.2.2', $error->getMessage());
			}
		}
	}

	/** @return void Check every new default without projecting away any changed legacy value. */
	public static function matrix(): void {
		$legacy = ['1' => ['matrix' => ['alice' => ['eligible' => true, 'connected' => false, 'grants' => ['files' => ['read' => false], 'calendar' => ['read' => true]]]]]];
		$branch = $legacy;
		$grants = $branch['1']['matrix']['alice']['grants'];
		$grants['files']['delete'] = false;
		foreach (self::MODULES as $module => $operations) {
			foreach ($operations as $operation) {
				$grants[$module][$operation] = $operation === 'read';
			}
		}
		foreach (RoutineCatalogAdditions::PAIRS as $module => $operations) {
			foreach ($operations as $operation) $grants[$module][$operation] = false;
		}
		$branch['1']['matrix']['alice']['grants'] = $grants;
		Assert::assertSame($legacy, LegacyCatalogContract::answers($branch));
		$branch['1']['matrix']['alice']['grants']['files']['read'] = true;
		Assert::assertNotSame($legacy, LegacyCatalogContract::answers($branch), 'Changed legacy answers cannot be projected away');
		foreach (RoutineCatalogAdditions::PAIRS as $module => $operations) {
			foreach ($operations as $operation) {
				$changed = $branch;
				$changed['1']['matrix']['alice']['grants'][$module][$operation] = true;
				try {
					LegacyCatalogContract::answers($changed);
					Assert::fail("Unexpected $module.$operation default accepted");
				} catch (UnexpectedValueException $error) {
					Assert::assertStringContainsString($module, $error->getMessage());
				}
			}
		}
		foreach (['files', ...array_keys(self::MODULES)] as $module) {
			$operation = $module === 'files' ? 'delete' : 'read';
			$changed = $branch;
			$changed['1']['matrix']['alice']['grants'][$module][$operation] = !$grants[$module][$operation];
			try {
				LegacyCatalogContract::answers($changed);
				Assert::fail("Unexpected $module default accepted");
			} catch (UnexpectedValueException $error) {
				Assert::assertStringContainsString($module, $error->getMessage());
			}
		}
	}
}
