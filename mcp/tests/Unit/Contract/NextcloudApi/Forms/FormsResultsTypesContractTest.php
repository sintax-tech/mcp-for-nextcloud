<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

use OCA\Mcp\Modules\Forms\Reading\ResultsTypes;
use PHPUnit\Framework\TestCase;

/** Compares actual pinned native answer-type values with the independently maintained MCP projection whitelist. */
final class FormsResultsTypesContractTest extends TestCase {
	/** Requires set equality, unique members and exact source integrity for every supported pin.
	 * @return void
	 */
	public function testFourPinsMatchMcpSupportedValues(): void {
		$expected = ResultsTypes::SUPPORTED;
		self::assertCount(13, $expected);
		self::assertSame($expected, array_values(array_unique($expected)));
		sort($expected);
		foreach (FormsSourcePins::all() as $version => $archiveSha) {
			$fixture = json_decode(file_get_contents(__DIR__ . '/../../../../fixtures/nextcloud-api/forms/' . $version . '.json'), true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($archiveSha, $fixture['sha256']);
			$entry = $fixture['sources']['Constants'];
			self::assertSame($entry['sha256'], hash('sha256', $entry['source']));
			$actual = self::nativeValues($entry['source']);
			self::assertSame($actual, array_values(array_unique($actual)), $version . ': duplicated native type');
			sort($actual);
			self::assertSame($expected, $actual, $version . ': native values differ from MCP support');
		}
	}

	/** Resolves the pinned literal-string/self-reference declaration without executing source or guessing values.
	 * @param string $source SHA-checked official Constants source.
	 * @return list<string> Values actually referenced by ANSWER_TYPES.
	 */
	private static function nativeValues(string $source): array {
		self::assertSame(1, preg_match('/public\s+const\s+ANSWER_TYPES\s*=\s*\[([^\]]*)\]\s*;/s', $source, $match));
		$members = array_values(array_filter(array_map('trim', explode(',', $match[1])), static fn (string $member): bool => $member !== ''));
		$values = [];
		foreach ($members as $member) {
			self::assertSame(1, preg_match('/^self::(ANSWER_TYPE_[A-Z_]+)$/', $member, $reference), 'Unreviewed native type expression');
			$pattern = "/public\\s+const\\s+" . preg_quote($reference[1], '/') . "\\s*=\\s*'([a-z_]+)'\\s*;/";
			self::assertSame(1, preg_match($pattern, $source, $literal), $reference[1] . ': no reviewed string literal');
			$values[] = $literal[1];
		}
		return $values;
	}
}
