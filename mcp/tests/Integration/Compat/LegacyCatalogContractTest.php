<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Compat;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/** Ensures the compatibility allowance cannot hide unrelated catalog or default changes. */
final class LegacyCatalogContractTest extends TestCase {
	/** @var array<string, list<string>> Small legacy catalog with both read and write cells. */
	private const BASELINE = ['files' => ['read', 'edit'], 'notes' => ['read', 'create']];

	/** @return array<string, list<string>> Explicitly documented additions to the legacy fixture. */
	private function current(): array {
		return [
			'files' => ['read', 'edit', 'delete'],
			'notes' => ['read', 'create'],
			'mail' => ['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'],
			'tables' => ['read', 'create', 'edit', 'schema', 'share'],
			'forms' => ['read', 'results', 'create', 'edit'],
			'polls' => ['read', 'results', 'links', 'create', 'configure', 'options', 'options.remove', 'share', 'invite'],
			// C01–C04: each reviewed operation is explicit and every write starts denied.
			'collectives' => ['read', 'create', 'edit', 'move', 'collective.create', 'members'],
		];
	}

	/** @return void Only the reviewed additions are removed from the legacy comparison. */
	public function testOnlyDocumentedAdditionsAreAccepted(): void {
		self::assertSame(self::BASELINE, LegacyCatalogContract::baseline(self::BASELINE, $this->current()));
		$answers = $this->answers();
		$expected = ['on' => ['matrix' => ['actor' => ['grants' => [
			'files' => ['read' => false, 'edit' => true], 'notes' => ['read' => true, 'create' => false],
		]]]]];
		self::assertSame($expected, LegacyCatalogContract::answers($answers));
	}

	/** @return void Removals, reordered operations and unrelated additions keep failing closed. */
	public function testUnrelatedCatalogChangesAreRejected(): void {
		$cases = [];
		$current = $this->current();
		foreach (array_keys($current) as $module) {
			$changed = $current;
			unset($changed[$module]);
			$cases[] = $changed;
			$changed = $current;
			$changed[$module][] = 'unreviewed';
			$cases[] = $changed;
			$changed = $current;
			$changed[$module] = array_reverse($changed[$module]);
			$cases[] = $changed;
		}
		$cases[] = $current + ['unreviewed' => ['read']];
		// Operations beyond the reviewed C04 grants still fail closed.
		foreach (['collective.delete', 'members.remove'] as $operation) {
			$future = $current;
			$future['collectives'][] = $operation;
			$cases[] = $future;
		}
		foreach ($cases as $changed) {
			try {
				LegacyCatalogContract::baseline(self::BASELINE, $changed);
				self::fail('An unrelated catalog change was accepted');
			} catch (UnexpectedValueException $error) {
				self::assertStringContainsString('1.2.2', $error->getMessage());
			}
		}
	}

	/** @return void Every new cell's default is checked, including each independent read-like grant. */
	public function testEveryNewDefaultIsChecked(): void {
		$answers = $this->answers();
		foreach ($answers['on']['matrix']['actor']['grants'] as $module => $grants) {
			foreach ($grants as $operation => $value) {
				if (isset(self::BASELINE[$module]) && in_array($operation, self::BASELINE[$module], true)) {
					continue;
				}
				$changed = $answers;
				$changed['on']['matrix']['actor']['grants'][$module][$operation] = !$value;
				try {
					LegacyCatalogContract::answers($changed);
					self::fail("Unexpected default accepted for $module.$operation");
				} catch (UnexpectedValueException $error) {
					self::assertStringContainsString($module, $error->getMessage());
				}
			}
		}
	}

	/** @return array<string, array<string, mixed>> A legacy matrix row with distinct existing grant values. */
	private function answers(): array {
		$grants = ['files' => ['read' => false, 'edit' => true, 'delete' => false], 'notes' => ['read' => true, 'create' => false]];
		foreach (array_slice($this->current(), 2) as $module => $operations) {
			foreach ($operations as $operation) {
				$grants[$module][$operation] = $operation === 'read';
			}
		}
		return ['on' => ['matrix' => ['actor' => ['grants' => $grants]]]];
	}
}
