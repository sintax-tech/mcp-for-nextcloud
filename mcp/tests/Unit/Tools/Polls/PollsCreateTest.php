<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tools\{ArgumentValidationException, ToolFailure, PlanChanged};
use OCA\Mcp\Tools\Polls\Create\{CreateRequest, CreateDefaults};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Discriminates preview/no effects, native persisted-versus-transient values, independent grants and failure phases. */
final class PollsCreateTest extends TestCase {
	/** @var PollsCreateRig actual graph */
	private PollsCreateRig $rig;
	/** @return void new test scene */
	protected function setUp(): void {
		$this->rig = new PollsCreateRig($this);
	}
	/** @return array<string,mixed> literal valid request */
	private function input(): array {
		return ['type' => 'textPoll', 'title' => 'Private <3', 'options' => [['text' => '&amp;'], ['text' => 'a<b']]];
	}
	/** @return void preview and positive persistence normalization */
	public function testPreviewAndNativePersistedDefaultsPass(): void {
		$args = $this->input();
		$plan = $this->rig->handler->preview($args, 'alice');
		self::assertSame([], NativeCreateScene::$effects);
		self::assertSame([], NativeScene::$polls);
		self::assertSame('&', $plan['options'][0]['text']);
		self::assertSame('&amp;', $plan['request']['options'][0]['text']);
		self::assertNull($plan['persistence']['persisted']['description']);
		self::assertSame('', $plan['persistence']['observable']['description']);
		$receipt = $this->rig->confirm($args, $plan);
		self::assertSame('created', $receipt['status']);
		self::assertSame(2, $receipt['completed']);
		self::assertSame(['textPoll', 'Private <3', null, 'simple'], NativeCreateScene::$addArgs[0]);
		self::assertNull(NativeScene::$polls[100]['description']);
		self::assertSame('', $receipt['observedDefaults']['description']);
		self::assertSame(1, $receipt['observedDefaults']['allowMaybe']);
		self::assertSame(1, $receipt['observedDefaults']['allowComment']);
		self::assertSame(1, $receipt['observedDefaults']['useNo']);
		self::assertNull($receipt['observedDefaults']['miscSettings']);
		self::assertNotEmpty(NativeCreateScene::$reads);
		self::assertSame(['poll.insert', 'poll.update', 'poll.event', 'option.insert', 'option.event', 'option.insert', 'option.event'], NativeCreateScene::$effects);
		self::assertSame(0, count(array_filter(NativeCreateScene::$effects, static fn(string $v): bool => $v === 'vote')));
		self::assertSame(0, NativeScene::session()->cleans % 2);
	}
	/** @return void zero initial options is a useful supported creation */
	public function testEmptyInitialOptionsAndRepeatedConfirmationAreSeparateCreations(): void {
		$args = ['type' => 'textPoll', 'title' => 'Empty'];
		$plan = $this->rig->handler->preview($args, 'alice');
		$one = $this->rig->confirm($args, $plan);
		$two = $this->rig->confirm($args, $plan);
		self::assertSame('created', $one['status']);
		self::assertSame(0, $one['completed']);
		self::assertNotSame($one['pollId'], $two['pollId']);
	}
	/** @return void canonical duplicate preflight runs before any sink */
	public function testCanonicalDuplicatesFailBeforePoll(): void {
		$args = ['type' => 'textPoll', 'title' => 'Duplicate', 'options' => [['text' => '&amp;'], ['text' => '&']]];
		try {
			$this->rig->handler->preview($args, 'alice');
			self::fail('duplicate accepted');
		} catch (ArgumentValidationException) {
			self::assertSame([], NativeCreateScene::$effects);
		}
	}
	/** @return void native dates are predicted and written, without string coercion */
	public function testDateEffectAndDisposableInputs(): void {
		$args = ['type' => 'datePoll', 'title' => 'Date', 'options' => [['timestamp' => 1800000000, 'duration' => 86400], ['timestamp' => 1800000000, 'duration' => 0]]];
		$plan = $this->rig->handler->preview($args, 'alice');
		$before = $plan;
		$out = $this->rig->confirm($args, $plan);
		self::assertSame('created', $out['status']);
		self::assertSame($before, $plan);
		self::assertNotSame(NativeCreateScene::$inputs[0], NativeCreateScene::$inputs[1]);
		self::assertSame(86400, $out['options'][0]['duration']);
	}
	/** @return void native legacy order mutation cannot touch predicted immutable scalars */
	public function testLegacyOrderMutationDoesNotAlterPrediction(): void {
		$args = CreateRequest::of($this->input());
		$effect = $this->rig->normalizer->predict($args, false);
		$again = $effect;
		$simple = $this->rig->normalizer->simple($args['options'][0], $args['type']);
		$simple->setOrder(100);
		self::assertSame($again, $effect);
		self::assertSame(100, $simple->order);
		self::assertNotSame($simple, $this->rig->normalizer->simple($args['options'][0], $args['type']));
	}
	/** @return iterable<string,array{array<string,mixed>}> malformed requests */
	public static function invalid(): iterable {
		$base = ['type' => 'textPoll', 'title' => 'valid'];
		yield 'empty title' => [array_replace($base, ['title' => ''])];
		yield 'zero title' => [array_replace($base, ['title' => '0'])];
		yield '129 chars' => [array_replace($base, ['title' => str_repeat('a', 129)])];
		yield 'invalid utf8' => [array_replace($base, ['title' => "bad\xff"])];
		yield 'bidi' => [array_replace($base, ['title' => "a‎b"])];
		yield 'C1 valid' => [array_replace($base, ['title' => "ab"])];
		yield 'unknown' => [$base + ['access' => 'open']];
		yield 'null options' => [$base + ['options' => null]];
		yield '101 options' => [$base + ['options' => array_fill(0, 101, ['text' => 'x'])]];
		yield '1025 text' => [$base + ['options' => [['text' => str_repeat('a', 1025)]]]];
		yield 'mixed fields' => [$base + ['options' => [['text' => 'x', 'timestamp' => 4]]]];
		yield 'array item' => [$base + ['options' => [['x']]]];
		yield 'float date' => [['type' => 'datePoll', 'title' => 'x', 'options' => [['timestamp' => 1.0]]]];
		yield 'null duration' => [['type' => 'datePoll', 'title' => 'x', 'options' => [['timestamp' => 1, 'duration' => null]]]];
		yield 'negative duration' => [['type' => 'datePoll', 'title' => 'x', 'options' => [['timestamp' => 1, 'duration' => -1]]]];
		yield 'over bytes' => [$base + ['options' => array_fill(0, 100, ['text' => str_repeat('é', 1024)])]];
	}
	/**
	 * Runtime semantic validation does not rely on the schema or permissive fake sinks.
	 * @param array<string,mixed> $args invalid input
	 * @return void
	 */
	#[DataProvider('invalid')]
	public function testInvalidInputsHaveNoEffects(array $args): void {
		try {
			$this->rig->handler->preview($args, 'alice');
			self::fail('accepted invalid input');
		} catch (ArgumentValidationException) {
			self::assertSame([], NativeCreateScene::$effects);
		}
	}
	/** @return iterable<string,array{string,mixed}> observable drift cases */
	public static function drift(): iterable {
		foreach (['allowComment' => 0, 'hideBookedUp' => 0, 'useNo' => 0, 'allowMaybe' => 0, 'allowProposals' => '', 'description' => 'changed', 'miscSettings' => '', 'timezoneName' => 'Europe/Berlin'] as $key => $value) {
			yield $key => [$key, $value];
		}
	}
	/**
	 * Return object has stale defaults; only the authorized readback can decide the next stage.
	 * @param string $field native persisted field
	 * @param mixed $value divergent value
	 * @return void
	 */
	#[DataProvider('drift')]
	public function testObservableReadbackDriftStopsBeforeAnyOption(string $field, mixed $value): void {
		$args = $this->input();
		$plan = $this->rig->handler->preview($args, 'alice');
		NativeCreateScene::$hook = static function (string $stage, int $id) use ($field, $value): void {
			if ($stage === 'poll.event') {
				NativeScene::$polls[$id][$field] = $value;
			}
		};
		$out = $this->rig->confirm($args, $plan);
		self::assertSame('uncertain', $out['status']);
		self::assertSame([], NativeScene::$options);
		self::assertCount(1, NativeScene::$polls);
		self::assertArrayNotHasKey('pollId', $out);
	}
	/** @return void timezone public normalization is not raw schema identity */
	public function testTimezoneEmptyCollapsesButMiscDoesNot(): void {
		NativeCreateScene::$hook = static function (string $stage, int $id): void {
			if ($stage === 'poll.event') {
				NativeScene::$polls[$id]['timezoneName'] = '';
			}
		};
		$out = $this->rig->confirm($this->input());
		self::assertSame('created', $out['status']);
		self::assertNull($out['observedDefaults']['timezoneName']);
	}
	/** @return iterable<string,array{string}> failure stages after persistence */
	public static function failures(): iterable {
		foreach (['poll.insert', 'poll.update', 'poll.event', 'option.insert', 'option.event'] as $s) {
			yield $s => [$s];
		}
	}
	/**
	 * Native event/insert failures are never described as zero writes, and do not retry.
	 * @param string $stage fixed native stage
	 * @return void
	 */
	#[DataProvider('failures')]
	public function testFailureAfterNativeSinkIsUncertain(string $stage): void {
		$args = $this->input();
		$plan = $this->rig->handler->preview($args, 'alice');
		NativeCreateScene::$hook = static function (string $s, int $id) use ($stage): void {
			if ($s === $stage) {
				throw new \RuntimeException('PRIVATE-body-token');
			}
		};
		$out = $this->rig->confirm($args, $plan);
		self::assertSame('uncertain', $out['status']);
		self::assertCount(1, NativeScene::$polls);
		self::assertStringNotContainsString('PRIVATE', json_encode([$out, $this->rig->base->logs], JSON_THROW_ON_ERROR));
		self::assertCount(1, NativeCreateScene::$addArgs);
	}
	/** @return void finally failure after all sinks still preserves an uncertain outcome */
	public function testCleanupAfterWriteIsUncertain(): void {
		$args = $this->input();
		$plan = $this->rig->handler->preview($args, 'alice');
		NativeScene::session()->failFinally = true;
		$out = $this->rig->confirm($args, $plan);
		self::assertSame('uncertain', $out['status']);
		self::assertCount(1, NativeScene::$polls);
	}
	/** @return void changed arguments never reuse an older confirmation */
	public function testChangedArgumentsRequireNewPlanWithoutWrite(): void {
		$args = $this->input();
		$plan = $this->rig->handler->preview($args, 'alice');
		$args['options'][0]['text'] = 'changed';
		try {
			$this->rig->confirm($args, $plan);
			self::fail('old state executed');
		} catch (PlanChanged $e) {
			self::assertTrue($e->plan['changed']);
			self::assertSame([], NativeCreateScene::$effects);
		}
	}
	/** @return void an independent create grant is required on direct handler calls */
	public function testNoCreateOrNativePolicyHasNoWrite(): void {
		$this->rig->base->policy->setGrant('alice', 'polls', 'create', false);
		try {
			$this->rig->handler->preview($this->input(), 'alice');
			self::fail('denied grant accepted');
		} catch (ToolFailure) {
			self::assertSame([], NativeCreateScene::$effects);
		}
		$this->rig->base->policy->setGrant('alice', 'polls', 'create', true);
		NativeCreateScene::$allowed = false;
		try {
			$this->rig->handler->preview($this->input(), 'alice');
			self::fail('native policy accepted');
		} catch (ToolFailure) {
			self::assertSame([], NativeCreateScene::$effects);
		}
	}
	/** @return void two native identities remain independent and both must agree */
	public function testIndependentNativeIdentitiesAreChecked(): void {
		foreach (['forceId', 'forceObject'] as $field) {
			$rig = new PollsCreateRig($this);
			NativeScene::session()->forceId = 'alice';
			NativeScene::session()->{$field} = 'bob';
			try {
				$rig->handler->preview($this->input(), 'alice');
				self::fail('foreign identity accepted');
			} catch (ToolFailure) {
				self::assertSame([], NativeCreateScene::$effects);
			}
		}
	}
}
