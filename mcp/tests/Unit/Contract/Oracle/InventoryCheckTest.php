<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use PHPUnit\Framework\TestCase;

/**
 * The inventory gate accepts a run only when every baseline test ran and failed or was skipped exactly where it did.
 */
final class InventoryCheckTest extends TestCase {
	/** @return void the baseline itself passes */
	public function testTheBaselineRunPasses(): void {
		$check = $this->check();
		$this->assertTrue($check->check($this->report(['A::one' => '', 'A::two' => 'failure', 'B::three' => ''])), implode("\n", $check->problems));
	}

	/** @return void a baseline test reported as skipped or incomplete did not run, even with the expected failures kept */
	public function testSkippedOrIncompleteBaselineTestsFail(): void {
		foreach (['skipped', 'incomplete'] as $kind) {
			$check = $this->check();
			$this->assertFalse($check->check($this->report(['A::one' => $kind, 'A::two' => 'failure', 'B::three' => $kind])));
			$this->assertContains('missing from the run: A::one', $check->problems);
			$this->assertContains('skipped but not in the baseline: B::three', $check->problems);
			$this->assertSame(1, $check->ran);
		}
	}

	/** @return void a missing test, a new failure, a fixed failure and an unreadable report all fail */
	public function testOtherDifferencesFail(): void {
		$check = $this->check();
		$this->assertFalse($check->check($this->report(['A::one' => '', 'A::two' => 'failure'])));
		$this->assertSame(['missing from the run: B::three'], $check->problems);
		$this->assertFalse($check->check($this->report(['A::one' => 'error', 'A::two' => 'failure', 'B::three' => ''])));
		$this->assertSame(['failing but not in the baseline: A::one'], $check->problems);
		$this->assertFalse($check->check($this->report(['A::one' => '', 'A::two' => '', 'B::three' => ''])));
		$this->assertSame(['baseline failure no longer failing: A::two'], $check->problems);
		$this->assertFalse($check->check('not xml'));
		$this->assertFalse($check->check('<testsuites/>'));
	}

	/** @return void a skip the baseline authorizes passes only while it stays a skip */
	public function testAuthorizedSkipMustStaySkipped(): void {
		$check = new InventoryCheck(['A::one', 'A::two'], [], ['A::two']);
		$this->assertTrue($check->check($this->report(['A::one' => '', 'A::two' => 'skipped'])), implode("\n", $check->problems));
		$this->assertFalse($check->check($this->report(['A::one' => '', 'A::two' => ''])));
		$this->assertSame(['baseline skip that ran or vanished: A::two'], $check->problems);
	}

	/** @return void the FQCN map of a move renames the baseline identities */
	public function testMapRenamesBaselineClasses(): void {
		$check = new InventoryCheck(['Old\\A::one'], [], [], ['Old\\A' => 'New\\A']);
		$this->assertTrue($check->check($this->report(['New\\A::one' => ''])), implode("\n", $check->problems));
	}

	/** @return InventoryCheck a baseline of three tests, A::two failing */
	private function check(): InventoryCheck {
		return new InventoryCheck(['A::one', 'A::two', 'B::three'], ['A::two'], []);
	}

	/**
	 * @param array<string, string> $cases identity => '' (passed), 'failure', 'error', 'skipped' or 'incomplete'
	 * @return string a JUnit report with those cases
	 */
	private function report(array $cases): string {
		$xml = '<testsuites><testsuite name="unit">';
		foreach ($cases as $id => $outcome) {
			[$class, $name] = explode('::', $id, 2);
			$xml .= '<testcase class="' . htmlspecialchars($class) . '" name="' . htmlspecialchars($name) . '">'
				. ($outcome === '' ? '' : '<' . $outcome . '/>') . '</testcase>';
		}
		return $xml . '</testsuite></testsuites>';
	}
}
