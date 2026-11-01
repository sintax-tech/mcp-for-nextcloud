<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Mcp\{Tools\Results\ToolFailure, Confirmation\PlanChanged};
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
/** Adversarial stage/receipt/MAC proofs through the real creation flow. */
final class PollsCreateAdversarialTest extends TestCase {
	/** @return array<string,mixed> two distinct initial options */
	private function input(): array {
		return ['type' => 'textPoll', 'title' => 'Private', 'options' => [['text' => 'one'], ['text' => 'two']]];
	}
	/** @return void the public upper boundary preserves input order and distinct native ids */
	public function testOneHundredOptionsKeepOrderWithoutVotes(): void {
		$r = new PollsCreateRig($this);
		$args = $this->input();
		$args['options'] = array_map(static fn(int $i): array => ['text' => 'choice ' . $i], range(0, 99));
		$out = $r->confirm($args);
		self::assertSame('created', $out['status']);
		self::assertSame(100, $out['completed']);
		self::assertSame(range(0, 99), array_column($out['options'], 'inputIndex'));
		self::assertCount(100, array_unique(array_column($out['options'], 'id')));
		self::assertNotContains('vote', NativeCreateScene::$effects);
	}
	/** @return iterable<string,array{string}> malformed native sink returns */
	public static function badReturns(): iterable {
		foreach (['poll zero', 'option zero', 'option parent', 'option duplicate', 'option value'] as $c) {
			yield $c => [$c];
		}
	}
	/**
	 * Native ids/effects must be independently checked, with no search/retry after an invalid return.
	 * @param string $case bad return
	 * @return void
	 */
	#[DataProvider('badReturns')]
	public function testBadSinkReturnStopsAsUncertain(string $case): void {
		$r = new PollsCreateRig($this);
		$args = $this->input();
		$plan = $r->handler->preview($args, 'alice');
		$first = null;
		NativeCreateScene::$returning = static function (object $value, string $kind) use ($case, &$first): object {
			if ($case === 'poll zero' && $kind === 'poll') {
				$value->row['id'] = 0;
			}
			if ($kind === 'option') {
				if ($case === 'option zero') {
					$value->row['id'] = 0;
				}
				if ($case === 'option parent') {
					$value->row['pollId'] = 999;
				}
				if ($case === 'option value') {
					$value->row['text'] = 'unexpected';
				}
				if ($case === 'option duplicate') {
					if ($first === null) {
						$first = $value->getId();
					} else {
						$value->row['id'] = $first;
					}
				}
			}
			return $value;
		};
		$out = $r->confirm($args, $plan);
		self::assertSame('uncertain', $out['status']);
		self::assertCount(1, NativeCreateScene::$addArgs);
		self::assertLessThanOrEqual(2, count(NativeCreateScene::$inputs));
		self::assertSame('', NativeScene::session()->token);
		self::assertNull(NativeScene::session()->stored);
		self::assertNull(NativeScene::session()->cached);
	}
	/** @return void a refusal before the next sink is partial; the poll and the known option remain, without retry */
	public function testPolicyRevocationBeforeSecondOptionIsPartial(): void {
		$r = new PollsCreateRig($this);
		$args = $this->input();
		$plan = $r->handler->preview($args, 'alice');
		$optionFinished = false;
		$reads = 0;
		NativeCreateScene::$hook = static function (string $stage, int $id) use (&$optionFinished, &$reads): void {
			if ($stage === 'option.event') {
				$optionFinished = true;
			}
			if ($stage === 'readback' && $optionFinished && ++$reads === 2) {
				NativeCreateScene::$allowed = false;
			}
		};
		$out = $r->confirm($args, $plan);
		self::assertSame('partial', $out['status']);
		self::assertCount(1, NativeCreateScene::$inputs);
		self::assertCount(1, NativeScene::$polls);
		self::assertCount(1, NativeScene::$options[100]);
		self::assertSame(100, $out['pollId']);
		self::assertCount(1, $out['options']);
		self::assertSame('one', $out['options'][0]['text']);
		self::assertSame(2, $out['requested']);
		self::assertSame(1, $out['completed']);
		self::assertSame(1, $out['remaining']);
		self::assertStringContainsString('partial progress', $out['message']);
		self::assertStringNotContainsString('uncertain', $out['message']);
		self::assertNull(NativeScene::session()->stored);
		self::assertNull(NativeScene::session()->cached);
	}
	/** @return void a second sink failure cannot claim just one option persisted */
	public function testSecondOptionEventFailureRemainsUncertain(): void {
		$r = new PollsCreateRig($this);
		$args = $this->input();
		$plan = $r->handler->preview($args, 'alice');
		$events = 0;
		NativeCreateScene::$hook = static function (string $s, int $id) use (&$events): void {
			if ($s === 'option.event' && ++$events === 2) {
				throw new \RuntimeException('after persistence');
			}
		};
		$out = $r->confirm($args, $plan);
		self::assertSame('uncertain', $out['status']);
		self::assertSame(1, $out['completed']);
		self::assertCount(2, NativeScene::$options[100]);
		self::assertCount(2, NativeCreateScene::$inputs);
		self::assertNull(NativeScene::session()->stored);
	}
	/** @return void endpoint login/app/grant changes deny before any sink */
	public function testPreflightRevocationsAndActorBinding(): void {
		foreach (['logged out', 'other actor', 'disabled', 'grant', 'version'] as $case) {
			$r = new PollsCreateRig($this);
			$args = $this->input();
			$plan = $r->handler->preview($args, 'alice');
			if ($case === 'logged out') {
				$r->base->uid = null;
			}
			if ($case === 'other actor') {
				$r->base->uid = 'bob';
			}
			if ($case === 'disabled') {
				$r->base->enabled = false;
			}
			if ($case === 'grant') {
				$r->base->policy->setGrant('alice', 'polls', 'create', false);
			}
			if ($case === 'version') {
				$r->base->version = '9.3.1';
			}
			try {
				$r->confirm($args, $plan);
				self::fail('revoked/changed context accepted');
			} catch (ToolFailure|PlanChanged) {
				self::assertSame([], NativeCreateScene::$effects, $case);
			}
		}
	}
	/** @return void a concurrent rewrite can make a recycled returned id match the second effect; uniqueness still matters */
	public function testRepeatedIdWithMatchingReadbackIsNotAcknowledgedTwice(): void {
		$r = new PollsCreateRig($this);
		$args = $this->input();
		$plan = $r->handler->preview($args, 'alice');
		$first = null;
		NativeCreateScene::$returning = static function (object $value, string $kind) use (&$first): object {
			if ($kind === 'option') {
				if ($first === null) {
					$first = $value->getId();
				} else {
					NativeScene::$options[100][0]['text'] = $value->row['text'];
					$value->row['id'] = $first;
				}
			}
			return $value;
		};
		$out = $r->confirm($args, $plan);
		self::assertSame('uncertain', $out['status']);
		self::assertSame(1, $out['completed']);
		self::assertCount(2, NativeCreateScene::$inputs);
		self::assertCount(1, $out['options']);
	}
	/** @return void canonical equality cannot erase the original requested text from the plan MAC */
	public function testCanonicalEquivalentRawInputStillChangesPlan(): void {
		$r = new PollsCreateRig($this);
		$args = ['type' => 'textPoll', 'title' => 'MAC', 'options' => [['text' => '&amp;']]];
		$plan = $r->handler->preview($args, 'alice');
		$args['options'][0]['text'] = '&';
		try {
			$r->confirm($args, $plan);
			self::fail('Original input was not bound');
		} catch (PlanChanged $e) {
			self::assertNotSame($plan['plan_state'], $e->plan['plan_state']);
			self::assertSame([], NativeCreateScene::$effects);
		}
	}
	/** @return void each sink/readback uses a distinct native option service and fresh native poll prototype */
	public function testFactoriesAreNewForEveryOptionAndReadback(): void {
		$r = new PollsCreateRig($this);
		$out = $r->confirm($this->input());
		self::assertSame('created', $out['status']);
		self::assertSame(4, NativeScene::$factories);
	}
	/** @return void omitted/false confirmation cannot call a sink directly */
	public function testDirectHandlerCannotSkipConfirmation(): void {
		$r = new PollsCreateRig($this);
		$args = $this->input();
		foreach ([[], ['confirm' => false], ['confirm' => true]] as $flags) {
			try {
				$r->handler->handle($args + $flags, 'alice');
				self::fail('missing confirmation state accepted');
			} catch (\Throwable $e) {
				self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
				self::assertSame([], NativeCreateScene::$effects);
			}
		}
	}
}
