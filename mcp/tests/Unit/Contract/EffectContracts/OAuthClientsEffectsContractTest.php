<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\OAuth\Clients\CustomClient;
use OCA\Mcp\OAuth\Clients\NativeClient;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of `grants#updateOauthClients` (RE-01): the trusted hosts, the native client switch and the list of
 * self-hosted clients, frozen in `effects/grants.oauth-clients.json` with the clients table (generations included)
 * and the OAuth grants of every client. Withdrawing a client (turned off, or one of its redirect URIs removed) deletes
 * that client's grants in the same command; the grants of the other clients stay.
 */
final class OAuthClientsEffectsContractTest extends TestCase {
	private GrantsEffectsRig $rig;
	private ControllerTranscript $transcript;

	/** @return void two self-hosted clients and the native one, each with one grant of ana or bob */
	protected function setUp(): void {
		$this->rig = new GrantsEffectsRig($this);
		$this->rig->fx->clients->native(true);
		$this->rig->fx->clients->custom([
			['id' => 'aaaaaaaaaaaa', 'name' => 'Lab', 'enabled' => true, 'redirectUris' => ['https://lab.example.test/cb', 'https://lab.example.test/cb2']],
			['id' => 'bbbbbbbbbbbb', 'name' => 'Ops', 'enabled' => true, 'redirectUris' => ['https://ops.example.test/cb']],
		]);
		foreach ([['ana', CustomClient::clientId('aaaaaaaaaaaa')], ['bob', CustomClient::clientId('bbbbbbbbbbbb')], ['ana', NativeClient::CLIENT_ID]] as [$uid, $client]) {
			$this->rig->fx->oauth->insertToken(['user_id' => $uid, 'client_id' => $client], 0);
		}
	}

	/** @return array<string, mixed> hosts, clients as stored, and the grants by client */
	private function state(): array {
		return [
			'app' => $this->rig->fx->config->app['mcp'] ?? [],
			'revision' => $this->rig->fx->config->revision,
			'native' => $this->rig->fx->clients->nativeRow(),
			'custom' => $this->rig->fx->clients->stored(),
			'cimdHosts' => $this->rig->fx->clients->mapper->cimdHosts()->all(),
			'grants' => array_values(array_map(static fn (array $row): string => $row['user_id'] . ' @ ' . $row['client_id'], $this->rig->fx->oauth->tokens)),
		];
	}

	/**
	 * @param string $step what the step proves
	 * @param array<string, mixed> $body JSON body
	 * @param bool $unusable whether the policy writer is unusable
	 * @return array<string, mixed> the recorded answer
	 */
	private function send(string $step, array $body, bool $unusable = false): array {
		return $this->rig->send($this->transcript, $step, 'grants#updateOauthClients', $body,
			static fn (GrantsController $c): Response => $c->updateOauthClients(), $unusable);
	}

	/**
	 * @param bool $opsEnabled whether Ops stays on
	 * @param list<string> $labUris redirect URIs of Lab
	 * @return list<array<string, mixed>> the whole list of self-hosted clients, as the page sends it
	 */
	private static function custom(bool $opsEnabled, array $labUris): array {
		return [['id' => 'aaaaaaaaaaaa', 'name' => 'Lab', 'enabled' => true, 'redirectUris' => $labUris],
			['id' => 'bbbbbbbbbbbb', 'name' => 'Ops', 'enabled' => $opsEnabled, 'redirectUris' => ['https://ops.example.test/cb']]];
	}

	/** @return void hosts saved, a client turned off and a URI removed: their grants go, the others stay */
	public function testUpdateOauthClients(): void {
		$this->transcript = new ControllerTranscript($this, 'grants.oauth-clients', fn (): array => $this->state());
		$both = ['https://lab.example.test/cb', 'https://lab.example.test/cb2'];
		$this->send('an empty body is invalid', []);
		$this->send('an empty host list is invalid', ['hosts' => []]);
		$this->send('a host with a scheme is invalid', ['hosts' => ['https://claude.ai']]);
		$this->send('without expectedRevision: 428', ['hosts' => ['claude.ai'], 'expectedRevision' => null]);
		$this->send('a stale revision: 409', ['hosts' => ['claude.ai'], 'expectedRevision' => '1']);
		$answer = $this->send('policy unavailable: 503, nothing changes', ['customClients' => self::custom(false, $both)], true);
		$this->assertSame(503, $answer['status']);
		$this->assertCount(5, $this->rig->fx->oauth->tokens, 'no refusal deleted a grant');

		$answer = $this->send('trusted hosts saved', ['hosts' => ['Claude.ai ', 'chatgpt.com']]);
		$this->assertSame(['claude.ai', 'chatgpt.com'], $answer['json']['hosts']);
		$answer = $this->send('Ops turned off: its grants are deleted', ['customClients' => self::custom(false, $both)]);
		$this->assertSame(200, $answer['status']);
		$this->assertNotContains(CustomClient::clientId('bbbbbbbbbbbb'), array_column($this->rig->fx->oauth->tokens, 'client_id'));
		$this->assertSame('2', $this->rig->fx->clients->stored()[1]['gen'], 'turning off advances the generation');
		$this->send('a redirect URI of Lab removed: its grants are deleted', ['customClients' => self::custom(false, [$both[0]])]);
		$this->send('the native client turned off: its grants are deleted', ['nativeClientEnabled' => false]);
		$this->assertSame(['https://claude.ai/c', 'https://claude.ai/c'], array_values(array_column($this->rig->fx->oauth->tokens, 'client_id')),
			'only the grants of the discovered (CIMD) client remain');
		$this->assertFalse($this->rig->fx->clients->nativeRow());
		$this->send('CIMD host withdrawn: its grants are deleted', ['hosts' => ['chatgpt.com']]);
		$this->assertSame([], $this->rig->fx->oauth->tokens);
		$this->send('CIMD host re-added: old grants never return', ['hosts' => ['claude.ai', 'chatgpt.com']]);
		$this->assertSame([], $this->rig->fx->oauth->tokens);
		$this->rig->register($this->transcript);
		$this->transcript->assertGolden();
	}
}
