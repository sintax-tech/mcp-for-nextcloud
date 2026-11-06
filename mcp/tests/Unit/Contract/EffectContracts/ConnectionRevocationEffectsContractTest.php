<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\ConnectionsController;
use OCA\Mcp\Controller\MyConnectionsController;
use OCA\Mcp\Controller\SettingsController;
use OCA\Mcp\Modules\Files\Reorganization\Batch;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the ways a connection ends (RE-01): a user revoking one of their own connections
 * (`my_connections#destroy`), an administrator revoking one or all of a user's (`connections#destroy`,
 * `connections#revokeUser`) and the personal switch (`settings#personal`). Each is frozen in `effects/*.json` with the
 * OAuth store and the policy, and each revoked token is presented again to the bearer authentication of the MCP
 * endpoint: a revocation that answers success but leaves a working token fails here.
 */
final class ConnectionRevocationEffectsContractTest extends TestCase {
	private OAuthEffectsRig $rig;
	private ?ControllerTranscript $transcript = null;
	/** @var list<string> tokens issued before the transcript started, registered when it starts */
	private array $issued = [];

	/** @return void builds the instance: service on, alice and bob eligible */
	protected function setUp(): void {
		$this->rig = new OAuthEffectsRig($this);
		$this->rig->policy->setEligible('bob', true);
	}

	/**
	 * Starts the transcript of a flow once its fixture is in place, so its initial state is the fixture.
	 *
	 * @param string $name golden file name
	 * @return void
	 */
	private function start(string $name): void {
		$this->transcript = new ControllerTranscript($this, $name, fn (): array => $this->rig->state());
		foreach ($this->issued as $secret) {
			$this->rig->secret($this->transcript, $secret);
		}
	}

	/**
	 * Connects a user through the real endpoints; the issued tokens are hidden in the transcript.
	 *
	 * @param string $uid an eligible user
	 * @return string the access token
	 */
	private function connect(string $uid): string {
		$body = $this->rig->connect($uid);
		foreach ([$body['access_token'], $body['refresh_token']] as $secret) {
			if ($this->transcript === null) {
				$this->issued[] = $secret;
			} else {
				$this->rig->secret($this->transcript, $secret);
			}
		}
		return $body['access_token'];
	}

	/**
	 * @param string $uid owner
	 * @return int id of the user's newest grant
	 */
	private function grantOf(string $uid): int {
		return (int)max(array_keys(array_filter($this->rig->store->tokens, static fn (array $row): bool => $row['user_id'] === $uid)));
	}

	/**
	 * @param string $step what the step proves
	 * @param string $route route name
	 * @param string $user signed-in user, '' for none
	 * @param array<string, mixed> $params what the client sent
	 * @param Response $response the answer
	 * @return array<string, mixed> the recorded answer
	 */
	private function step(string $step, string $route, string $user, array $params, Response $response): array {
		return $this->transcript->record($step, ['route' => $route, 'user' => $user, 'params' => $params], $response);
	}

	/** @return void the personal routes need a login only, the administrator's ones keep the admin-only default */
	public function testRouteAttributes(): void {
		foreach (['index', 'destroy'] as $method) {
			$attributes = array_map(static fn ($a): string => $a->getName(), (new \ReflectionMethod(MyConnectionsController::class, $method))->getAttributes());
			$this->assertSame([NoAdminRequired::class], $attributes, $method);
		}
		foreach (['index', 'destroy', 'revokeUser'] as $method) {
			$this->assertSame([], (new \ReflectionMethod(ConnectionsController::class, $method))->getAttributes(), $method);
		}
		$this->assertSame([NoAdminRequired::class], array_map(static fn ($a): string => $a->getName(),
			(new \ReflectionMethod(SettingsController::class, 'personal'))->getAttributes()));
	}

	/** @return void a user revokes their own connection, never someone else's, and the token stops working */
	public function testAUserRevokesTheirOwnConnection(): void {
		$alice = $this->connect('alice');
		$bob = $this->connect('bob');
		[$aliceGrant, $bobGrant] = [$this->grantOf('alice'), $this->grantOf('bob')];
		$this->start('oauth.my-connections-revoke');

		$list = $this->step('alice lists only her own', 'my_connections#index', 'alice', [], $this->rig->mine('alice')->index());
		$this->assertSame([$aliceGrant], array_column($list['json']['connections'], 'id'));
		$this->step("bob's connection is not found for alice", 'my_connections#destroy', 'alice', ['id' => $bobGrant],
			$this->rig->mine('alice')->destroy($bobGrant));
		$this->step('an invalid id is not found', 'my_connections#destroy', 'alice', ['id' => 0], $this->rig->mine('alice')->destroy(0));
		$this->step('nobody signed in: not found', 'my_connections#destroy', '', ['id' => $aliceGrant], $this->rig->mine('')->destroy($aliceGrant));
		$answer = $this->step('policy unavailable: 503 and the token stays', 'my_connections#destroy', 'alice', ['id' => $aliceGrant],
			UnusableWriter::during($this->rig->writer, fn () => $this->rig->mine('alice')->destroy($aliceGrant)));
		$this->assertSame(503, $answer['status']);
		$this->assertSame(['alice', 'bob'], [$this->rig->bearer($alice), $this->rig->bearer($bob)], 'no refusal revoked anything');

		$answer = $this->step('alice revokes her connection', 'my_connections#destroy', 'alice', ['id' => $aliceGrant],
			$this->rig->mine('alice')->destroy($aliceGrant));
		$this->assertSame([200, ['revoked' => true]], [$answer['status'], $answer['json']]);
		$this->assertArrayNotHasKey($aliceGrant, $this->rig->store->tokens, 'the grant row is deleted');
		$this->assertNull($this->rig->bearer($alice), 'the revoked token no longer authenticates');
		$this->assertSame('bob', $this->rig->bearer($bob), "bob's connection is untouched");
		$this->step('revoking again is not found', 'my_connections#destroy', 'alice', ['id' => $aliceGrant], $this->rig->mine('alice')->destroy($aliceGrant));
		$this->assertSame('1', $this->rig->data->user['alice']['mcp']['connected'], 'revoking one connection keeps the personal switch on');
		$this->transcript->assertGolden();
	}

	/** @return void an administrator revokes one connection, or every connection of a user */
	public function testAnAdministratorRevokesConnections(): void {
		$first = $this->connect('alice');
		$second = $this->connect('alice');
		$bob = $this->connect('bob');
		$bobGrant = $this->grantOf('bob');
		$this->start('oauth.admin-connections-revoke');

		$this->step('the list shows every user', 'connections#index', 'admin', [], $this->rig->admin()->index());
		$answer = $this->step("revoking bob's connection", 'connections#destroy', 'admin', ['id' => $bobGrant], $this->rig->admin()->destroy($bobGrant));
		$this->assertSame([200, ['revoked' => true, 'counts' => ['connections' => 2]]], [$answer['status'], $answer['json']]);
		$this->assertNull($this->rig->bearer($bob));
		$this->step('an unknown connection is not found', 'connections#destroy', 'admin', ['id' => 999], $this->rig->admin()->destroy(999));
		$this->step('an empty user id is invalid', 'connections#revokeUser', 'admin', ['uid' => ''], $this->rig->admin()->revokeUser(''));
		$long = str_repeat('u', 65);
		$this->step('an oversized user id is invalid', 'connections#revokeUser', 'admin', ['uid' => $long], $this->rig->admin()->revokeUser($long));
		$this->step('policy unavailable: 503 and nothing revoked', 'connections#revokeUser', 'admin', ['uid' => 'alice'],
			UnusableWriter::during($this->rig->writer, fn () => $this->rig->admin()->revokeUser('alice')));
		$this->assertSame(['alice', 'alice'], [$this->rig->bearer($first), $this->rig->bearer($second)]);

		$answer = $this->step('revoking every connection of alice', 'connections#revokeUser', 'admin', ['uid' => 'alice'],
			$this->rig->admin()->revokeUser('alice'));
		$this->assertSame([200, ['revoked' => true, 'counts' => ['connections' => 0]]], [$answer['status'], $answer['json']]);
		$this->assertSame([null, null], [$this->rig->bearer($first), $this->rig->bearer($second)], 'both tokens of alice stop working');
		$this->assertSame(['1', '1'], [$this->rig->data->user['alice']['mcp']['eligible'], $this->rig->data->user['alice']['mcp']['connected']],
			'alice stays eligible and connected, so a new consent works');
		$this->assertNotNull($this->connect('alice'), 'a new consent after the cut issues a working token');
		$this->transcript->assertGolden();
	}

	/** @return void the personal switch: off revokes every credential, link and batch of the user; on needs eligibility */
	public function testThePersonalSwitch(): void {
		$alice = $this->connect('alice');
		$bob = $this->connect('bob');
		foreach (['alice', 'bob'] as $n => $uid) {
			$this->rig->checkout->insert(['token_hash' => 'checkout-' . $uid, 'kind' => 'download', 'user_id' => $uid, 'file_id' => $n + 1,
				'path' => '/a.md', 'etag' => 'e1', 'scope' => '', 'shared_confirmed' => 0, 'created_at' => $this->rig->data->now,
				'expires_at' => $this->rig->data->now + 600], $this->rig->data->now);
			$this->rig->batches->insert(new Batch(null, $uid, $this->rig->data->now, [['from' => '/a.md', 'to' => '/B/a.md', 'toId' => 7]], ['/B'], null));
		}
		$this->start('settings.personal');

		$this->step('a value other than 0 or 1 is invalid', 'settings#personal', 'alice', ['enabled' => 'yes'], $this->rig->settings('alice')->personal('yes'));
		$this->step('nobody signed in is invalid', 'settings#personal', '', ['enabled' => '0'], $this->rig->settings('')->personal('0'));
		$this->step('policy unavailable: 503 and nothing changes', 'settings#personal', 'alice', ['enabled' => '0'],
			UnusableWriter::during($this->rig->writer, fn () => $this->rig->settings('alice')->personal('0')));
		$this->assertSame('alice', $this->rig->bearer($alice));
		$this->assertCount(2, $this->rig->checkout->rows);

		$answer = $this->step('alice switches off', 'settings#personal', 'alice', ['enabled' => '0'], $this->rig->settings('alice')->personal('0'));
		$this->assertSame(303, $answer['status']);
		$this->assertSame('0', $this->rig->data->user['alice']['mcp']['connected']);
		$this->assertNull($this->rig->bearer($alice), 'switching off revokes the token');
		$this->assertSame(['bob'], array_values(array_column($this->rig->checkout->rows, 'user_id')), "alice's checkout links are gone");
		$this->assertSame(['bob'], array_values(array_column($this->rig->batches->rows, 'user_id')), "alice's undo batches are gone");
		$this->assertSame('bob', $this->rig->bearer($bob), "bob's connection is untouched");

		$this->step('alice switches on again', 'settings#personal', 'alice', ['enabled' => '1'], $this->rig->settings('alice')->personal('1'));
		$this->assertSame('1', $this->rig->data->user['alice']['mcp']['connected']);
		$this->assertNull($this->rig->bearer($alice), 'switching on does not bring a revoked token back');
		$this->rig->policy->setEligible('bob', false);
		$this->step('eligibility of bob withdrawn by the administrator; bob cannot switch on', 'settings#personal', 'bob', ['enabled' => '1'],
			$this->rig->settings('bob')->personal('1'));
		$this->transcript->assertGolden();
	}
}
