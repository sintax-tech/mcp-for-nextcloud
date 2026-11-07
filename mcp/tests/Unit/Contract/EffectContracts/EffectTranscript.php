<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tests\Unit\Contract\Oracle\VolatileFields;
use PHPUnit\Framework\TestCase;

/**
 * A transcript of calls a {@see \OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner} cannot make: refusals asked directly
 * (a stale ETag, a hidden destination) and the HTTP routes of a checkout. Each entry keeps the request, the answer and
 * the observed effect; the whole is normalized with {@see VolatileFields} and compared with `effects/<name>.json`.
 */
final class EffectTranscript {
	/** Directory of the effect transcripts. */
	private const DIR = __DIR__ . '/../effects/';

	/** @var list<array<string, mixed>> entries recorded so far */
	private array $steps = [];

	/**
	 * @param TestCase $test the running test, for its assertions
	 * @param string $name golden file name without extension, e.g. `files.edit-refusals`
	 */
	public function __construct(private TestCase $test, private string $name) {
	}

	/**
	 * @param string $step name of the entry
	 * @param mixed $request what was asked
	 * @param mixed $response what was answered
	 * @param mixed $state the observable effect right after it
	 * @return void
	 */
	public function record(string $step, mixed $request, mixed $response, mixed $state): void {
		$this->steps[] = ['step' => $step, 'request' => $request, 'response' => $response, 'state' => $state];
	}

	/**
	 * @param array<string, string> $literals random values to replace in the transcript only, original => placeholder
	 * @return void
	 */
	public function assertGolden(array $literals = []): void {
		GoldenFile::assertMatches($this->test, self::DIR . $this->name . '.json',
			VolatileFields::normalize(['steps' => $this->steps], $literals));
	}
}
