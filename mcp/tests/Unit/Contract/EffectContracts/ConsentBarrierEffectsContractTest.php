<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Service\GrantPolicy;
use PHPUnit\Framework\TestCase;

/** Consent admission at both boundaries, with the code table and connection observed independently of the page. */
final class ConsentBarrierEffectsContractTest extends TestCase {
	/** @return void an enabled, eligible account becomes disabled after the page: the writer must refuse */
	public function testDisabledAccountAtTheAuthoritativeAdmission(): void {
		$rig = new OAuthEffectsRig($this);
		$t = new ControllerTranscript($this, 'oauth.consent-authoritative', fn (): array => $rig->state());
		$rig->oauth('alice', OAuthEffectsRig::authorization())->authorize();
		$id = $rig->pendingId($t);
		$rig->enabled['alice'] = false;
		$this->assertTrue($rig->policy->eligible('alice'), 'the controller precheck still passes');
		$answer = $t->record('shared policy admits, disabled account refused under mutex', ['pending' => $id, 'decision' => 'allow'],
			$rig->oauth('alice')->consent($id, 'allow'));
		$this->assertTrue($answer['params']['blocked']);
		$this->assertSame([], $rig->store->codes);
		$this->assertFalse($rig->policy->connected('alice'));
		$t->assertGolden();
	}

	/** @return void a denied precheck snapshot must answer blocked even if the authoritative policy now admits */
	public function testTheControllerKeepsItsEarlyBlockedAnswer(): void {
		$rig = new OAuthEffectsRig($this);
		$t = new ControllerTranscript($this, 'oauth.consent-precheck', fn (): array => $rig->state());
		$rig->oauth('alice', OAuthEffectsRig::authorization())->authorize();
		$id = $rig->pendingId($t);
		$controller = $rig->oauth('alice');
		$snapshot = $this->createMock(GrantPolicy::class);
		$snapshot->method('globalEnabled')->willReturn(false);
		(new \ReflectionProperty($controller, 'policy'))->setValue($controller, $snapshot);
		$answer = $t->record('controller denied snapshot, writer would admit', ['pending' => $id, 'decision' => 'allow'],
			$controller->consent($id, 'allow'));
		$this->assertTrue($answer['params']['blocked']);
		$this->assertSame([], $rig->store->codes);
		$this->assertFalse($rig->policy->connected('alice'));
		$t->assertGolden();
	}
}
