<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\ClientMetadataDownloader;
use OCA\Mcp\OAuth\OAuthException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The client metadata download keeps one total time budget (connection and body), fails with a clear message when it
 * runs out, and remembers a failure briefly for that client_id only.
 */
final class ClientMetadataDownloaderTest extends TestCase {
	private const CLIENT = 'https://claude.ai/client';
	private const OTHER = 'https://chatgpt.com/client';

	/** @var array<string,mixed> contents of the fake distributed cache */
	private array $stored = [];

	/** @var list<string> client_ids requested over HTTP, in order */
	private array $requested = [];

	/** @var list<array<string,mixed>> options of every HTTP request, in order */
	private array $options = [];

	private float $now = 1000.0;

	/**
	 * @return ICache a cache backed by $this->stored
	 */
	private function cache(): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->stored[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->stored[$key] = $value;
			return true;
		});
		return $cache;
	}

	/**
	 * @param \Closure(string): IResponse $answer what the server does for each requested client_id
	 * @param int $seconds total budget
	 * @param bool $fakeClock true to use $this->now as the clock, false for the real clock
	 * @return ClientMetadataDownloader the downloader under test
	 */
	private function downloader(\Closure $answer, int $seconds = 5, bool $fakeClock = true): ClientMetadataDownloader {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $uri, array $options) use ($answer): IResponse {
			$this->requested[] = $uri;
			$this->options[] = $options;
			return $answer($uri);
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return new ClientMetadataDownloader($service, $this->cache(), $seconds,
			$fakeClock ? fn (): float => $this->now : null);
	}

	/**
	 * @param resource $body response body stream
	 * @return IResponse a 200 response without Content-Length
	 */
	private function response($body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeader')->with('Content-Length')->willReturn('');
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	/**
	 * @param string $content bytes of the stream
	 * @return resource a rewound memory stream
	 */
	private static function memory(string $content) {
		$stream = fopen('php://memory', 'w+');
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	/**
	 * @param ClientMetadataDownloader $downloader downloader under test
	 * @param string $clientId client_id to download
	 * @return OAuthException the refusal
	 */
	private function refusal(ClientMetadataDownloader $downloader, string $clientId = self::CLIENT): OAuthException {
		try {
			$downloader->download($clientId);
		} catch (OAuthException $e) {
			return $e;
		}
		$this->fail('the download was accepted');
	}

	/**
	 * A document that arrives in time is returned unchanged, with the budget passed as both the connection and the total
	 * limit, redirects off, and no failure entry left behind.
	 */
	public function testTheBudgetBoundsTheConnectionAndTheWholeTransfer(): void {
		$document = '{"client_id":"' . self::CLIENT . '"}';
		$this->assertSame($document, $this->downloader(fn () => $this->response(self::memory($document)))->download(self::CLIENT));

		$this->assertSame(5, $this->options[0]['timeout']);
		$this->assertSame(5, $this->options[0]['connect_timeout']);
		$this->assertFalse($this->options[0]['allow_redirects']);
		$this->assertTrue($this->options[0]['stream']);
		$this->assertSame([], array_filter(array_keys($this->stored), static fn (string $key) => str_starts_with($key, 'failed-')),
			'a successful download leaves no failure entry');
	}

	/** A connection that runs out the budget is refused as invalid_client, never redirectable, with the clear timeout message. */
	public function testAConnectionThatTimesOutShowsTheTimeoutMessage(): void {
		$downloader = $this->downloader(function (): IResponse {
			$this->now += 5.01; // curl gave up at its total limit
			throw new RuntimeException('cURL error 28: Connection timed out after 5001 milliseconds');
		});

		$e = $this->refusal($downloader);

		$this->assertSame('invalid_client', $e->error);
		$this->assertFalse($e->redirectable);
		$this->assertSame(ClientMetadataDownloader::TIMEOUT_MESSAGE, $e->getMessage());
	}

	/** A failure that comes back before the budget ends keeps the generic message instead of the timeout one. */
	public function testAFailureBeforeTheBudgetEndsIsNotCalledATimeout(): void {
		$downloader = $this->downloader(function (): IResponse {
			$this->now += 0.2;
			throw new RuntimeException('cURL error 7: Connection refused');
		});

		$this->assertSame(ClientMetadataDownloader::UNAVAILABLE_MESSAGE, $this->refusal($downloader)->getMessage());
	}

	/** A body that keeps arriving cannot stretch the wait: every read counts against the same budget. */
	public function testABodyThatKeepsArrivingIsCutAtTheBudget(): void {
		$stream = self::memory(str_repeat(' ', 65536));
		$ticks = 0;
		// Every look at the clock is 0.8 s later: each chunk arrives in time, the sum does not.
		$clock = function () use (&$ticks): float {
			return $this->now + 0.8 * $ticks++;
		};
		$downloader = new ClientMetadataDownloader($this->serviceAnswering(fn () => $this->response($stream)), $this->cache(), 5, $clock);

		$e = $this->refusal($downloader);

		$this->assertSame(ClientMetadataDownloader::TIMEOUT_MESSAGE, $e->getMessage());
		$this->assertFalse(is_resource($stream), 'the body stream is closed');
	}

	/** A real socket that sends part of the body and then stalls is cut at the budget, not at a per-read timeout. */
	public function testAStalledSocketIsCutAtTheBudget(): void {
		[$server, $socket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		fwrite($server, '{"client_id":');
		$downloader = $this->downloader(fn () => $this->response($socket), 1, false);

		$started = hrtime(true);
		$e = $this->refusal($downloader);
		$elapsed = (hrtime(true) - $started) / 1e9;
		fclose($server);

		$this->assertSame(ClientMetadataDownloader::TIMEOUT_MESSAGE, $e->getMessage());
		$this->assertGreaterThanOrEqual(0.9, $elapsed);
		$this->assertLessThan(2.0, $elapsed);
		$this->assertFalse(is_resource($socket), 'the body stream is closed');
	}

	/** After a failure the same client_id is refused without a new request, while another client_id is still downloaded. */
	public function testAFailureIsRefusedAtOnceForThatClientOnly(): void {
		$downloader = $this->downloader(function (string $uri): IResponse {
			if ($uri === self::CLIENT) {
				$this->now += 5.01;
				throw new RuntimeException('cURL error 28');
			}
			return $this->response(self::memory('{"client_id":"' . self::OTHER . '"}'));
		});

		$this->refusal($downloader);
		$second = $this->refusal($downloader);
		$other = $downloader->download(self::OTHER);

		$this->assertSame([self::CLIENT, self::OTHER], $this->requested, 'the failed client is not requested again');
		$this->assertSame(ClientMetadataDownloader::TIMEOUT_MESSAGE, $second->getMessage());
		$this->assertSame('{"client_id":"' . self::OTHER . '"}', $other);
	}

	/** The failure entry uses the client_id key, holds only the fixed message, and lives for the short FAILURE_TTL. */
	public function testAFailureEntryExpiresQuicklyAndHoldsNoDocument(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects($this->once())->method('set')->with('failed-' . hash('sha256', self::CLIENT),
			ClientMetadataDownloader::UNAVAILABLE_MESSAGE, ClientMetadataDownloader::FAILURE_TTL);
		$downloader = new ClientMetadataDownloader($this->serviceAnswering(function (): IResponse {
			throw new RuntimeException('cURL error 6: Could not resolve host');
		}), $cache);

		$this->refusal($downloader);

		$this->assertLessThanOrEqual(60, ClientMetadataDownloader::FAILURE_TTL);
	}

	/** Only the two fixed messages count as a failure entry; anything else in that key is ignored. */
	public function testAnUnexpectedFailureEntryIsIgnored(): void {
		$this->stored['failed-' . hash('sha256', self::CLIENT)] = 'something else';
		$document = '{"client_id":"' . self::CLIENT . '"}';

		$this->assertSame($document, $this->downloader(fn () => $this->response(self::memory($document)))->download(self::CLIENT));
		$this->assertSame([self::CLIENT], $this->requested);
	}

	/**
	 * @param \Closure(string): IResponse $answer what the server does for each requested client_id
	 * @return IClientService a client service whose client answers with $answer
	 */
	private function serviceAnswering(\Closure $answer): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(fn (string $uri, array $options): IResponse => $answer($uri));
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return $service;
	}
}
