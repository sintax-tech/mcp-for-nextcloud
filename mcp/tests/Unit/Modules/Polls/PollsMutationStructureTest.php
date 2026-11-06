<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Contracts\PollWriteReader;
use OCA\Mcp\Modules\Polls\Contracts\PollMutation;
use OCA\Mcp\Modules\Polls\Contracts\PollMutationKind;
use OCA\Mcp\Modules\Polls\Writing\PollWritePreviewFlow;
use OCA\Mcp\Modules\Polls\Writing\PollWriteEffectFlow;
use PHPUnit\Framework\TestCase;

/** The production preview has no mutation port or boolean effect selector. */
final class PollsMutationStructureTest extends TestCase {
	/** @return void */
	public function testPlanningAndEffectsHaveDistinctRealWorkersAndPorts(): void {
		$preview = new \ReflectionClass(PollWritePreviewFlow::class);
		$effect = new \ReflectionClass(PollWriteEffectFlow::class);
		self::assertSame(PollWriteReader::class, $preview->getConstructor()->getParameters()[0]->getType()->getName());
		self::assertFalse((new \ReflectionClass(PollWriteReader::class))->hasMethod('mutate'));
		self::assertTrue($preview->hasMethod('plan'));
		self::assertFalse($preview->hasMethod('execute'));
		self::assertTrue($effect->hasMethod('execute'));
		self::assertFalse($effect->hasMethod('plan'));
		foreach ([$preview->getMethod('plan'), $effect->getMethod('execute')] as $method) {
			foreach ($method->getParameters() as $parameter) {
				self::assertNotSame('bool', $parameter->getType()->getName());
			}
		}
	}
	/** @return void */
	public function testOnlyReviewedTypedNamesCanSelectAVerbFamily(): void {
		self::assertNull(PollMutation::tryFrom('polls_surprise_option_share'));
		self::assertSame(PollMutationKind::Options, PollMutation::AddOption->kind());
		self::assertSame(PollMutationKind::Shares, PollMutation::InviteShare->kind());
		self::assertSame(PollMutationKind::Configuration, PollMutation::SetPollState->kind());
		self::assertCount(10, PollMutation::cases());
	}
}
