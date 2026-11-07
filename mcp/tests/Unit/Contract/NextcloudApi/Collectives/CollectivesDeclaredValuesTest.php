<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Magic returns and array columns require explicit value evidence. */
final class CollectivesDeclaredValuesTest extends CallGraphTreeTestCase {
	/** @return void a property default is insufficient evidence for a magic field */
	public function testUntypedMagicFieldRemainsUnknown(): void {
		$r = $this->reach(['Entry' => 'protected $term = "initial";
			public function read(): void { strval($this->getTerm()); }
			public function __call($name, array $args) { $field = lcfirst(substr($name, 3)); return $this->$field; }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'implicit receiver of unknown type');
	}

	/** @return void a dynamic name is not guessed from the possible field names */
	public function testDynamicMagicNameRemainsUnknown(): void {
		$r = $this->reach(['Entry' => 'protected string $term;
			public function read(string $name): void { strval($this->$name()); }
			public function __call($name, array $args) { $field = lcfirst(substr($name, 3)); return $this->$field; }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

	/** @return void a bare array declaration does not prove its records or column values */
	public function testUnprovedRecordsRemainUnknown(): void {
		$r = $this->reach(['Entry' => 'public function read(array $rows): void {
			array_unique(array_column($rows, "id")); }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'implicit receiver of unknown type');
	}

	/** @return void scalar literal records and declared magic fields supply positive evidence */
	public function testDeclaredFieldAndLiteralRecordsAreProved(): void {
		$r = $this->reach(['Entry' => 'protected string $term;
			public function read(): void {
				strval($this->getTerm()); array_unique(array_column([["id" => 3]], "id"));
			}
			public function __call($name, array $args) { $field = lcfirst(substr($name, 3)); return $this->$field; }'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Entry::__call', $r['methods']);
	}

	/** @return void every possible concrete class contributes its contract, including unknowns */
	public function testUntypedSubclassCannotBeDiscarded(): void {
		$r = $this->reach([
			'Base' => 'public function __call($name, array $args) { $field = lcfirst(substr($name, 3)); return $this->$field; }',
			'Known extends Base' => 'protected string $term;',
			'Entry' => 'public function read(Base $value): void { strval($value->getTerm()); }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'implicit receiver of unknown type');
	}
	/** @return void a typed field with a similarly named method is not proof of an arbitrary __call result */
	public function testGetterPrefixWithoutActualFieldDelegationIsUnknown(): void {
		$r = $this->reach(['Entry' => 'protected string $term;
			public function read(): void { strval($this->getTerm()); }
			public function __call($name, array $args) { return mystery(); }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

}
