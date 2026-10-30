<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;

/**
 * Issue #19 through the real token endpoint: a public client identified only by an empty-password Basic header gets
 * its code and refresh token, while every binding, single use, rotation and replay revocation of TokenService stays.
 * No request carries `state`: it is the client's check of the callback, not part of the token exchange.
 */
final class TokenClientIdFlowTest extends TestCase {
	/**
	 * Asserts an OAuth error answer.
	 *
	 * @param array{status:int, body:array<string,mixed>} $answer the token endpoint answer
	 * @param string $error the expected OAuth error code
	 * @return void
	 */
	private function assertRefused(array $answer, string $error): void {
		$this->assertSame(400, $answer['status']);
		$this->assertSame($error, $answer['body']['error'] ?? null);
		$this->assertArrayNotHasKey('access_token', $answer['body']);
	}

	/**
	 * Asserts a token answer.
	 *
	 * @param array{status:int, body:array<string,mixed>} $answer the token endpoint answer
	 * @return void
	 */
	private function assertIssued(array $answer): void {
		$this->assertSame(200, $answer['status'], json_encode($answer['body']) ?: '');
		$this->assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'], array_keys($answer['body']));
	}

	/** @return void */
	public function testABasicOnlyCodeExchangeIsIssuedOnceAndItsReplayIsAnInvalidGrant(): void {
		$rig = new TokenEndpointRig($this);
		$code = $rig->code();
		$first = $rig->token(TokenEndpointRig::exchange($code), TokenEndpointRig::basic(TokenEndpointRig::CLIENT));
		$this->assertIssued($first);
		$this->assertSame('alice', $rig->authenticator->authenticate('Bearer ' . $first['body']['access_token'], TokenEndpointRig::RESOURCE)?->getUID());
		$this->assertRefused($rig->token(TokenEndpointRig::exchange($code), TokenEndpointRig::basic(TokenEndpointRig::CLIENT)), 'invalid_grant');
		$this->assertRefused($rig->token(TokenEndpointRig::exchange($code) + ['client_id' => TokenEndpointRig::CLIENT]), 'invalid_grant');
	}

	/** @return void */
	public function testABasicOnlyRefreshRotatesAndTheReplayOfTheOldTokenRevokesTheClientsCredentials(): void {
		$rig = new TokenEndpointRig($this);
		$header = TokenEndpointRig::basic(TokenEndpointRig::CLIENT);
		$first = $rig->token(TokenEndpointRig::exchange($rig->code()), $header);
		$this->assertIssued($first);
		$second = $rig->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['body']['refresh_token']], $header);
		$this->assertIssued($second);
		$this->assertNotSame($first['body']['refresh_token'], $second['body']['refresh_token']);
		$this->assertNull($rig->authenticator->authenticate('Bearer ' . $first['body']['access_token'], TokenEndpointRig::RESOURCE));
		$this->assertNotNull($rig->authenticator->authenticate('Bearer ' . $second['body']['access_token'], TokenEndpointRig::RESOURCE));
		$this->assertRefused($rig->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['body']['refresh_token']], $header), 'invalid_grant');
		// The existing replay rule: every credential of this owner and client is gone.
		$this->assertNull($rig->authenticator->authenticate('Bearer ' . $second['body']['access_token'], TokenEndpointRig::RESOURCE));
		$this->assertRefused($rig->token(['grant_type' => 'refresh_token', 'refresh_token' => $second['body']['refresh_token']], $header), 'invalid_grant');
	}

	/**
	 * Values of a present client_id key; with a valid Basic header each still gets today's 400.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function presentInvalid(): array {
		return ['empty string' => [''], 'array' => [[TokenEndpointRig::CLIENT]], 'null' => [null]];
	}

	/**
	 * @param mixed $value value of the present client_id key
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('presentInvalid')]
	public function testAPresentInvalidClientIdKeepsTheRefusalAndDoesNotSpendTheCredentials(mixed $value): void {
		$rig = new TokenEndpointRig($this);
		$header = TokenEndpointRig::basic(TokenEndpointRig::CLIENT);
		$code = $rig->code();
		$this->assertRefused($rig->token(TokenEndpointRig::exchange($code) + ['client_id' => $value], $header), 'invalid_request');
		$tokens = $rig->token(TokenEndpointRig::exchange($code) + ['client_id' => TokenEndpointRig::CLIENT]);
		$this->assertIssued($tokens);
		$refresh = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['body']['refresh_token']];
		$this->assertRefused($rig->token($refresh + ['client_id' => $value], $header), 'invalid_request');
		$this->assertIssued($rig->token($refresh + ['client_id' => TokenEndpointRig::CLIENT]));
	}

	/** @return void */
	public function testAPresentConflictingClientIdStillWinsOverTheHeader(): void {
		$rig = new TokenEndpointRig($this);
		$this->assertIssued($rig->token(TokenEndpointRig::exchange($rig->code()) + ['client_id' => TokenEndpointRig::CLIENT],
			TokenEndpointRig::basic(TokenEndpointRig::OTHER_CLIENT)));
	}

	/** @return void */
	public function testTheHeaderNeverReassignsAnotherClientsCode(): void {
		$rig = new TokenEndpointRig($this);
		$this->assertRefused($rig->token(TokenEndpointRig::exchange($rig->code()), TokenEndpointRig::basic(TokenEndpointRig::OTHER_CLIENT)), 'invalid_grant');
	}

	/** @return void */
	public function testAWrongVerifierWithTheHeaderIsAnInvalidGrant(): void {
		$rig = new TokenEndpointRig($this);
		$this->assertRefused($rig->token(TokenEndpointRig::exchange($rig->code(), 'x' . substr(TokenEndpointRig::VERIFIER, 1)),
			TokenEndpointRig::basic(TokenEndpointRig::CLIENT)), 'invalid_grant');
	}

	/** @return void */
	public function testARefreshTokenOfAnotherClientIsRefusedAndStaysUsableByItsOwner(): void {
		$rig = new TokenEndpointRig($this);
		$tokens = $rig->token(TokenEndpointRig::exchange($rig->code()), TokenEndpointRig::basic(TokenEndpointRig::CLIENT));
		$this->assertIssued($tokens);
		$refresh = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['body']['refresh_token']];
		$this->assertRefused($rig->token($refresh, TokenEndpointRig::basic(TokenEndpointRig::OTHER_CLIENT)), 'invalid_grant');
		$this->assertIssued($rig->token($refresh, TokenEndpointRig::basic(TokenEndpointRig::CLIENT)));
	}

	/**
	 * A secret never authenticates: if a non-empty password reaches the app (the core stops it first on the image tested),
	 * the request stays the current refusal and the code is not spent.
	 *
	 * @return void
	 */
	public function testANonEmptyPasswordIsNeverAcceptedAndTheCodeIsNotSpent(): void {
		$rig = new TokenEndpointRig($this);
		$code = $rig->code();
		$this->assertRefused($rig->token(TokenEndpointRig::exchange($code), TokenEndpointRig::basic(TokenEndpointRig::CLIENT, 'secret')), 'invalid_request');
		$this->assertIssued($rig->token(TokenEndpointRig::exchange($code) + ['client_id' => TokenEndpointRig::CLIENT]));
	}

	/** @return void */
	public function testWithoutHeaderAndClientIdTheAnswerIsTodaysInvalidRequest(): void {
		$rig = new TokenEndpointRig($this);
		$code = $rig->code();
		$this->assertRefused($rig->token(TokenEndpointRig::exchange($code)), 'invalid_request');
		$this->assertIssued($rig->token(TokenEndpointRig::exchange($code) + ['client_id' => TokenEndpointRig::CLIENT]));
	}
}
