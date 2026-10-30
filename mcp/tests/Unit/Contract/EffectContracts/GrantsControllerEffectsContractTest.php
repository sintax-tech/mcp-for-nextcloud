<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\Controller\OverridePreviewController;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the administrator's permission API (RE-01): `grants#update`, `grants#bulk`, `grants#commands`
 * with `override_preview#index`, `grants#undo` and `grants#service`, frozen in `effects/grants.*.json` with the stored
 * policy, its receipts and audit events, the OAuth grants and the tools the real registry offers each user afterwards.
 * Every flow also holds its refusals (invalid input, missing or stale revision, no administrator signed in) and the
 * policy failure (the writer unusable: 503 and nothing changed).
 */
final class GrantsControllerEffectsContractTest extends TestCase {
	private GrantsEffectsRig $rig;
	private ControllerTranscript $transcript;

	/** @return void the fixture: service on, ana and bob eligible and connected, ana may edit notes */
	protected function setUp(): void {
		$this->rig = new GrantsEffectsRig($this);
	}

	/**
	 * @param string $name golden file name
	 * @return void starts the transcript of a flow
	 */
	private function start(string $name): void {
		$this->transcript = new ControllerTranscript($this, $name, fn (): array => $this->rig->state());
	}

	/**
	 * Sends one request to GrantsController and records it.
	 *
	 * @param string $step what the step proves
	 * @param string $route route name
	 * @param array<string, mixed> $body JSON body; the current revision is added unless it names one (null: none)
	 * @param \Closure(GrantsController): Response $call the controller method
	 * @param string $admin signed-in administrator, '' for nobody
	 * @return array<string, mixed> the recorded answer
	 */
	private function send(string $step, string $route, array $body, \Closure $call, string $admin = 'root'): array {
		return $this->transcript->record($step, ['route' => $route, 'user' => $admin,
			'body' => GrantsEffectsRig::withRevision($body, $this->rig->revision())], $call($this->rig->grants($body, $admin)));
	}

	/**
	 * The same request while the policy writer is unusable: it must answer 503 and change nothing.
	 *
	 * @param string $route route name
	 * @param array<string, mixed> $body JSON body
	 * @param \Closure(GrantsController): Response $call the controller method
	 * @return void
	 */
	private function unavailable(string $route, array $body, \Closure $call): void {
		$before = $this->rig->state();
		$answer = $this->send('policy unavailable: 503, nothing changes', $route, $body,
			fn (GrantsController $c): Response => UnusableWriter::during($this->rig->writer(), static fn (): Response => $call($c)));
		$this->assertSame(503, $answer['status']);
		$this->assertSame($before, $this->transcript->lastState());
	}

	/** @return void finishes the flow: registers the commands it stored and compares the golden */
	private function finish(): void {
		$this->rig->register($this->transcript);
		$this->transcript->assertGolden();
	}

	/** @return void every route keeps the admin-only, CSRF-protected default of the framework */
	public function testEveryRouteIsAdminOnly(): void {
		foreach (['update', 'bulk', 'commands', 'undo', 'service'] as $method) {
			$this->assertSame([], (new \ReflectionMethod(GrantsController::class, $method))->getAttributes(), $method);
		}
		$this->assertSame([], (new \ReflectionMethod(OverridePreviewController::class, 'index'))->getAttributes());
	}

	/** @return void one user's grant and eligibility: allowed, denied, refused, and seen at the tool list */
	public function testUpdateOneUser(): void {
		$this->start('grants.update');
		$edit = static fn (bool $granted): array => ['module' => 'notes', 'operation' => 'edit', 'granted' => $granted];
		$update = static fn (string $uid): \Closure => static fn (GrantsController $c): Response => $c->update($uid);

		$answer = $this->send('deny ana notes.edit', 'grants#update', $edit(false), $update('ana'));
		$this->assertSame([200, false], [$answer['status'], $answer['json']['grants']['notes']['edit']]);
		$this->assertFalse($this->rig->fx->policy->granted('ana', 'notes', 'edit'));
		$this->assertNotContains('notes_edit', $this->rig->tools('ana'), 'a revoked grant is gone from the tool list at once');
		$this->assertSame('0', $this->rig->fx->config->user['ana']['mcp']['grant_notes_edit']);

		$this->send('allow it again', 'grants#update', $edit(true), $update('ana'));
		$this->assertContains('notes_edit', $this->rig->tools('ana'));
		$this->send('an unknown user is invalid', 'grants#update', $edit(false), $update('ghost'));
		$this->send('eligibility and a grant at once is invalid', 'grants#update', $edit(false) + ['eligible' => false], $update('ana'));
		$this->send('a grant outside the catalog is invalid', 'grants#update', ['module' => 'notes', 'operation' => 'destroy', 'granted' => false], $update('ana'));
		$this->send('a non-boolean is invalid', 'grants#update', ['module' => 'notes', 'operation' => 'edit', 'granted' => 'false'], $update('ana'));
		$this->send('without expectedRevision: 428', 'grants#update', $edit(false) + ['expectedRevision' => null], $update('ana'));
		$this->send('a stale revision: 409 with the current row', 'grants#update', $edit(false) + ['expectedRevision' => '1'], $update('ana'));
		$this->send('nobody signed in: invalid', 'grants#update', $edit(false), $update('ana'), '');
		$this->unavailable('grants#update', $edit(false), $update('ana'));
		$this->assertContains('notes_edit', $this->rig->tools('ana'), 'no refusal changed the grant');

		$this->send('ana loses eligibility', 'grants#update', ['eligible' => false], $update('ana'));
		$this->assertSame(['bob'], array_values(array_column($this->rig->fx->oauth->tokens, 'user_id')), "ana's OAuth grant is revoked");
		$this->finish();
	}

	/** @return void one grant for a page of users at once, all or nothing */
	public function testBulk(): void {
		$this->start('grants.bulk');
		$bulk = static fn (GrantsController $c): Response => $c->bulk();
		$create = ['uids' => ['ana', 'bob', 'ana'], 'module' => 'notes', 'operation' => 'create', 'granted' => true];

		$answer = $this->send('allow notes.create for ana and bob', 'grants#bulk', $create, $bulk);
		$this->assertSame(2, $answer['json']['updated']);
		$this->assertSame([true, true], [$this->rig->fx->policy->granted('ana', 'notes', 'create'), $this->rig->fx->policy->granted('bob', 'notes', 'create')]);
		$this->send('one unknown user refuses the whole page', 'grants#bulk', ['uids' => ['bob', 'ghost']] + $create, $bulk);
		$this->send('an empty page is invalid', 'grants#bulk', ['uids' => []] + $create, $bulk);
		$this->send('eligibility needs a null module', 'grants#bulk', ['uids' => ['bob'], 'module' => 'notes', 'operation' => 'eligible', 'granted' => false], $bulk);
		$this->unavailable('grants#bulk', ['uids' => ['bob'], 'module' => null, 'operation' => 'eligible', 'granted' => false], $bulk);
		$this->assertSame(['ana', 'bob'], array_values(array_column($this->rig->fx->oauth->tokens, 'user_id')));

		$this->send('bob loses eligibility', 'grants#bulk', ['uids' => ['bob'], 'module' => null, 'operation' => 'eligible', 'granted' => false], $bulk);
		$this->assertSame(['ana'], array_values(array_column($this->rig->fx->oauth->tokens, 'user_id')), "bob's OAuth grant is revoked");
		$this->finish();
	}

	/** @return void a reset previewed, applied as commands, and undone through its receipt */
	public function testCommandsPreviewAndUndo(): void {
		$this->start('grants.commands-undo');
		$reset = ['uid' => 'ana', 'module' => 'notes', 'operation' => 'edit', 'state' => 'inherit'];
		$preview = fn (string $step, array $body, string $user): array => $this->transcript->record($step,
			['route' => 'override_preview#index', 'user' => $user, 'body' => $body], $this->rig->preview($body, $user)->index());
		$answer = $preview('the preview of a reset writes nothing', ['commands' => [$reset]], 'root');
		$this->assertSame(['allowed' => false, 'source' => 'default', 'groupIds' => []], $answer['json']['cells'][0]['after']);
		$preview('a non-administrator cannot preview: 403', ['commands' => [$reset]], 'ana');
		$preview('an invalid preview body: 400', ['commands' => [$reset + ['extra' => 1]]], 'root');

		$commands = static fn (GrantsController $c): Response => $c->commands();
		$this->send('a command with a fifth key is invalid', 'grants#commands', ['commands' => [$reset + ['granted' => true]]], $commands);
		$this->send('an unknown state is invalid', 'grants#commands', ['commands' => [['state' => 'maybe'] + $reset]], $commands);
		$this->unavailable('grants#commands', ['commands' => [$reset]], $commands);
		$saved = $this->send('reset ana notes.edit and deny bob notes.read', 'grants#commands', ['commands' => [$reset,
			['uid' => 'bob', 'module' => 'notes', 'operation' => 'read', 'state' => 'deny']]], $commands)['json'];
		$this->assertSame(2, $saved['changed']);
		$this->assertSame([[], []], [array_intersect(['notes_edit'], $this->rig->tools('ana')), array_intersect(['notes_list'], $this->rig->tools('bob'))]);

		$undo = static fn (GrantsController $c): Response => $c->undo();
		$body = ['receiptId' => $saved['receiptId'], 'expectedRevision' => $saved['revision']];
		$this->send("another administrator cannot undo it: 404", 'grants#undo', $body, $undo, 'other-admin');
		$this->send('an invented receipt: 404', 'grants#undo', ['receiptId' => 'not-a-receipt'] + $body, $undo);
		$this->send('a receipt that is not a string: 400', 'grants#undo', ['receiptId' => 7] + $body, $undo);
		$this->send('another revision than the receipt committed: 409', 'grants#undo', ['expectedRevision' => '1'] + $body, $undo);
		$this->unavailable('grants#undo', $body, $undo);
		$this->assertNotContains('notes_edit', $this->rig->tools('ana'));

		$answer = $this->send('undo restores both cells', 'grants#undo', $body, $undo);
		$this->assertSame([200, 2], [$answer['status'], $answer['json']['changed']]);
		$this->assertSame('1', $this->rig->fx->config->user['ana']['mcp']['grant_notes_edit']);
		$this->assertArrayNotHasKey('grant_notes_read', $this->rig->fx->config->user['bob']['mcp'], 'absence restored, not a stored value');
		$this->assertSame([['notes_edit'], ['notes_list']], [array_values(array_intersect(['notes_edit'], $this->rig->tools('ana'))),
			array_values(array_intersect(['notes_list'], $this->rig->tools('bob')))]);
		$this->send('the same receipt again: 409', 'grants#undo', $body, $undo);
		$this->finish();
	}

	/** @return void the global switch: off cuts every credential, on again does not bring them back */
	public function testServiceSwitch(): void {
		$this->start('grants.service');
		$service = static fn (GrantsController $c): Response => $c->service();
		$this->send('a non-boolean is invalid', 'grants#service', ['enabled' => 'false'], $service);
		$this->send('a stale revision: 409', 'grants#service', ['enabled' => false, 'expectedRevision' => '0'], $service);
		$this->unavailable('grants#service', ['enabled' => false], $service);

		$answer = $this->send('switch the service off', 'grants#service', ['enabled' => false], $service);
		$this->assertSame([200, false], [$answer['status'], $answer['json']['enabled']]);
		$this->assertFalse($this->rig->fx->policy->globalEnabled());
		$this->assertSame(1, $this->rig->fx->config->authEpoch, 'switching off moves the global auth epoch');
		$this->send('switch it on again', 'grants#service', ['enabled' => true], $service);
		$this->assertTrue($this->rig->fx->policy->globalEnabled());
		$this->assertSame(1, $this->rig->fx->config->authEpoch, 'credentials of the old epoch stay invalid');
		$this->finish();
	}
}
