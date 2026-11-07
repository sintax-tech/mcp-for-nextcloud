<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Container evidence never proves its unknown values scalar; nested and nullable types retain every object. */
final class CollectivesArrayDomainsTest extends CallGraphTreeTestCase {
	/** @return void declared array writes/count do not dispatch object protocols */
	public function testDeclaredArrayContainerCanBeWrittenWithoutGuessingElements(): void {
		$r = $this->reach(['Entry' => 'private array $data = [];
			public function read($value): void { $this->data["key"] = $value; count($this->data); }'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
	}

	/** @return void the same container cannot silently acquire scalar element evidence */
	public function testUnprovedArrayElementRemainsUnknown(): void {
		$r = $this->reach(['Entry' => 'private array $data = [];
			public function read($value): void { $this->data["key"] = $value; strval($this->data["key"]); }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'implicit receiver of unknown type');
	}

	/** @return void a nullable union inside a generic array keeps the object destination */
	public function testNullableGenericDoesNotEraseObjectElements(): void {
		$r = $this->reach([
			'Text' => 'public function __toString(): string { (new Folder())->newFile("nullable"); return ""; }',
			'Entry' => '/** @param array<string, Text|null> $items */
				public function read(array $items): void { foreach ($items as $item) { strval($item); } }',
		], 'Entry::read');
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
	}

	/** @return void nested immutable literal tables preserve key and value proof at each level */
	public function testNestedStaticLiteralsAreProved(): void {
		$r = $this->reach(['Entry' => 'private static array $table = [["id" => "safe"]];
			public function read(): void { foreach (self::$table as $row) { strval($row["id"]); } }'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
	}

	/** @return void later writes cannot be hidden by an empty/scalar property initializer */
	public function testMutableLiteralTableDoesNotDiscardUnknownWrites(): void {
		$r = $this->reach(['Entry' => 'private array $table = ["safe"];
			public function read($value): void { $this->table[] = $value; foreach ($this->table as $item) { strval($item); } }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

	/** @return void strict deduplication keeps unknown even when scalar appears first */
	public function testUnknownAndScalarAreDifferentUnionMembers(): void {
		$this->assertContains(null, \CallGraphTypes::union(['', null]));
		$this->assertContains('', \CallGraphTypes::union([null, '']));
	}
}
