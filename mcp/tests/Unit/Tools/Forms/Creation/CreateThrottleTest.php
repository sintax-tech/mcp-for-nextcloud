<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/NativeNoSuchFormException.php';

/** Discriminates pre-entry throttle denial from the native access-error second delay. */
final class CreateThrottleTest extends TestCase {
	/** Preserves order and marks native entry only after the first delay.
	 * @return void
	 */
	public function testInitialAndSecondDelayFailures(): void {
		foreach ([1, 2, 0] as $failure) {
			$events = []; $delays = 0; $entered = false;
			$t = $this->createMock(\OCP\Security\Bruteforce\IThrottler::class);
			$t->method('sleepDelayOrThrowOnMax')->willReturnCallback(static function () use (&$events, &$delays, $failure): int {
				$events[] = 'delay'; $delays++; if ($delays === $failure) { throw new \OCP\Security\Bruteforce\MaxDelayReached('delay limit'); } return 0;
			});
			$t->expects($failure === 1 ? self::never() : self::once())->method('registerAttempt')->with('form', '127.0.0.1', ['action' => 'form'])->willReturnCallback(static function () use (&$events): void { $events[] = 'register'; });
			$r = $this->createMock(\OCP\IRequest::class); $r->method('getRemoteAddress')->willReturn('127.0.0.1');
			try {
				(new CreateNativeThrottle($t, $r))->invoke(static function () use (&$events): never { $events[] = 'native'; throw new \OCA\Forms\Exception\NoSuchFormException('private'); }, static function () use (&$entered): void { $entered = true; });
				self::fail('Expected native or throttle error');
			} catch (\Throwable $error) {
				self::assertSame($failure !== 1, $entered);
				self::assertSame($failure === 1 ? ['delay'] : ['delay', 'native', 'register', 'delay'], $events);
				self::assertSame($failure === 0 ? \OCA\Forms\Exception\NoSuchFormException::class : \OCP\Security\Bruteforce\MaxDelayReached::class, $error::class);
			}
		}
	}
}
