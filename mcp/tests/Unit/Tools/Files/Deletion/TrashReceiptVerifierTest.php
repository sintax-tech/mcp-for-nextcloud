<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Tools\Files\Deletion\TrashReceiptMessages;
use OCA\Mcp\Tools\Files\Deletion\TrashReceiptVerifier;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The receipt only reports what the actor's own trash bin shows at the moment of the check. */
final class TrashReceiptVerifierTest extends TestCase {
	/** Message of the caller when the destination stays unconfirmed. */
	private const DEFAULT = 'default';

	/**
	 * @param Node|\Throwable|null $found What the trash manager answers, or throws.
	 * @param bool $enabled Whether files_trashbin is enabled for the actor.
	 * @return TrashReceiptVerifier Verifier over doubles of the official services.
	 */
	private function verifier(Node|\Throwable|null $found, bool $enabled = true): TrashReceiptVerifier {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($enabled);
		$manager = new class($found) {
			/** @param Node|\Throwable|null $found Prepared answer. */
			public function __construct(private Node|\Throwable|null $found) {
			}

			/** @return Node|null Prepared answer. */
			public function getTrashNodeById(IUser $user, int $id): ?Node {
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}
				return $this->found;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\Files_Trashbin\\Trash\\ITrashManager')->willReturn($manager);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-30T12:00:00+00:00'));
		return new TrashReceiptVerifier($apps, $users, $container, $time);
	}

	/**
	 * @param class-string<Node> $type Node interface to double.
	 * @param int $id File ID reported by the trash bin.
	 * @param string $path Absolute path reported by the trash bin.
	 * @return Node Trash node double.
	 */
	private function node(string $type, int $id, string $path = '/alice/files_trashbin/files/a.txt.d1'): Node {
		$node = $this->createMock($type);
		$node->method('getId')->willReturn($id);
		$node->method('getPath')->willReturn($path);
		return $node;
	}

	/** @return void A file seen in the actor's own trash bin gets the moment of the check and the time-limit warning. */
	public function testOwnFileObservedInTrash(): void {
		$receipt = $this->verifier($this->node(File::class, 7))->receipt(TrashReceiptVerifier::OWN, 'alice', 7, false, self::DEFAULT);
		self::assertSame(['status' => 'removed_observed_in_trash', 'trash' => 'observed_in_own_trash',
			'trashCheckedAt' => '2026-10-30T12:00:00+00:00'], $receipt['fields']);
		self::assertSame(TrashReceiptMessages::observedFile() . ' ' . TrashReceiptMessages::expiry(), $receipt['message']);
		self::assertStringContainsString('Recovery is not guaranteed.', $receipt['message']);
	}

	/** @return void A folder receipt says only the folder itself was checked. */
	public function testOwnFolderObservedInTrash(): void {
		$receipt = $this->verifier($this->node(Folder::class, 8, '/alice/files_trashbin/files/docs.d1'))->receipt(TrashReceiptVerifier::OWN, 'alice', 8, true, self::DEFAULT);
		self::assertSame('observed_in_own_trash', $receipt['fields']['trash']);
		self::assertStringStartsWith(TrashReceiptMessages::observedFolder(), $receipt['message']);
	}

	/** @return void Any doubt keeps the destination unconfirmed and the caller's message unchanged. */
	public function testDoubtfulObservationsStayUnconfirmed(): void {
		$cases = [
			'absent' => $this->verifier(null),
			'other id' => $this->verifier($this->node(File::class, 99)),
			'other type' => $this->verifier($this->node(Folder::class, 7)),
			'outside own trash' => $this->verifier($this->node(File::class, 7, '/pedro/files_trashbin/files/a.txt.d1')),
			'app disabled' => $this->verifier($this->node(File::class, 7), false),
			'manager failure' => $this->verifier(new \RuntimeException('private detail')),
		];
		foreach ($cases as $case => $verifier) {
			self::assertSame(['fields' => ['trash' => 'not_confirmed'], 'message' => self::DEFAULT],
				$verifier->receipt(TrashReceiptVerifier::OWN, 'alice', 7, false, self::DEFAULT), $case);
		}
	}

	/** @return void A received share item never touches users, apps or any trash bin, even when it would be found. */
	public function testReceivedShareNeverConsultsAnyTrash(): void {
		$receipt = $this->silentVerifier()->receipt(TrashReceiptVerifier::OTHER, 'alice', 7, false, self::DEFAULT);
		self::assertSame(['trash' => 'owner_trash_not_checked'], $receipt['fields']);
		self::assertSame(self::DEFAULT . ' ' . TrashReceiptMessages::ownerTrash(), $receipt['message']);
	}

	/** @return void An unknown owner stays unconfirmed, names nobody else and consults no trash bin at all. */
	public function testUnknownOwnerNeverConsultsAnyTrashNorNamesAnotherPerson(): void {
		$receipt = $this->silentVerifier()->receipt(TrashReceiptVerifier::UNKNOWN, 'alice', 7, false, self::DEFAULT);
		self::assertSame(['fields' => ['trash' => 'not_confirmed'], 'message' => self::DEFAULT], $receipt);
		self::assertStringNotContainsString(TrashReceiptMessages::ownerTrash(), $receipt['message']);
	}

	/** @return TrashReceiptVerifier Verifier whose users, apps and container fail the test if touched. */
	private function silentVerifier(): TrashReceiptVerifier {
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::never())->method('get');
		$apps = $this->createMock(IAppManager::class);
		$apps->expects(self::never())->method('isEnabledForUser');
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		return new TrashReceiptVerifier($apps, $users, $container, $this->createMock(ITimeFactory::class));
	}

	/** @return void Own, other and unknown owners are three distinct destinations; a read failure is never another person. */
	public function testDestinationSeparatesOwnOtherAndUnknown(): void {
		$verifier = $this->verifier(null);
		$cases = [['alice', false, TrashReceiptVerifier::OWN], ['pedro', false, TrashReceiptVerifier::OTHER],
			['alice', true, TrashReceiptVerifier::OTHER], ['', false, TrashReceiptVerifier::UNKNOWN]];
		foreach ($cases as [$owner, $shared, $expected]) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($owner);
			$storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
			$storage->method('instanceOfStorage')->willReturn($shared);
			$node = $this->createMock(File::class);
			$node->method('getOwner')->willReturn($user);
			$node->method('getStorage')->willReturn($storage);
			self::assertSame($expected, $verifier->destination($node, 'alice'));
		}
		$ownerless = $this->createMock(File::class);
		$ownerless->method('getOwner')->willReturn(null);
		self::assertSame(TrashReceiptVerifier::UNKNOWN, $verifier->destination($ownerless, 'alice'));
		$broken = $this->createMock(File::class);
		$broken->method('getOwner')->willThrowException(new \RuntimeException('gone'));
		self::assertSame(TrashReceiptVerifier::UNKNOWN, $verifier->destination($broken, 'alice'));
		$storageFailure = $this->createMock(File::class);
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$storageFailure->method('getOwner')->willReturn($alice);
		$storageFailure->method('getStorage')->willThrowException(new \RuntimeException('gone'));
		self::assertSame(TrashReceiptVerifier::UNKNOWN, $verifier->destination($storageFailure, 'alice'));
	}
}
