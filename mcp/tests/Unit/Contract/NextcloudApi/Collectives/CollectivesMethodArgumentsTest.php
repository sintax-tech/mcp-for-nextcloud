<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Method and magic argument inference has the same union and unknown guarantees as function inference. */
final class CollectivesMethodArgumentsTest extends CallGraphTreeTestCase {
	/** @return void zero supplied magic arguments remain a proved empty array through another method call */
	public function testEmptyMagicArgumentsAndJsonResultsRetainTheirEvidence(): void {
		$r = $this->reach(['Entry' => 'public function read(): void { $this->missing(); }
			public function __call($name, array $args) { $this->sink($args); }
			private function sink(array $args): void {
				$args[0] = json_decode("[]", true); strval($args[0]);
			}'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Entry::__call', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Entry::sink', $r['methods']);
		$this->assertSame(['[]'], \CallGraphTypes::union(['[]']), 'a sole empty array cannot disappear during union');
	}

	/** @return void an object in one caller is not overwritten by a scalar array from another caller */
	public function testObjectArgumentsFromAllMethodCallersAreKept(): void {
		$r = $this->reach([
			'Text' => 'public function __construct(private Folder $f) {}
				public function __toString(): string { $this->f->newFile("method"); return ""; }',
			'Entry' => 'public function read(Folder $f): void { $this->sink([new Text($f)]); $this->sink(["scalar"]); }
				private function sink(array $values): void { foreach ($values as $value) { strval($value); } }',
		], 'Entry::read');
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
	}

	/** @return void an unproved argument remains unknown after another caller supplies a proved scalar array */
	public function testUnknownArgumentsFromAnyMethodCallerRemainUnknown(): void {
		$r = $this->reach(['Entry' => 'public function read(array $unknown): void { $this->sink($unknown); $this->sink(["scalar"]); }
			private function sink(array $values): void { foreach ($values as $value) { strval($value); } }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'implicit receiver of unknown type');
	}
}
