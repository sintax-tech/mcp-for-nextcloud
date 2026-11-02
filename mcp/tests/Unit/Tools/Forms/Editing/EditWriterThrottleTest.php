<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

require_once __DIR__ . '/EditingFixture.php';
require_once dirname(__DIR__) . '/Creation/NativeNoSuchFormException.php';
use OCA\Mcp\Tools\Forms\Editing\{EditWriter, EditNativeFactory, EditState, EditPlan, EditRequest};
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Mcp\{Confirmation\PlanState, Tools\Results\ToolFailure};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
/** Separates refusal before native entry from a second-delay failure after the controller was attempted. */
final class EditWriterThrottleTest extends TestCase
{
	/** Uses the real throttle bridge and writer, with explicit first/second failure ports and no automatic second PATCH.
	 * @return void
	 */
	public function testWriterClassifiesBothThrottleBoundaries(): void
	{
		foreach ([1, 2] as $failure) {
			$f = new EditingFixture();
			$c = $f->context();
			$r = EditRequest::of(['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => 'New']], false);
			$state = new EditState();
			$plans = new EditPlan(new PlanState((new InMemoryConfig())->mock($this)));
			$s = $state->read($c, $r, 'alice');
			$e = $state->effect($c, $r, $s);
			$plan = $plans->of($r, $c, $s, $e, 'alice');
			$events = [];
			$delays = 0;
			$t = $this->createMock(\OCP\Security\Bruteforce\IThrottler::class);
			$t->method('sleepDelayOrThrowOnMax')->willReturnCallback(static function () use (&$delays, $failure, &$events): int {
				$events[] = 'delay';
				if (++$delays === $failure) {
					throw new \OCP\Security\Bruteforce\MaxDelayReached('limit');
				}
				return 0;
			});
			$t->expects($failure === 1 ? self::never() : self::once())->method('registerAttempt')->with('form', '127.0.0.1', ['action' => 'form'])->willReturnCallback(static function () use (&$events): void {
				$events[] = 'register';
			});
			$request = $this->createMock(\OCP\IRequest::class);
			$request->method('getRemoteAddress')->willReturn('127.0.0.1');
			$controller = new EditMissingFormController($events);
			$context = new \OCA\Mcp\Modules\Forms\Editing\EditContext($c->version, $controller, $c->config, $c->service, $c->questions, $c->options, $c->shares);
			$writer = new EditWriter($this->createMock(EditNativeFactory::class), $state, $plans, new CreateNativeThrottle($t, $request));
			try {
				$receipt = $writer->write($context, $r, $s, $e, $plan, 'alice');
				self::assertSame(2, $failure);
				self::assertSame('uncertain', $receipt['status']);
				self::assertTrue($receipt['nativeCallAttempted']);
			} catch (ToolFailure) {
				self::assertSame(1, $failure);
			}
			self::assertSame($failure === 1 ? ['delay'] : ['delay', 'native', 'register', 'delay'], $events);
			self::assertSame([], $f->calls);
		}
	}
}
