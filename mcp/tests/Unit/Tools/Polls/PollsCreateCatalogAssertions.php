<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use PHPUnit\Framework\TestCase;
use OCA\Mcp\Mcp\Confirmation\WriteGate;

/** Keep the exact original five read tools/negative checks and independently assert the one write. */
final class PollsCreateCatalogAssertions {
	/**
	 * Separates the creation definition without replacing exact catalog checks by contains.
	 * @param TestCase $test assertions
	 * @param list<array<string,mixed>> $tools all definitions
	 * @return list<array<string,mixed>> original read definitions
	 */
	public static function separate(TestCase $test, array $tools): array {
		$write = array_values(array_filter($tools,static fn(array $d):bool=>WriteGate::isWrite($d)));
		$test->assertCount(1,$write); $test->assertSame('polls_create_poll',$write[0]['name']);
		$test->assertSame(['polls','create','polls'],[$write[0]['module'],$write[0]['operation'],$write[0]['app']]);
		$test->assertFalse($write[0]['inputSchema']['additionalProperties']);
		return array_values(array_filter($tools,static fn(array $d):bool=>!WriteGate::isWrite($d)));
	}
}
