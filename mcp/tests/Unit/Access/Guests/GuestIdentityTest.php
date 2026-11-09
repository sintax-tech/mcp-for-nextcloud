<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Guests;

use OCA\Mcp\Access\Guests\GuestIdentity;
use OCA\Mcp\Access\Guests\NativeGuests;
use OCP\App\IAppManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Identifying a guest account is not applying the Guests restrictions: a guest allowed the request is still a guest.
 * Fails closed when Guests is enabled and cannot answer, including when its enablement cannot be read.
 */
final class GuestIdentityTest extends TestCase {
	/**
	 * @param bool|\Throwable $enabled whether Guests is enabled for anyone, or what reading it throws
	 * @param bool|\Throwable $guest what Guests answers, or throws
	 * @return bool the identification
	 */
	private function identify(bool|\Throwable $enabled, bool|\Throwable $guest): bool {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('4.10.1');
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (): bool => $enabled instanceof \Throwable ? throw $enabled : $enabled);
		$native = $this->createMock(NativeGuests::class);
		$native->method('isGuest')->willReturnCallback(static fn (): bool => $guest instanceof \Throwable ? throw $guest : $guest);
		$native->method('isUrlAllowed')->willReturn(true);
		$native->method('setUp')->willReturn(true);
		return (new GuestIdentity($apps, $native, $this->createMock(LoggerInterface::class)))->isGuestAccount($this->createMock(IUser::class));
	}

	/** @return void a guest whose restrictions would apply successfully is still identified as a guest */
	public function testAGuestAllowedTheRequestIsAGuest(): void {
		$this->assertTrue($this->identify(true, true));
	}

	/** @return void an ordinary account, or no Guests at all, is not a guest */
	public function testOrdinaryAccounts(): void {
		$this->assertFalse($this->identify(true, false));
		$this->assertFalse($this->identify(false, true));
	}

	/** @return void any failure counts as a guest, so the action is refused */
	public function testFailuresCountAsGuest(): void {
		$this->assertTrue($this->identify(true, new \RuntimeException('Guests failed')));
		$this->assertTrue($this->identify(new \RuntimeException('app manager failed'), false));
	}
	/** @return void The logger receives a safe scalar, never a Throwable that Nextcloud serializes. */
	public function testFailureIsIdentifiableWithoutLeakingTheException(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willThrowException(new \RuntimeException('/private/Salary.txt', 17));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->willReturnCallback(static function (string $message, array $context): void {
			self::assertSame('RuntimeException (code 17)', $context['exception']);
			self::assertSame('The Guests app could not tell whether an account is a guest; the action is refused: RuntimeException (code 17)',
				strtr($message, ['{exception}' => $context['exception']]));
			self::assertStringNotContainsString('Salary', json_encode([$message, $context]));
		});
		$identity = new GuestIdentity($apps, $this->createMock(NativeGuests::class), $logger);
		self::assertTrue($identity->isGuestAccount($this->createMock(IUser::class)));
	}

}
