<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use PHPUnit\Framework\TestCase;

/** Every published write needs its own CriticalFlow transcript; substring matches cannot establish coverage. */
final class WriteToolCoverageTest extends TestCase {
	/** @return void The 1.2.4 UID fix closes Q1-a: every published write must now be covered */
	public function testEveryWriteHasItsOwnEffectTranscript(): void {
		$discovery = json_decode(file_get_contents(__DIR__ . '/../golden/discovery.all-on.json'), true, 512, JSON_THROW_ON_ERROR);
		$writes = array_column(array_filter($discovery['tools/list']['body']['result']['tools'],
			static fn (array $tool): bool => !$tool['annotations']['readOnlyHint']), 'name');
		$covered = [];
		foreach (glob(__DIR__ . '/../effects/*.json') as $file) {
			$flow = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			if (isset($flow['tool'])) {
				$covered[] = $flow['tool'];
			}
		}
		$this->assertSame([], array_values(array_diff($writes, $covered)));
	}
}
