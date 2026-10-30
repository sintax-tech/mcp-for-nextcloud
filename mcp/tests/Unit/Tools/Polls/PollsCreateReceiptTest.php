<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tools\Polls\Create\{CreateAttempt, CreateMessages};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Failure receipts preserve acknowledged progress only under current identity, grant and native access. */
final class PollsCreateReceiptTest extends TestCase {
	/** @return iterable<string,array{string}> receipt disclosure revocations */
	public static function revocations(): iterable {
		foreach (['grant', 'app', 'actor id', 'actor object', 'owner with access', 'access'] as $case) {
			yield $case => [$case];
		}
	}

	/**
	 * Revoking creation policy alone is different from revoking access to the acknowledged receipt.
	 * @param string $case independent admission or native owner/access failure
	 * @return void
	 */
	#[DataProvider('revocations')]
	public function testReceiptRemainsHiddenAfterOtherRevocations(string $case): void {
		$rig = new PollsCreateRig($this);
		$args = ['type' => 'textPoll', 'title' => 'Receipt', 'options' => [['text' => 'one'], ['text' => 'two']]];
		$plan = $rig->handler->preview($args, 'alice');
		$finished = false;
		$reads = 0;
		NativeCreateScene::$hook = static function (string $stage, int $id) use ($rig, $case, &$finished, &$reads): void {
			if ($stage === 'option.event') {
				$finished = true;
			}
			if ($stage !== 'readback' || !$finished || ++$reads !== 2) {
				return;
			}
			NativeCreateScene::$allowed = false;
			match ($case) {
				'grant' => $rig->base->policy->setGrant('alice', 'polls', 'create', false),
				'app' => $rig->base->enabled = false,
				'actor id' => NativeScene::session()->forceId = 'bob',
				'actor object' => NativeScene::session()->forceObject = 'bob',
				default => NativeScene::$polls[$id]['owner'] = 'bob',
			};
			if ($case === 'owner with access') {
				NativeScene::$polls[$id]['members'] = ['alice'];
			}
		};
		$out = $rig->confirm($args, $plan);
		self::assertSame('partial', $out['status']);
		self::assertCount(1, NativeCreateScene::$inputs);
		foreach (['pollId', 'url', 'options', 'requested', 'completed', 'remaining', 'observedDefaults'] as $field) {
			self::assertArrayNotHasKey($field, $out, $case . ': ' . $field);
		}
		self::assertNull(NativeScene::session()->stored);
		self::assertNull(NativeScene::session()->cached);
	}

	/** @return void partial and uncertain have distinct, stage-appropriate explanations */
	public function testPartialAndUncertainMessagesAreDistinct(): void {
		$attempt = new CreateAttempt();
		$attempt->started = true;
		self::assertSame(CreateMessages::partial(), $attempt->result('partial', 2)['message']);
		self::assertSame(CreateMessages::failure(true), $attempt->result('uncertain', 2)['message']);
		self::assertNotSame($attempt->result('partial', 2)['message'], $attempt->result('uncertain', 2)['message']);
	}
	/** @return void policy-only revocation retains the acknowledged poll and first option */
	public function testPolicyOnlyRevocationRetainsAcknowledgedReceipt(): void {
		$rig = new PollsCreateRig($this);
		$args = ['type' => 'textPoll', 'title' => 'Receipt', 'options' => [['text' => 'one'], ['text' => 'two']]];
		$plan = $rig->handler->preview($args, 'alice');
		$finished = false;
		$reads = 0;
		NativeCreateScene::$hook = static function (string $stage, int $id) use (&$finished, &$reads): void {
			if ($stage === 'option.event') {
				$finished = true;
			}
			if ($stage === 'readback' && $finished && ++$reads === 2) {
				NativeCreateScene::$allowed = false;
			}
		};
		$out = $rig->confirm($args, $plan);
		self::assertFalse(NativeCreateScene::$allowed);
		self::assertSame('partial', $out['status']);
		self::assertCount(1, NativeCreateScene::$inputs);
		self::assertArrayHasKey('pollId', $out);
		self::assertSame(100, $out['pollId']);
		self::assertCount(1, $out['options']);
		self::assertSame('one', $out['options'][0]['text']);
		self::assertSame(2, $out['requested']);
		self::assertSame(1, $out['completed']);
		self::assertSame(1, $out['remaining']);
	}

}
