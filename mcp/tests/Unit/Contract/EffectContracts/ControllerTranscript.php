<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tests\Unit\Contract\Oracle\VolatileFields;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use PHPUnit\Framework\TestCase;

/**
 * The transcript of one HTTP controller flow for the effect contracts of RE-01, the controller counterpart of the
 * oracle's FlowRunner (whose steps are tools/call only): each step is the request sent to the controller method, the
 * answer it returned (status, JSON or template, the headers a client relies on) and the state of the stores right
 * after it. The whole transcript is compared with `effects/<area>.<flow>.json` through {@see GoldenFile}.
 *
 * Random values the code really stores (tokens, codes, pending ids, receipt ids) are never rewritten in the stores:
 * the contract registers each one with {@see self::literal()} once it has asserted its shape, and only the transcript
 * shows the placeholder. {@see VolatileFields} then applies its own documented keys.
 */
final class ControllerTranscript {
	/** Directory of the effect transcripts. */
	private const DIR = __DIR__ . '/../effects/';
	/** Response headers that are part of the contract of these routes. */
	private const HEADERS = ['Cache-Control', 'Pragma', 'Retry-After', 'Referrer-Policy'];

	/** @var list<array<string, mixed>> the steps recorded so far */
	private array $steps = [];
	/** @var array<string, string> original value => placeholder, applied to the whole transcript */
	private array $literals = [];

	/**
	 * @param TestCase $test the running test, for its assertions
	 * @param string $name golden file name, without directory and extension (`<area>.<flow>`)
	 * @param \Closure(): array<string, mixed> $state the observable state of the stores, JSON-encodable
	 */
	public function __construct(private TestCase $test, private string $name, private \Closure $state) {
		$this->steps[] = ['step' => 'initial', 'state' => ($this->state)()];
	}

	/**
	 * Replaces a value in the transcript only (never in the stores).
	 *
	 * @param string $original the exact value, as stored or answered
	 * @param string $placeholder what the transcript shows instead
	 * @return void
	 */
	public function literal(string $original, string $placeholder): void {
		$this->literals[$original] ??= $placeholder;
	}

	/**
	 * Records one request with its answer and the state after it.
	 *
	 * @param string $step what the step proves, in a few words
	 * @param array<string, mixed> $request route, signed-in user and parameters as the client sent them
	 * @param Response $response what the controller returned
	 * @return array<string, mixed> the recorded answer, for the contract's own assertions
	 */
	public function record(string $step, array $request, Response $response): array {
		$answer = self::answer($response);
		$this->steps[] = ['step' => $step, 'request' => $request, 'response' => $answer, 'state' => ($this->state)()];
		return $answer;
	}

	/** @return array<string, mixed> the state recorded by the last step, before normalization */
	public function lastState(): array {
		return $this->steps[count($this->steps) - 1]['state'];
	}

	/**
	 * Compares the normalized transcript with its golden file. Before that, no 32-hex or longer id and no `ncmcp_`
	 * secret may remain: every random value must have been registered, or the golden would hold it.
	 *
	 * @return void
	 */
	public function assertGolden(): void {
		$normalized = VolatileFields::normalize(['flow' => $this->name, 'steps' => $this->steps], $this->literals);
		$this->test->assertDoesNotMatchRegularExpression('/[0-9a-f]{32}|ncmcp_(at|rt|code)_/', GoldenFile::encode($normalized),
			'an unregistered random value would make the golden unstable');
		GoldenFile::assertMatches($this->test, self::DIR . $this->name . '.json', $normalized);
	}

	/**
	 * @param Response $response a controller answer
	 * @return array<string, mixed> status, body and the contract headers, JSON-encodable
	 */
	public static function answer(Response $response): array {
		$answer = ['status' => $response->getStatus()];
		if ($response instanceof JSONResponse) {
			$answer['json'] = $response->getData();
			if ($response->isThrottled()) {
				$answer['throttled'] = $response->getThrottleMetadata();
			}
		} elseif ($response instanceof TemplateResponse) {
			$answer['template'] = $response->getTemplateName();
			$params = $response->getParams();
			if (is_string($params['target'] ?? null)) {
				$params['target'] = self::url($params['target']);
			}
			ksort($params);
			$answer['params'] = $params;
		} elseif ($response instanceof RedirectResponse) {
			$answer['redirect'] = $response->getRedirectURL();
		} elseif ($response instanceof DataDisplayResponse) {
			$answer['body'] = $response->getData();
		} else {
			throw new \LogicException('Unexpected response ' . $response::class);
		}
		$headers = array_intersect_key(self::headers($response), array_flip(self::HEADERS));
		ksort($headers);
		if ($headers !== []) {
			$answer['headers'] = $headers;
		}
		return $answer;
	}

	/**
	 * @param string $url a client callback URL
	 * @return array{url:string, query:array<string, mixed>} the URL without its query, and the query decoded
	 */
	public static function url(string $url): array {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
		return ['url' => (string)strtok($url, '?'), 'query' => $query];
	}

	/**
	 * @param Response $response a controller answer
	 * @return array<string, string> the headers it set, read without the server container getHeaders() needs
	 */
	private static function headers(Response $response): array {
		return (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}
}
