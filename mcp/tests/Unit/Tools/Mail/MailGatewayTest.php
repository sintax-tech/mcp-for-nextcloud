<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The boundary keeps delegated and foreign accounts out whatever Mail answers, lets real failures through, and
 * resolves the Mail classes only while Mail is enabled for the user in a supported version.
 */
final class MailGatewayTest extends TestCase {
	/**
	 * An account Mail returns for the user but owned by somebody else (a delegated one) is not listed.
	 *
	 * @return void
	 */
	public function testAnAccountOwnedBySomebodyElseIsNeverListed(): void {
		$journal = new \ArrayObject();
		$own = $this->account(3, 'alice', $journal);
		$delegated = $this->account(8, 'carol', $journal);
		$gateway = new MailGateway($this->services(['findByUserId' => static fn (): array => [$own, $delegated]], $journal));

		$this->assertSame([['id' => 3, 'name' => 'Account 3', 'email' => 'a3@example.com']], $gateway->accounts('alice'));
	}

	/**
	 * An account find() returns but owned by somebody else counts as not found.
	 *
	 * @return void
	 */
	public function testAFoundAccountOfAnotherOwnerIsNotFound(): void {
		$journal = new \ArrayObject();
		$delegated = $this->account(8, 'carol', $journal);
		$gateway = new MailGateway($this->services(['find' => static fn (): object => $delegated], $journal));

		$this->assertNull($gateway->ownedAccount('alice', 8));
	}

	/**
	 * Only the "no such account" of Mail becomes not found; any other failure reaches the registry.
	 *
	 * @return void
	 */
	public function testAnotherFailureOfMailIsNotTurnedIntoNotFound(): void {
		$journal = new \ArrayObject();
		$gateway = new MailGateway($this->services(['find' => static fn (): object => throw new RuntimeException('database down')], $journal));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('database down');
		$gateway->ownedAccount('alice', 3);
	}

	/**
	 * The services refuse while Mail is off for the user, too old, or not loaded, and never ask the container.
	 *
	 * @return void
	 */
	public function testTheMailClassesAreResolvedOnlyWhenMailIsUsable(): void {
		foreach ([[false, '5.12.5'], [true, '4.4.0'], [true, '5.12.5']] as [$enabled, $version]) {
			$apps = $this->createMock(IAppManager::class);
			$apps->method('isEnabledForUser')->willReturn($enabled);
			$apps->method('getAppVersion')->willReturn($version);
			$users = $this->createMock(IUserManager::class);
			$users->method('get')->willReturn($this->createMock(IUser::class));
			$container = $this->createMock(ContainerInterface::class);
			// The last case is a usable Mail whose classes are not loaded in the unit suite.
			$container->expects($this->never())->method('get');
			try {
				(new MailServices($apps, $container, $users))->accountService('alice');
				$this->fail('Mail was resolved');
			} catch (MailUnavailableException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * An unknown user never reaches the app manager.
	 *
	 * @return void
	 */
	public function testAnUnknownUserHasNoMail(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->never())->method('isEnabledForUser');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);

		$this->assertFalse((new MailServices($apps, $this->createMock(ContainerInterface::class), $users))->isEnabledFor('ghost'));
	}

	/**
	 * Services whose account service answers the given methods.
	 *
	 * @param array<string, callable> $methods methods of the account service
	 * @param \ArrayObject<int, string> $journal shared call journal
	 * @return MailServices the services
	 */
	private function services(array $methods, \ArrayObject $journal): MailServices {
		return new class($methods, $journal) extends FakeMailApp {
			/**
			 * Keeps the account service methods.
			 *
			 * @param array<string, callable> $methods methods of the account service
			 * @param \ArrayObject<int, string> $calls shared call journal
			 * @return void
			 */
			public function __construct(private array $methods, private \ArrayObject $calls) {
				parent::__construct();
			}

			/**
			 * The account service with the given methods only.
			 *
			 * @param string $userId authenticated user UID
			 * @return object strict double
			 */
			public function accountService(string $userId): object {
				return new RecordingDouble('AccountService', $this->methods, $this->calls);
			}
		};
	}

	/**
	 * A strict double of OCA\Mail\Account.
	 *
	 * @param int $id account id
	 * @param string $owner UID of the owner
	 * @param \ArrayObject<int, string> $journal shared call journal
	 * @return object the double
	 */
	private function account(int $id, string $owner, \ArrayObject $journal): object {
		return new RecordingDouble('Account', [
			'getId' => static fn (): int => $id,
			'getName' => static fn (): string => 'Account ' . $id,
			'getEmail' => static fn (): string => 'a' . $id . '@example.com',
			'getUserId' => static fn (): string => $owner,
		], $journal);
	}
}
