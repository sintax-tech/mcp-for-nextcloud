<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use Closure;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tests\Unit\Contract\Oracle\VolatileFields;
use PHPUnit\Framework\TestCase;

/**
 * The refusals of a module that the {@see \OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner} steps do not reach: a
 * confirmed call that the module itself must turn down (a shared board without `confirm_shared`, a list that still
 * holds cards, a folder of another account). Each one is asked through the endpoint, must leave the observed state
 * exactly as it was and must answer the refusal its caller names; the transcript is frozen in
 * `effects/<module>.<name>.json` like a flow.
 */
final class RefusalTranscript {
	/** Directory of the effect transcripts. */
	private const DIR = __DIR__ . '/../effects/';

	/** @var list<array<string, mixed>> the refusals asked so far */
	private array $steps = [];

	/**
	 * @param TestCase $test the running test, for its assertions
	 * @param EffectsGate $gate the endpoint, already serving the module
	 * @param Closure(): mixed $state the observable effect of the module
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate, private Closure $state) {
	}

	/**
	 * Asks one call that must be refused and records it.
	 *
	 * @param string $name what the refusal is about
	 * @param string $tool tool called
	 * @param array<string, mixed> $arguments arguments of the call, confirmation included
	 * @param string $expected text the answer must contain: the refusal the module gives
	 * @return mixed the JSON-RPC body of the answer
	 */
	public function refuse(string $name, string $tool, array $arguments, string $expected): mixed {
		$before = ($this->state)();
		$response = $this->gate->call($tool, $arguments);
		$after = ($this->state)();
		$this->test->assertEquals($before, $after, $tool . ': refusal ' . $name . ' changed something');
		$text = json_encode($response['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->test->assertStringContainsString($expected, $text, $tool . ': ' . $name . ' is not the expected refusal: ' . $text
			. json_encode($this->gate->logged()));
		$this->test->assertStringNotContainsString('Unexpected error while accessing Nextcloud.', $text, $tool . ': ' . $name
			. ' fell into the generic error: ' . json_encode($this->gate->logged()));
		$this->steps[] = ['refusal' => $name, 'tool' => $tool, 'arguments' => $arguments, 'response' => $response, 'state' => $after];
		return $response['body'];
	}

	/**
	 * Compares the recorded refusals with their golden file.
	 *
	 * @param string $file golden file name without directory and extension, `<module>.<name>`
	 * @return void
	 */
	public function assertGolden(string $file): void {
		GoldenFile::assertMatches($this->test, self::DIR . $file . '.json', VolatileFields::normalize(['refusals' => $this->steps]));
	}
}
