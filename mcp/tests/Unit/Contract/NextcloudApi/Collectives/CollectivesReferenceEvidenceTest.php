<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Regex captures have language-defined scalar/tuple shapes; prior unknown values are never overwritten. */
final class CollectivesReferenceEvidenceTest extends CallGraphTreeTestCase {
	/** @return void plain captures and PREG_OFFSET_CAPTURE arrays retain scalar leaf proof */
	public function testRegexOutParametersAreProved(): void {
		$r = $this->reach(['Entry' => 'public function read(): void {
			preg_match("/(a)/", "a", $match); strval($match[1]);
			preg_match_all("/(a)/", "a", $all, PREG_OFFSET_CAPTURE);
			foreach ($all as $group) { foreach ($group as $row) { strval($row[0]); } }
		}'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
	}

	/** @return void an unresolved pattern/flag mask still cannot cause capture objects, but existing unknown input remains unknown */
	public function testUnknownExistingOutValueIsNotErased(): void {
		$r = $this->reach(['Entry' => 'public function read($old): void {
			$match = $old; preg_match("/(a)/", "a", $match); strval($match[1]);
		}'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}

	/** @return void an unmodelled function with a reference-looking call acquires no out-parameter proof */
	public function testOtherFunctionsDoNotAcquireRegexFacts(): void {
		$r = $this->reach(['Entry' => 'public function read(): void { unknown_match("a", "a", $out); strval($out[0]); }'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}
}
