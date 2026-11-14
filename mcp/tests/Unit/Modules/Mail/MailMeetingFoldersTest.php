<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\MailMeetingSearch;
use OCA\Mcp\Modules\Mail\Reading\MailSearchListing;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The folder list is inbox first, then path, and it does not stop at the search cap of 100. */
final class MailMeetingFoldersTest extends TestCase {
	private bool $granted = true;

	/**
	 * Inbox comes first inside an account, then the path. An unowned account, an uncached folder, a folder that
	 * cannot be selected and a locked folder are left out.
	 *
	 * @return void
	 */
	public function testFoldersAreInboxFirstAndSkipWhatASearchCannotRead(): void {
		$first = new \stdClass();
		$second = new \stdClass();
		$accounts = $this->createMock(MailGateway::class);
		$accounts->method('accounts')->willReturn([
			['id' => 1, 'name' => 'A', 'email' => 'a@example.com'],
			['id' => 9, 'name' => 'Other', 'email' => 'o@example.com'],
			['id' => 2, 'name' => 'B', 'email' => 'b@example.com'],
		]);
		$accounts->method('ownedAccount')->willReturnCallback(static fn (string $uid, int $id): ?object => match ($id) {
			1 => $first,
			2 => $second,
			default => null,
		});
		$messages = $this->createMock(MailMessageGateway::class);
		$messages->expects($this->exactly(2))->method('mailboxes')->willReturnCallback(static function (string $uid, object $account) use ($first, $second): array {
			if ($account === $first) {
				return [
					new CachedFolder(5, 'Archive'),
					new CachedFolder(2, 'INBOX'),
					new CachedFolder(9, 'Drafts', false),
					new CachedFolder(8, 'Hidden', true, false),
					new CachedFolder(7, 'Busy', true, true, true),
				];
			}
			self::assertSame($second, $account);
			return [new CachedFolder(1, 'Zebra'), new CachedFolder(3, 'alpha')];
		});

		self::assertSame([2, 5, 3, 1], $this->port($accounts, $messages)->folders('alice'));
	}

	/**
	 * More than 100 readable folders are returned. The caller cuts them; this list does not fail the source.
	 *
	 * @return void
	 */
	public function testMoreThanOneHundredFoldersAreListedWithoutFailing(): void {
		$owned = new \stdClass();
		$boxes = [];
		for ($id = 1; $id <= 100; $id++) {
			$boxes[] = new CachedFolder($id, sprintf('Box%03d', $id));
		}
		$boxes[] = new CachedFolder(200, 'INBOX');
		$accounts = $this->createMock(MailGateway::class);
		$accounts->method('accounts')->willReturn([['id' => 1, 'name' => 'A', 'email' => 'a@example.com']]);
		$accounts->method('ownedAccount')->willReturn($owned);
		$messages = $this->createMock(MailMessageGateway::class);
		$messages->method('mailboxes')->willReturn($boxes);

		$ids = $this->port($accounts, $messages)->folders('alice');

		self::assertCount(101, $ids);
		self::assertSame(200, $ids[0]);
		self::assertContains(100, $ids);
	}

	/**
	 * A missing mail grant refuses the folder list before any account is read.
	 *
	 * @return void
	 */
	public function testADeniedGrantNeverListsFolders(): void {
		$this->granted = false;
		$accounts = $this->createMock(MailGateway::class);
		$accounts->expects($this->never())->method('accounts');
		$messages = $this->createMock(MailMessageGateway::class);
		$messages->expects($this->never())->method('mailboxes');

		try {
			$this->port($accounts, $messages)->folders('alice');
			self::fail('A denied grant listed folders');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('mail_search_messages', $error->getMessage());
		}
	}

	/**
	 * @param MailGateway $accounts the actor's accounts
	 * @param MailMessageGateway $messages the cached folders
	 * @return MailMeetingSearch the port under test
	 */
	private function port(MailGateway $accounts, MailMessageGateway $messages): MailMeetingSearch {
		$search = $this->createMock(MailSearchListing::class);
		return new MailMeetingSearch($this->access(), $search, $accounts, $messages);
	}

	/**
	 * @return ToolAccess the registry gate, granted unless this test closed it
	 */
	private function access(): ToolAccess {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(fn (): bool => $this->granted);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (): IUser => $this->createMock(IUser::class));
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturn(true);
		return new ToolAccess($policy, $apps, $users, $guests, new CapabilityRegistry([], new NullLogger()));
	}
}

/** One cached folder, with the getters the meeting folder list reads. */
final class CachedFolder {
	/**
	 * @param int $id the folder id
	 * @param string $name the folder path
	 * @param bool $cached whether the Mail app has synchronized it
	 * @param bool $selectable whether a search may open it
	 * @param bool $locked whether a synchronization lock is held
	 */
	public function __construct(
		private int $id,
		private string $name,
		private bool $cached = true,
		private bool $selectable = true,
		private bool $locked = false,
	) {
	}

	/**
	 * @return int the folder id
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * @return string the folder path
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @return string the path delimiter
	 */
	public function getDelimiter(): string {
		return '/';
	}

	/**
	 * @return string the special-use list, empty
	 */
	public function getSpecialUse(): string {
		return '[]';
	}

	/**
	 * @return bool whether a search may open the folder
	 */
	public function getSelectable(): bool {
		return $this->selectable;
	}

	/**
	 * @return bool whether the folder is in the cache
	 */
	public function isCached(): bool {
		return $this->cached;
	}

	/**
	 * @param int $now the current Unix time
	 * @return bool whether a synchronization lock is held
	 */
	public function hasLocks(int $now): bool {
		return $this->locked;
	}
}
