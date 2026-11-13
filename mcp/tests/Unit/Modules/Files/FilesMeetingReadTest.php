<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Reading\FilesMeetingRead;
use OCA\Mcp\Modules\Files\Reading\FileTextListing;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** files_read is the only read the meeting dossier may ask of Files, and only for one path. */
final class FilesMeetingReadTest extends TestCase {
	private bool $granted = true;
	private bool $guestAllowed = true;
	private bool $unavailable = false;
	/** @var list<array{string, string, list<string>}> */
	private array $asked = [];
	/** @var list<array{string, string}> */
	private array $guestApps = [];

	/**
	 * A missing files.read grant refuses the port before the reader runs.
	 *
	 * @return void
	 */
	public function testADeniedGrantNeverReachesTheReader(): void {
		$this->granted = false;
		$files = new CountingFileRead();
		$read = new FilesMeetingRead($this->access(), $files);

		try {
			$read->read('alice', ['path' => '/Notes/agenda.md']);
			self::fail('A denied grant reached the reader');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('files_read', $error->getMessage());
		}
		self::assertSame(0, $files->calls);
		self::assertSame([['alice', 'files', ['read']]], $this->asked);
	}

	/**
	 * A guest whom Guests keeps out of Files never reaches the reader.
	 *
	 * @return void
	 */
	public function testAGuestTheAppForbidsNeverReachesTheReader(): void {
		$this->guestAllowed = false;
		$files = new CountingFileRead();
		$read = new FilesMeetingRead($this->access(), $files);

		try {
			$read->read('alice', ['path' => '/Notes/agenda.md']);
			self::fail('A forbidden guest reached the reader');
		} catch (ToolFailure) {
			self::assertSame(0, $files->calls);
		}
		self::assertContains(['alice', 'files'], $this->guestApps);
	}

	/**
	 * An allowed call validates the path and reads it once.
	 *
	 * @return void
	 */
	public function testAnAllowedCallValidatesThenReadsThePathOnce(): void {
		$files = new CountingFileRead();
		$read = new FilesMeetingRead($this->access(), $files);

		$result = $read->read('alice', ['path' => '/Notes/agenda.md']);

		self::assertSame(['text' => 'agenda'], $result);
		self::assertSame(1, $files->calls);
		self::assertSame('alice', $files->userId);
		self::assertSame('/Notes/agenda.md', $files->arguments['path']);
		self::assertSame([['alice', 'files', ['read']]], $this->asked);
		self::assertContains(['alice', 'files'], $this->guestApps);
	}

	/**
	 * A call without a path is refused before the reader.
	 *
	 * @return void
	 */
	public function testAMissingPathIsRefusedBeforeTheReader(): void {
		$files = new CountingFileRead();
		$read = new FilesMeetingRead($this->access(), $files);

		try {
			$read->read('alice', []);
			self::fail('A missing path reached the reader');
		} catch (InvalidArgumentException) {
			self::assertSame(0, $files->calls);
		}
	}

	/**
	 * A grant that cannot be read propagates and the reader is not called.
	 *
	 * @return void
	 */
	public function testAnUnreadableGrantPropagatesAndSkipsTheReader(): void {
		$this->unavailable = true;
		$files = new CountingFileRead();
		$read = new FilesMeetingRead($this->access(), $files);

		$this->expectException(PolicyUnavailable::class);
		try {
			$read->read('alice', ['path' => '/Notes/agenda.md']);
		} finally {
			self::assertSame(0, $files->calls);
		}
	}

	/**
	 * The registry gate of files_read, with the grant and guest answers of this test.
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
		$apps->method('isEnabledForUser')->willReturn(true);
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

/** Counts files_read. The port types this interface, which has no other read. */
final class CountingFileRead implements FileTextListing {
	public int $calls = 0;
	public string $userId = '';
	/** @var array<string, mixed> */
	public array $arguments = [];

	/** {@inheritDoc} */
	public function handle(array $arguments, string $userId): array {
		$this->calls++;
		$this->arguments = $arguments;
		$this->userId = $userId;
		return ['text' => 'agenda'];
	}
}
