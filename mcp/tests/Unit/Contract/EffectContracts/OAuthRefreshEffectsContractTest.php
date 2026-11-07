<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use PHPUnit\Framework\TestCase;

/**
 * Effect contract of `o_auth#token` with `grant_type=refresh_token` (RE-01): rotation (the old refresh token and
 * access token stop working, the new ones work), the replay of a spent refresh token (the whole family of that user
 * and client is revoked), a wrong client, a missing client_id and a policy failure, frozen in
 * `effects/oauth.refresh-token.json` with the OAuth store, including its spent-token records.
 */
final class OAuthRefreshEffectsContractTest extends TestCase {
	/** A second self-hosted client id, never registered. */
	private const OTHER_CLIENT = 'nextcloud-mcp-custom-bbbbbbbbbbbb';

	private OAuthEffectsRig $rig;
	private ControllerTranscript $transcript;

	/** @return void builds the instance: service on, alice eligible */
	protected function setUp(): void {
		$this->rig = new OAuthEffectsRig($this);
	}

	/**
	 * @param string $refresh a refresh token
	 * @param string $client the client_id sent
	 * @return array<string, string> the parameters of a refresh request
	 */
	private static function refresh(string $refresh, string $client = OAuthEffectsRig::CLIENT): array {
		return ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $client];
	}

	/**
	 * Sends one token request and records it.
	 *
	 * @param string $step what the step proves
	 * @param array<string, string> $params form parameters
	 * @param bool $unusable whether the policy writer is unusable during the request
	 * @return array<string, mixed> the recorded answer
	 */
	private function token(string $step, array $params, bool $unusable = false): array {
		$call = fn () => $this->rig->oauth('', $params)->token();
		return $this->transcript->record($step, ['route' => 'o_auth#token', 'user' => '', 'params' => $params],
			$unusable ? UnusableWriter::during($this->rig->writer, $call) : $call());
	}

	/** @return void rotation, then the replay of the spent token revokes the rotated family */
	public function testRotationAndReplay(): void {
		$first = $this->rig->connect('alice');
		$this->transcript = new ControllerTranscript($this, 'oauth.refresh-token', fn (): array => $this->rig->state());
		[$access, $refresh] = [$this->rig->secret($this->transcript, $first['access_token']), $this->rig->secret($this->transcript, $first['refresh_token'])];
		$row = array_values($this->rig->store->tokens)[0];

		$this->token('without client_id: invalid_request', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
		$answer = $this->token('another client: invalid_grant, throttled', self::refresh($refresh, self::OTHER_CLIENT));
		$this->assertSame([400, 'invalid_grant', ['action' => 'mcp_oauth_token']], [$answer['status'], $answer['json']['error'], $answer['throttled']]);
		$answer = $this->token('policy unavailable: 503, retry later', self::refresh($refresh), true);
		$this->assertSame([503, '1'], [$answer['status'], $answer['headers']['Retry-After']]);
		$this->assertSame([$row], array_values($this->rig->store->tokens), 'no refusal touched the grant');
		$this->assertSame('alice', $this->rig->bearer($access));

		$answer = $this->token('rotation issues a new pair', self::refresh($refresh));
		$this->assertSame(200, $answer['status']);
		$newAccess = $this->rig->secret($this->transcript, (string)$answer['json']['access_token']);
		$newRefresh = $this->rig->secret($this->transcript, (string)$answer['json']['refresh_token']);
		$this->assertSame([null, 'alice'], [$this->rig->bearer($access), $this->rig->bearer($newAccess)], 'the old access token is replaced');
		$this->assertSame([$this->rig->hasher->hash($newRefresh)], array_values(array_column($this->rig->store->tokens, 'refresh_hash')));
		$this->assertSame([$this->rig->hasher->hash($refresh)], array_values(array_column($this->rig->store->spent, 'refresh_hash')),
			'the old refresh token is recorded as spent');

		$answer = $this->token('replaying the spent refresh token revokes the family', self::refresh($refresh));
		$this->assertSame([400, 'invalid_grant'], [$answer['status'], $answer['json']['error']]);
		$this->assertSame([[], []], [$this->rig->store->tokens, $this->rig->store->spent], 'every grant of alice for this client is gone');
		$this->assertNull($this->rig->bearer($newAccess), 'the rotated access token stops working');
		$answer = $this->token('the rotated refresh token is dead too', self::refresh($newRefresh));
		$this->assertSame([400, 'invalid_grant'], [$answer['status'], $answer['json']['error']]);
		$this->transcript->assertGolden();
	}
}
