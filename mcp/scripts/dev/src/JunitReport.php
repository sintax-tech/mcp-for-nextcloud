<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

use DOMDocument;
use RuntimeException;

/**
 * Test cases of a PHPUnit JUnit report, and which of them failed (a `failure` or an `error` child).
 */
final class JunitReport {
	/**
	 * @param array<string,bool> $cases `Class::name` => true when it failed; the name keeps its data set suffix
	 */
	public function __construct(public readonly array $cases) {
	}

	/**
	 * Parses a report.
	 *
	 * @param string $xml the JUnit document
	 * @return self the report
	 * @throws RuntimeException when the document is not XML
	 */
	public static function fromXml(string $xml): self {
		$document = new DOMDocument();
		if ($xml === '' || !@$document->loadXML($xml)) {
			throw new RuntimeException('junit: not an XML document');
		}
		$cases = [];
		foreach ($document->getElementsByTagName('testcase') as $case) {
			$id = $case->getAttribute('class') . '::' . $case->getAttribute('name');
			$failed = $case->getElementsByTagName('failure')->length > 0 || $case->getElementsByTagName('error')->length > 0;
			$cases[$id] = ($cases[$id] ?? false) || $failed;
		}
		return new self($cases);
	}

	/**
	 * Classes that have at least one test case.
	 *
	 * @return list<string> fully qualified names
	 */
	public function classes(): array {
		return array_values(array_unique(array_map(static fn (string $id): string => explode('::', $id, 2)[0], array_keys($this->cases))));
	}

	/**
	 * Failed test cases.
	 *
	 * @return list<string> `Class::name`, sorted
	 */
	public function failures(): array {
		$failed = array_keys(array_filter($this->cases));
		sort($failed, SORT_STRING);
		return $failed;
	}
}
