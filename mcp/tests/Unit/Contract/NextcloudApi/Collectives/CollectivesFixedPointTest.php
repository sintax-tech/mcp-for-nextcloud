<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Shape and recursion inference keeps explicit unknown callers distinct from provisional recursive bottom. */
final class CollectivesFixedPointTest extends CallGraphTreeTestCase {
	/** @return void heterogeneous literal fields retain their independent types */
	public function testHeterogeneousReturnFieldsAreProvedByKey(): void {
		$r = $this->reach(['Entry' => 'public function read(): void {
			foreach ($this->record()["ids"] as $id) { strval($id); }
		} private function record(): array { return ["ids" => [1, 2], "kind" => "match"]; }'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
	}

	/** @return void a known field in one branch cannot erase an unproved field in another */
	public function testUnknownFieldSurvivesReturnUnion(): void {
		$r = $this->reach(['Entry' => 'public function read($unknown): void { strval($this->record($unknown)["value"]); }
			private function record($value): array { if ($value) { return ["value" => $value]; } return ["value" => "safe"]; }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

	/** @return void seeded recursive caller/return dependence reaches the finite scalar record fixed point */
	public function testRecursiveMergePreservesKnownRecords(): void {
		$r = $this->reach(['Entry' => 'public function read(): void {
			$rows = []; $rows = $this->merge($rows, [["id" => 1]]);
			foreach ($rows as $row) { strval($row["id"]); }
		} private function merge(array $rows, array $next): array {
			foreach ($next as $row) { $rows[] = $row; } return $rows;
		}'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
	}

	/** @return void a recursion without a proved seed is explicitly rejected */
	public function testUnseededRecursionIsUnknown(): void {
		$r = $this->reach(['Entry' => 'public function read(): void { strval($this->cycle()); }
			private function cycle() { return $this->cycle(); }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

	/** @return void contradictory caller data cannot borrow a scalar docblock to discard an object */
	public function testCallerObjectCannotBeErasedByAnArrayDocblock(): void {
		$r = $this->reach([
			'Text' => 'public function __toString(): string { (new Folder())->newFile("doc"); return "a"; }',
			'Entry' => 'public function read(): void { $this->useItems([new Text()]); }
				/** @param string[] $items */ private function useItems(array $items): void { foreach ($items as $item) { strval($item); } }',
		], 'Entry::read');
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
	}
}
