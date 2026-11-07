<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\OAuthController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the OAuth consent and token endpoints (RE-01): what `o_auth#authorize`, `o_auth#consent` and
 * `o_auth#token` leave in the session, the policy and the OAuth store, frozen in `effects/oauth.*.json`. The secrets
 * the code draws stay in the store; the transcript shows placeholders once their shape is asserted, and the tokens
 * are checked where they matter, at the bearer authentication of the MCP endpoint.
 */
final class OAuthControllerEffectsContractTest extends TestCase {
	private OAuthEffectsRig $rig;
	private ?ControllerTranscript $transcript = null;

	/** @return void builds the instance: service on, alice eligible, bob not */
	protected function setUp(): void {
		$this->rig = new OAuthEffectsRig($this);
	}

	/**
	 * Sends one request and records it.
	 *
	 * @param string $step what the step proves
	 * @param string $route route name of appinfo/routes.php
	 * @param string $user signed-in user, '' for none
	 * @param array<string, mixed> $params what the client sent
	 * @param \Closure(OAuthController): \OCP\AppFramework\Http\Response $call the controller method
	 * @return array<string, mixed> the recorded answer
	 */
	private function send(string $step, string $route, string $user, array $params, \Closure $call): array {
		$query = $route === 'o_auth#consent' ? [] : $params;
		return $this->transcript->record($step, ['route' => $route, 'user' => $user, 'params' => $params],
			$call($this->rig->oauth($user, $query)));
	}

	/**
	 * @param string $user who opens the consent page
	 * @param string $step what the step proves
	 * @return string|null the pending id the page shows, registered in the transcript
	 */
	private function authorize(string $user, string $step): ?string {
		$this->send($step, 'o_auth#authorize', $user, OAuthEffectsRig::authorization(), static fn (OAuthController $c) => $c->authorize());
		return $this->rig->pending() === null ? null : $this->rig->pendingId($this->transcript);
	}

	/** @return void the routes keep their framework attributes: consent needs a login and CSRF, token is public */
	public function testTheEndpointsKeepTheirAccessAttributes(): void {
		$names = static fn (string $method): array => array_map(static fn ($a): string => $a->getName(),
			(new \ReflectionMethod(OAuthController::class, $method))->getAttributes());
		$this->assertContains(NoAdminRequired::class, $names('consent'));
		$this->assertNotContains(NoCSRFRequired::class, $names('consent'));
		$this->assertNotContains(PublicPage::class, $names('consent'));
		$this->assertNotContains(PublicPage::class, $names('authorize'));
		$this->assertContains(PublicPage::class, $names('token'));
	}

	/** @return void allow: the consent connects alice and stores one code, whose exchange issues working tokens once */
	public function testAnAcceptedConsentIssuesACodeThatExchangesForWorkingTokens(): void {
		$this->transcript = new ControllerTranscript($this, 'oauth.consent-token', fn (): array => $this->rig->state());
		$pending = $this->authorize('alice', 'consent page keeps the request in the session only');
		$this->assertNotNull($pending);
		$this->assertSame([], $this->rig->store->codes, 'showing the page issues nothing');

		$answer = $this->send('allow issues a code and connects alice', 'o_auth#consent', 'alice',
			['pending' => $pending, 'decision' => 'allow'], static fn (OAuthController $c) => $c->consent($pending, 'allow'));
		$code = $this->rig->secret($this->transcript, (string)($answer['params']['target']['query']['code'] ?? ''));
		$this->assertSame(OAuthEffectsRig::REDIRECT, $answer['params']['target']['url']);
		$this->assertSame(['st-1', OAuthEffectsRig::ISSUER], [$answer['params']['target']['query']['state'], $answer['params']['target']['query']['iss']]);
		$this->assertSame('1', $this->rig->data->user['alice']['mcp']['connected'] ?? null, 'consent is the personal activation');
		$this->assertSame([$this->rig->hasher->hash($code)], array_values(array_column($this->rig->store->codes, 'code_hash')));
		$this->assertSame(['alice', OAuthEffectsRig::CLIENT, OAuthEffectsRig::CHALLENGE],
			[...array_values(array_intersect_key(array_values($this->rig->store->codes)[0], array_flip(['user_id', 'client_id', 'code_challenge'])))]);

		$this->send('an unsupported grant type writes nothing', 'o_auth#token', '', ['grant_type' => 'password'],
			static fn (OAuthController $c) => $c->token());
		$this->send('a wrong verifier spends the code and issues nothing', 'o_auth#token', '',
			OAuthEffectsRig::exchange($code, str_repeat('x', 43)), static fn (OAuthController $c) => $c->token());
		$this->assertSame([], $this->rig->store->codes);
		$this->assertSame([], $this->rig->store->tokens);

		$pending = (string)$this->authorize('alice', 'a second consent page');
		$answer = $this->send('allow again issues a fresh code', 'o_auth#consent', 'alice', ['pending' => $pending, 'decision' => 'allow'],
			static fn (OAuthController $c) => $c->consent($pending, 'allow'));
		$code = $this->rig->secret($this->transcript, (string)$answer['params']['target']['query']['code']);
		$answer = $this->send('the exchange issues one grant', 'o_auth#token', '', OAuthEffectsRig::exchange($code),
			static fn (OAuthController $c) => $c->token());
		$this->assertSame(200, $answer['status']);
		$access = $this->rig->secret($this->transcript, (string)$answer['json']['access_token']);
		$this->rig->secret($this->transcript, (string)$answer['json']['refresh_token']);
		$this->assertSame(['Bearer', 3600, 'mcp'], [$answer['json']['token_type'], $answer['json']['expires_in'], $answer['json']['scope']]);
		$this->assertSame([$this->rig->hasher->hash($access)], array_values(array_column($this->rig->store->tokens, 'access_hash')));
		$this->assertSame('alice', $this->rig->bearer($access), 'the issued token authenticates at the MCP endpoint');

		$answer = $this->send('a replayed code is refused and throttled', 'o_auth#token', '', OAuthEffectsRig::exchange($code),
			static fn (OAuthController $c) => $c->token());
		$this->assertSame([400, 'invalid_grant'], [$answer['status'], $answer['json']['error']]);
		$this->assertCount(1, $this->rig->store->tokens, 'a replay issues nothing');
		$this->transcript->assertGolden();
	}

	/**
	 * @return void deny, a user who is not eligible, an eligibility withdrawn between the page and the click, an
	 *     expired request and an unusable policy: no code, no connection, nothing in the store
	 */
	public function testARefusedConsentWritesNothing(): void {
		$this->transcript = new ControllerTranscript($this, 'oauth.consent-refused', fn (): array => $this->rig->state());
		$this->assertNull($this->authorize('bob', 'a user who is not eligible gets the blocked page'));

		$pending = (string)$this->authorize('alice', 'consent page');
		$answer = $this->send('deny returns access_denied to the client', 'o_auth#consent', 'alice',
			['pending' => $pending, 'decision' => 'deny'], static fn (OAuthController $c) => $c->consent($pending, 'deny'));
		$this->assertSame('access_denied', $answer['params']['target']['query']['error']);

		$pending = (string)$this->authorize('alice', 'consent page');
		$this->rig->policy->setEligible('alice', false);
		$answer = $this->send('eligibility withdrawn after the page was shown (by the administrator): blocked', 'o_auth#consent', 'alice',
			['pending' => $pending, 'decision' => 'allow'], static fn (OAuthController $c) => $c->consent($pending, 'allow'));
		$this->assertTrue($answer['params']['blocked']);
		$this->assertArrayNotHasKey('code', $answer['params']['target']['query'] ?? []);
		$this->rig->policy->setEligible('alice', true);

		$this->send('eligibility given back; an unknown pending request is an error page', 'o_auth#consent', 'alice', ['pending' => 'guess', 'decision' => 'allow'],
			static fn (OAuthController $c) => $c->consent('guess', 'allow'));
		$answer = $this->transcript->record('policy unavailable: the page says so and keeps nothing',
			['route' => 'o_auth#authorize', 'user' => 'alice', 'params' => OAuthEffectsRig::authorization()],
			UnusableWriter::during($this->rig->writer, fn () => $this->rig->oauth('alice', OAuthEffectsRig::authorization())->authorize()));
		$this->assertTrue($answer['params']['unavailable']);
		$this->send('an invented code is refused and throttled', 'o_auth#token', '', OAuthEffectsRig::exchange('not-a-code'),
			static fn (OAuthController $c) => $c->token());

		$this->assertSame([[], [], null], [$this->rig->store->codes, $this->rig->store->tokens, $this->rig->pending()]);
		$this->assertArrayNotHasKey('connected', $this->rig->data->user['alice']['mcp'] ?? [], 'no refusal connects alice');
		$this->assertArrayNotHasKey('bob', $this->rig->data->generations, 'no code was ever issued to bob');
		$this->transcript->assertGolden();
	}
}
