<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** String-offset assignment invokes object conversion even when the executed source declares strict types. */
final class CollectivesStrictOffsetTest extends RuntimeTreeTestCase {
	/** @return void strict offset conversion with a read-only body remains provable */
	public function testStrictOffsetConversionFollowsTheReadOnlyBody(): void {
		$r = $this->runtimeAndUnknown([
			'Text' => 'public function __toString(): string { return "a"; }',
			'Entry' => 'public function read(Folder $f): void { $s = "abc"; $s[0] = new Text(); }',
		], 0, true);
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods']);
		$this->assertStringStartsWith('<?php declare(strict_types=1);', file_get_contents($this->lib . '/Service/Entry.php'));
	}

	/** @return void a runtime write cannot disappear behind strict mode or a structured string-offset destination */
	public function testStrictOffsetConversionCannotHideAWritingBody(): void {
		foreach (['$s[0] = $m;', '[$s[0]] = [$m];', 'foreach ([$m] as $s[0]) {}'] as $write) {
			$r = $this->runtimeAndUnknown([
				'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("strict"); return "a"; }',
				'Entry' => 'public function read(Folder $f): void { $m = new Text($f); $s = "abc"; ' . $write . ' }',
			], 1, true);
			$this->assertArrayHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods']);
			$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
			$this->assertStringStartsWith('<?php declare(strict_types=1);', file_get_contents($this->lib . '/Service/Entry.php'));
		}
	}
}
