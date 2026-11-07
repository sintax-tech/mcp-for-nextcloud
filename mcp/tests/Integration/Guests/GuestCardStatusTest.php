<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Deck\FunctionalDeckScene;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Status editing keeps native Guests parity; the strict file-link policy is not applied here. */
#[Group('deck')]
final class GuestCardStatusTest extends IntegrationTestCase {
	use GuestTestSupport;
	private const GUEST = 'mcp-it-dk-status-guest';

	/** @param GrantPolicy $policy Fixture policy. @return void */
	protected static function grantGuestControls(GrantPolicy $policy): void {
		$policy->setGrant(self::GUEST, 'deck', 'read', true);
		$policy->setGrant(self::GUEST, 'deck', 'edit', true);
	}

	/** Native guest service and HTTP OAuth both edit a received board, then both lose EDIT. */
	public function testGuestCompletionMatchesNativeAndReadOnlyIsDenied(): void {
		$scene = new FunctionalDeckScene();
		try {
			$this->guests(['usewhitelist' => 'yes', 'whitelist' => 'mcp,deck', 'hide_users' => 'yes']);
			$owner = $scene->uid(0); $f = $scene->seed($owner, self::GUEST);
			FunctionalDeckScene::run(self::GUEST, 'native-status', ['card' => $f['nativeCard'], 'method' => 'done']);
			$native = FunctionalDeckScene::run(self::GUEST, 'read', ['card' => $f['nativeCard']]);
			self::assertIsInt($native['done']);
			$client = $this->mcp('bearer');
			foreach ([['deck_set_card_done', 'done', true], ['deck_set_card_done', 'done', false],
				['deck_set_card_archived', 'archived', true], ['deck_set_card_archived', 'archived', false]] as [$tool, $field, $value]) {
				$args = ['cardId' => $f['card'], $field => $value];
				$reply = $client->tool($tool, $args); self::assertTrue($reply->ok(), $reply->describe());
				$plan = $reply->structured() ?: $reply->json();
				$written = $client->write($tool, $args + ['plan_state' => $plan['plan_state'], 'confirm_shared' => true]);
				self::assertTrue($written->ok(), $written->describe());
				$data = $written->structured() ?: $written->json(); self::assertTrue($data['confirmed']);
				$state = FunctionalDeckScene::run(self::GUEST, 'read', ['card' => $f['card']]);
				self::assertSame($value, $field === 'done' ? $state['done'] !== null : $state['archived']);
			}
			FunctionalDeckScene::run($owner, 'read-only', $f + ['editor' => self::GUEST]);
			$denied = $client->tool('deck_set_card_done', ['cardId' => $f['card'], 'done' => true]);
			self::assertFalse($denied->ok()); self::assertStringNotContainsString('Synthetic card', $denied->text());
			FunctionalDeckScene::run($owner, 'read-only', $f + ['editor' => self::GUEST, 'edit' => true]);
			$allowed = $client->tool('deck_set_card_archived', ['cardId' => $f['card'], 'archived' => true]);
			self::assertTrue($allowed->ok(), $allowed->describe());
			$this->guests(['usewhitelist' => 'yes', 'whitelist' => 'mcp', 'hide_users' => 'yes']);
			self::assertFalse($client->tool('deck_set_card_archived', ['cardId' => $f['card'], 'archived' => true])->ok());
		} finally { $scene->close(); }
	}
}
