<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Numeric operators and enum backing values require actual operand or declaration evidence. */
final class CollectivesScalarEvidenceTest extends CallGraphTreeTestCase {
	/** @return void literal/default/declared numeric operands retain their numeric result */
	public function testNumericOperationsAndDefaultArgumentsAreProved(): void {
		$r = $this->reach(['Entry' => 'public function read(int $x): void {
			strval(++$x); strval($x--); strval($x + 2); $this->check(); $this->check(3);
		} private function check($case = 0): void { if ($case >= 0) { strval(--$case); } }'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
	}

	/** @return void an untyped input does not acquire a numeric type just because an operator is applied */
	public function testUnprovedArithmeticRemainsUnknown(): void {
		$r = $this->reach(['Entry' => 'public function read($x): void { strval(++$x); strval($x + 1); }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

	/** @return void a known default does not overwrite an unknown actual caller */
	public function testUnknownCallerSurvivesTheKnownDefault(): void {
		$r = $this->reach(['Entry' => 'public function read($x): void { $this->check($x); }
			private function check($case = 0): void { strval(--$case); }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

	/** @return void a real enum case remains an object and its backing value has the declared native type */
	public function testBackedEnumValueAndCaseProtocolsAreProved(): void {
		file_put_contents($this->lib . '/Service/Kind.php', '<?php namespace Example;
			enum Kind: string implements \\Countable { case Item = "x";
				public function count(): int { (new \\OCP\\Files\\Folder())->newFile("enum"); return 1; } }
			class Entry { public function read(): void { strval(Kind::Item->value); count(Kind::Item); } }');
		$r = (new \CollectivesCallGraph($this->lib))->reach(['Example\\Entry::read']);
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
		$this->assertArrayHasKey('Example\\Kind::count', $r['methods']);
	}

	/** @return void a similarly named untyped property does not borrow the enum language rule */
	public function testNonEnumValueNeedsItsOwnTypeProof(): void {
		$r = $this->reach(['Fake' => 'public $value;',
			'Entry' => 'public function read(Fake $fake): void { strval($fake->value); }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

	/** @return void min/max select scalar operands without inventing an array-element unknown */
	public function testMinMaxRequireProvedOperands(): void {
		$ok = $this->reach(['Entry' => 'public function read(int $a): void { strval(min($a, 3)); strval(max([$a, 4])); }'], 'Entry::read');
		$this->assertSame([], $ok['unknown']);
		$bad = $this->reach(['Entry' => 'public function read($a): void { strval(min($a, 3)); }'], 'Entry::read');
		$this->assertNotSame([], $bad['unknown']);
	}

	/** @return void a native union retains its object member instead of treating the whole declaration as scalar */
	public function testNativeUnionKeepsObjectAlternative(): void {
		$r = $this->reach([
			'Text' => 'public function __toString(): string { (new Folder())->newFile("union"); return "a"; }',
			'Entry' => 'public function read(string|Text $value): void { strval($value); }',
		], 'Entry::read');
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
	}

	/** @return void a class merely naming its factory from does not receive a backed-enum return proof */
	public function testNonEnumFactoryCannotBorrowTheNativeFromRule(): void {
		$r = $this->reach(['Fake' => 'public static function from($value) { return mystery($value); }',
			'Entry' => 'public function read(): void { strval(Fake::from("x")->value); }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}
}
