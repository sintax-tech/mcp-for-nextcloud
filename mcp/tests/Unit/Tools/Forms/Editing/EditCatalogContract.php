<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/** Updates the existing registry test through one delegation, preserving its read and default-deny assertions. */
final class EditCatalogContract {
	/** Checks the full catalog and separate creation grant without constructing native services.
	 * @param TestCase $test Existing registry test's fixture owner.
	 * @return void
	 */
	public static function check(TestCase $test): void {
		$module = (new \ReflectionClass(FormsModule::class))->newInstanceWithoutConstructor();
		// The seven 1.2.5 tools stay first and unchanged; the structure tools appended after them are checked in tests/Unit/Modules/Forms.
		$legacy = array_slice($module->definitions(), 0, 7);
		Assert::assertSame(['forms_list_forms', 'forms_read_form', 'forms_list_submissions', 'forms_read_submission', 'forms_create_form', 'forms_update_question', 'forms_update_option'], array_column($legacy, 'name'));
		foreach ($legacy as $definition) {
			$definition['annotations'] = \OCA\Mcp\Mcp\Tools\ToolPresentation::annotations($definition['name'], $definition['operation'], $definition['destructiveHint'] ?? false);
			$create = $definition['name'] === 'forms_create_form';
			$edit = str_starts_with($definition['name'], 'forms_update_');
			Assert::assertStringContainsString('Links are credentials.', $definition['description']);
			Assert::assertSame('forms', $definition['app']); Assert::assertSame($create ? 'create' : ($edit ? 'edit' : 'read'), $definition['operation']);
			Assert::assertSame($create || $edit, WriteGate::isWrite($definition)); Assert::assertFalse($definition['inputSchema']['additionalProperties']);
			if ($create || $edit) { Assert::assertArrayNotHasKey('grantAnyOf', $definition); Assert::assertFalse($definition['annotations']['idempotentHint']); }
		}
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($test), new InMemoryOAuthStore());
		Assert::assertSame(['read', 'results', 'create', 'edit'], GrantPolicy::CATALOG['forms']);
		foreach (GrantPolicy::CATALOG['forms'] as $operation) { Assert::assertSame($operation === 'read', $policy->granted('alice', 'forms', $operation)); }
	}
}
