<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Deck\AssigneeChanges;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the validation and the diff of the `assign` / `unassign` lists of `deck_edit_card`.
 */
final class AssigneeChangesTest extends TestCase {
	public function testAbsentListsMeanNoChange(): void {
		self::assertSame(['assign' => [], 'unassign' => []], AssigneeChanges::fromArguments(['cardId' => 7]));
	}

	public function testListsAreDeduplicated(): void {
		self::assertSame(
			['assign' => ['pedro'], 'unassign' => ['maria']],
			AssigneeChanges::fromArguments(['assign' => ['pedro', 'pedro'], 'unassign' => ['maria', 'maria']]),
		);
	}

	public function testAnAccountInBothListsIsRefusedWithFieldAndRule(): void {
		try {
			AssigneeChanges::fromArguments(['assign' => ['pedro', 'ana'], 'unassign' => ['ana']]);
			self::fail('accepted an account in both lists');
		} catch (ArgumentValidationException $e) {
			self::assertSame('assign', $e->details()['field']);
			self::assertSame('an account ID cannot be in both assign and unassign', $e->details()['rule']);
			self::assertStringNotContainsString('ana', $e->getMessage());
		}
	}

	public function testMalformedListsReportTheirOwnField(): void {
		foreach (['assign', 'unassign'] as $field) {
			foreach ([[''], [123], 'pedro', array_fill(0, 101, 'pedro')] as $value) {
				try {
					AssigneeChanges::fromArguments([$field => $value]);
					self::fail('accepted malformed ' . $field);
				} catch (ArgumentValidationException $e) {
					self::assertSame($field, $e->details()['field']);
				}
			}
		}
	}

	public function testResolveSplitsWhatChangesFromWhatIsIgnored(): void {
		self::assertSame(
			['add' => ['novo'], 'remove' => ['sai'], 'alreadyAssigned' => ['fica'], 'notAssigned' => ['nunca']],
			AssigneeChanges::resolve(['novo', 'fica'], ['sai', 'nunca'], ['fica', 'sai']),
		);
	}
}
