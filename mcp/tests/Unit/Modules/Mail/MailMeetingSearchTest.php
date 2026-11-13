<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\MailMeetingSearch;
use OCA\Mcp\Modules\Mail\Reading\ListMessagesHandler;
use OCA\Mcp\Modules\Mail\Reading\MailSearchListing;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** mail_search_messages is the only read the meeting dossier may ask of Mail. */
final class MailMeetingSearchTest extends TestCase {
	private bool $granted = true;
	private bool $appEnabled = true;
	private bool $guestAllowed = true;
	private bool $unavailable = false;
	/** @var list<array{string, string, list<string>}> */
	private array $asked = [];
	/** @var list<array{string, string}> */
	private array $guestApps = [];

	/**
	 * A missing mail grant refuses the port before the search runs.
	 *
	 * @return void
	 */
	public function testADeniedGrantNeverReachesTheSearch(): void {
		$this->granted = false;
		$search = new CountingMailSearch();
		$mail = new MailMeetingSearch($this->access(), $search);

		try {
			$mail->search('alice', []);
			self::fail('A denied grant reached the search');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('mail_search_messages', $error->getMessage());
		}
		self::assertSame(0, $search->calls);
		self::assertSame([['alice', 'mail', ['messages']]], $this->asked);
	}

	/**
	 * A Mail app that is off for the actor refuses the port before the search runs.
	 *
	 * @return void
	 */
	public function testADisabledAppNeverReachesTheSearch(): void {
		$this->appEnabled = false;
		$search = new CountingMailSearch();
		$mail = new MailMeetingSearch($this->access(), $search);

		try {
			$mail->search('alice', []);
			self::fail('A disabled app reached the search');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('mail_search_messages', $error->getMessage());
		}
		self::assertSame(0, $search->calls);
	}

	/**
	 * A guest whom Guests keeps out of Mail never reaches the search.
	 *
	 * @return void
	 */
	public function testAGuestTheAppForbidsNeverReachesTheSearch(): void {
		$this->guestAllowed = false;
		$search = new CountingMailSearch();
		$mail = new MailMeetingSearch($this->access(), $search);

		try {
			$mail->search('alice', []);
			self::fail('A forbidden guest reached the search');
		} catch (ToolFailure) {
			self::assertSame(0, $search->calls);
		}
		self::assertContains(['alice', 'mail'], $this->guestApps);
	}

	/**
	 * An empty call applies the published limit and searches once.
	 *
	 * @return void
	 */
	public function testAnEmptyCallAppliesThePublishedLimitAndSearchesOnce(): void {
		$search = new CountingMailSearch();
		$mail = new MailMeetingSearch($this->access(), $search);

		$result = $mail->search('alice', []);

		self::assertSame(['messages' => []], $result);
		self::assertSame(1, $search->calls);
		self::assertSame('alice', $search->userId);
		self::assertSame(ListMessagesHandler::DEFAULT_LIMIT, $search->arguments['limit']);
		self::assertSame([['alice', 'mail', ['messages']]], $this->asked);
		self::assertContains(['alice', 'mail'], $this->guestApps);
	}

	/**
	 * An argument mail_search_messages does not publish is refused before the search.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyIsRefusedBeforeTheSearch(): void {
		$search = new CountingMailSearch();
		$mail = new MailMeetingSearch($this->access(), $search);

		try {
			$mail->search('alice', ['uid' => 'one-message']);
			self::fail('An unknown key reached the search');
		} catch (InvalidArgumentException) {
			self::assertSame(0, $search->calls);
		}
	}

	/**
	 * A grant that cannot be read propagates and the search is not called.
	 *
	 * @return void
	 */
	public function testAnUnreadableGrantPropagatesAndSkipsTheSearch(): void {
		$this->unavailable = true;
		$search = new CountingMailSearch();
		$mail = new MailMeetingSearch($this->access(), $search);

		$this->expectException(PolicyUnavailable::class);
		try {
			$mail->search('alice', []);
		} finally {
			self::assertSame(0, $search->calls);
		}
	}

	/**
	 * The registry gate of mail_search_messages, with the grant, app and guest answers of this test.
	 *
	 * @return ToolAccess the real registry decision
	 */
	private function access(): ToolAccess {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(function (string $uid, string $module, array $operations): bool {
			$this->asked[] = [$uid, $module, $operations];
			if ($this->unavailable) {
				throw new PolicyUnavailable('x');
			}
			return $this->granted;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (): bool => $this->appEnabled);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (): IUser => $this->createMock(IUser::class));
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturnCallback(function (string $uid, string $app): bool {
			$this->guestApps[] = [$uid, $app];
			return $this->guestAllowed;
		});
		return new ToolAccess($policy, $apps, $users, $guests, new CapabilityRegistry([], new NullLogger()));
	}
}

/** Counts mail_search_messages. The port types this interface, which has no other read. */
final class CountingMailSearch implements MailSearchListing {
	public int $calls = 0;
	public string $userId = '';
	/** @var array<string, mixed> */
	public array $arguments = [];

	/** {@inheritDoc} */
	public function searchMessages(array $arguments, string $userId): array {
		$this->calls++;
		$this->arguments = $arguments;
		$this->userId = $userId;
		return ['messages' => []];
	}
}
