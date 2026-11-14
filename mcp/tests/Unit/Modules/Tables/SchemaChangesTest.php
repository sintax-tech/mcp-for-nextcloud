<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Writing\{SchemaColumnChanges, SchemaViewChanges};
use PHPUnit\Framework\TestCase;

/** Relation immutability, typed filter domains and bounded regex compilation. */
final class SchemaChangesTest extends TestCase {
	/** @return void Unsupported relation columns cannot be edited, including metadata. */
	public function testRelationColumnRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		SchemaColumnChanges::of(['title' => 'Changed'], ['type' => 'relation', 'subtype' => '']);
	}
	/** @return void A view may edit metadata while leaving its existing relation references untouched. */
	public function testRelationReferencesArePreservedWithoutEditing(): void {
		$before = ['columnSettings' => [['columnId' => 7, 'order' => 0]], 'filter' => [[['columnId' => 7, 'operator' => 'is-equal', 'value' => '1']]], 'sort' => []];
		$columns = [7 => ['type' => 'relation']];
		$this->assertSame(['title' => 'Changed'], SchemaViewChanges::of(['title' => 'Changed'], $before, $columns, '2.3.2'));
		$this->expectException(\InvalidArgumentException::class);
		SchemaViewChanges::of(['columns' => []], $before, $columns, '2.3.2');
	}
	/** @return void New relation predicates cannot be introduced through view editing. */
	public function testNewRelationFilterRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		SchemaViewChanges::of(['filter' => [[['column_id' => 7, 'operator' => 'is-equal', 'value' => 1]]]], ['filter' => []], [7 => ['type' => 'relation', 'subtype' => '']], '2.3.2');
	}
	/** @return void A saved filter cannot refer to a foreign column. */
	public function testForeignFilterColumnIsNotFound(): void {
		$this->expectExceptionMessage(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound());
		SchemaViewChanges::of(['sort' => [['column_id' => 7, 'mode' => 'ASC']]], [], [], '2.3.2');
	}
	/** @return void Empty lists clear rules and invalid operators do not disappear silently. */
	public function testEmptyAndTypedRules(): void {
		$this->assertSame(['filter' => [], 'sort' => []], SchemaViewChanges::of(['filter' => [], 'sort' => []], [], [], '2.3.2'));
		$this->expectException(\InvalidArgumentException::class);
		SchemaViewChanges::of(['filter' => [[['column_id' => 1, 'operator' => 'contains', 'value' => 'x']]]], [], [1 => ['type' => 'number', 'subtype' => '']], '2.3.2');
	}
	/** @return void Only the proven native provider CSV can be changed; text regex writing stays closed. */
	public function testPatternWritesRequireProvenNativeSemantics(): void {
		$result = SchemaColumnChanges::of(['type' => 'text', 'subtype' => 'link', 'options' => [['name' => 'textAllowedPattern', 'value' => 'url,files,contacts']]], null);
		$this->assertSame('url,files,contacts', $result['textAllowedPattern']);
		foreach (['^[A-Z]+$', '/^[a-z]+$/u', ''] as $pattern) {
			try { SchemaColumnChanges::of(['type' => 'text', 'subtype' => 'line', 'options' => [['name' => 'textAllowedPattern', 'value' => $pattern]]], null); $this->fail('Unproved text regex written'); }
			catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
		}
	}
	/** @return void Native settings objects retain readonly/mandatory when only visible order is edited. */
	public function testNestedNativeSettingsAreMaterializedWithoutDroppingFlags(): void {
		$settings = new class implements \JsonSerializable {
			/** @return array<string,mixed> Native column information shape. */
			public function jsonSerialize(): array { return ['columnId' => 1, 'order' => 1, 'readonly' => true, 'mandatory' => true]; }
		};
		$view = new class($settings) {
			/** @param object $settings Native nested information. */
			public function __construct(private object $settings) {}
			/** @return array<string,mixed> Native view metadata. */
			public function jsonSerialize(): array { return ['columnSettings' => [$this->settings]]; }
		};
		$before = \OCA\Mcp\Modules\Tables\Integration\SchemaNativeSnapshot::of($view);
		$result = SchemaViewChanges::of(['columns' => [1]], $before, [1 => ['type' => 'text', 'subtype' => 'line']], '2.3.2');
		$this->assertTrue($result['columnSettings'][0]['readonly']);
		$this->assertTrue($result['columnSettings'][0]['mandatory']);
	}
	/** @return void Existing relation rules cannot be rewritten through a different predicate. */
	public function testExistingRelationFilterCannotBeReplaced(): void {
		$this->expectExceptionMessage('Invalid argument: patch.filter');
		SchemaViewChanges::of(['filter' => []], ['filter' => [[['columnId' => 7, 'operator' => 'is-equal', 'value' => '1']]]], [7 => ['type' => 'relation']], '2.3.2');
	}
	/** @return void Selection IDs/order are preserved even when new values are individually valid. */
	public function testSelectionCannotBeReorderedOrRemoved(): void {
		$old = ['type' => 'selection', 'subtype' => '', 'selectionOptions' => [['id' => 1, 'label' => 'One'], ['id' => 2, 'label' => 'Two']]];
		foreach ([array_reverse($old['selectionOptions']), [$old['selectionOptions'][0]]] as $values) {
			try { SchemaColumnChanges::of(['options' => [['name' => 'selectionOptions', 'value' => $values]]], $old); $this->fail('Selection order/removal accepted'); }
			catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
		}
	}
	/** @return void A syntactically valid expression must still obey the size bound. */
	public function testOversizedPatternIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		SchemaColumnChanges::of(['type' => 'text', 'subtype' => 'link', 'options' => [['name' => 'textAllowedPattern', 'value' => str_repeat('url,', 130) . 'files']]], null);
	}
	/** @return void Old DTO truthiness must never silently discard an explicit clear or zero string. */
	public function testOldViewDescriptionEmptyAndZeroAreRejected(): void {
		foreach (['2.2.2'] as $profile) foreach (['', '0'] as $value) {
			try { SchemaViewChanges::of(['description' => $value], [], [], $profile); $this->fail('Native truthiness discarded description'); }
			catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
		}
	}

}
