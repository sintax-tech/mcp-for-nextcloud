<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\TokenClientId;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The strict parser of {@see TokenClientId}: a present client_id key always wins untouched, and only an exact Basic
 * header with an empty password identifies a public client when the key is absent (issue #19).
 */
final class TokenClientIdTest extends TestCase {
	/** Base parameters of a token request without a client_id key. */
	private const PARAMS = ['grant_type' => 'authorization_code', 'code' => 'c', 'code_verifier' => 'v', 'redirect_uri' => 'https://x.test/cb'];

	/**
	 * A request whose Authorization header is the given raw value.
	 *
	 * @param string $header raw Authorization header ('' when absent)
	 * @return IRequest the request double
	 */
	private function request(string $header): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => strcasecmp($name, 'Authorization') === 0 ? $header : '');
		return $request;
	}

	/**
	 * A Basic header from the raw (already form-encoded, not yet base64) credentials.
	 *
	 * @param string $raw the credentials before base64
	 * @return string the header value
	 */
	private static function basic(string $raw): string {
		return 'Basic ' . base64_encode($raw);
	}

	/**
	 * Headers that identify a client, with the client_id they must yield.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function accepted(): array {
		return [
			'plain id' => [self::basic('nextcloud-mcp-custom-61a862c61e30:'), 'nextcloud-mcp-custom-61a862c61e30'],
			'CIMD URL encoded' => [self::basic('https%3A%2F%2Fclaude.ai%2Foauth%2Fmcp-client-metadata:'), 'https://claude.ai/oauth/mcp-client-metadata'],
			'plus sign encoded' => [self::basic('a%2Bb:'), 'a+b'],
			'double escape decoded once' => [self::basic('a%253Ab:'), 'a%3Ab'],
			'lower-case scheme' => ['basic ' . base64_encode('id:'), 'id'],
			'upper-case scheme' => ['BASIC ' . base64_encode('id:'), 'id'],
			'several spaces after the scheme' => ['Basic   ' . base64_encode('id:'), 'id'],
		];
	}

	/**
	 * @param string $header raw Authorization header
	 * @param string $clientId expected client_id
	 * @return void
	 */
	#[DataProvider('accepted')]
	public function testAnEmptyPasswordBasicHeaderIdentifiesTheClientWhenTheKeyIsAbsent(string $header, string $clientId): void {
		$this->assertSame(self::PARAMS + ['client_id' => $clientId], TokenClientId::resolve(self::PARAMS, $this->request($header)));
	}

	/**
	 * Headers that must leave the parameters exactly as they were.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refused(): array {
		return [
			'no header' => [''],
			'invalid base64' => ['Basic !!!not-base64!!!'],
			'base64 with trailing garbage' => ['Basic ' . base64_encode('id:') . '*'],
			'trailing LF' => [self::basic('id:') . "\n"],
			'trailing CR' => [self::basic('id:') . "\r"],
			'trailing CRLF' => [self::basic('id:') . "\r\n"],
			'leading text' => ['X ' . self::basic('id:')],
			'scheme with suffix' => ['Basicx ' . base64_encode('id:')],
			'no space after the scheme' => ['Basic' . base64_encode('id:')],
			'another scheme' => ['Bearer ' . base64_encode('id:')],
			'two tokens' => [self::basic('id:') . ' extra'],
			'no raw colon' => [self::basic('id')],
			'two raw colons' => [self::basic('https://claude.ai/x:')],
			'password zero' => [self::basic('id:0')],
			'password space' => [self::basic('id: ')],
			'password plus is a space' => [self::basic('id:+')],
			'password encoded NUL' => [self::basic('id:%00')],
			'password encoded colon' => [self::basic('id:%3A')],
			'non-empty password' => [self::basic('id:secret')],
			'empty user' => [self::basic(':')],
			'lone percent in user' => [self::basic('a%:')],
			'invalid escape in user' => [self::basic('a%G0:')],
			'truncated escape in user' => [self::basic('a%4:')],
			'invalid escape in password' => [self::basic('id:%G0')],
		];
	}

	/**
	 * @param string $header raw Authorization header
	 * @return void
	 */
	#[DataProvider('refused')]
	public function testAnythingButAStrictEmptyPasswordBasicHeaderLeavesTheParametersUnchanged(string $header): void {
		$this->assertSame(self::PARAMS, TokenClientId::resolve(self::PARAMS, $this->request($header)));
	}

	/**
	 * Values a present client_id key may have; every one is returned untouched.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function present(): array {
		return [
			'empty string' => [''],
			'null' => [null],
			'empty array' => [[]],
			'array with the id' => [['nextcloud-mcp-custom-61a862c61e30']],
			'false' => [false],
			'zero int' => [0],
			'zero string' => ['0'],
			'conflicting string' => ['nextcloud-mcp-custom-000000000000'],
			'the same id' => ['nextcloud-mcp-custom-61a862c61e30'],
		];
	}

	/**
	 * @param mixed $value value of the present client_id key
	 * @return void
	 */
	#[DataProvider('present')]
	public function testAPresentClientIdKeyIsReturnedUnchangedWithoutReadingTheHeader(mixed $value): void {
		$params = self::PARAMS + ['client_id' => $value];
		$request = $this->createMock(IRequest::class);
		$request->expects($this->never())->method('getHeader');
		$this->assertSame($params, TokenClientId::resolve($params, $request));
	}

	/**
	 * The other parameters, client_secret included, are never touched.
	 *
	 * @return void
	 */
	public function testOtherParametersAreKeptAsTheyWere(): void {
		$params = ['grant_type' => 'refresh_token', 'refresh_token' => 'r', 'client_secret' => '', 'resource' => 'https://x.test/apps/mcp/'];
		$this->assertSame($params + ['client_id' => 'id'], TokenClientId::resolve($params, $this->request(self::basic('id:'))));
	}
}
