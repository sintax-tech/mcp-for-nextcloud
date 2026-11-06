<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Modules\Files\Deletion\DeleteEligibility;
use OCA\Mcp\Modules\Files\Deletion\DeleteMessages;
use OCA\Mcp\Modules\Files\Deletion\DeletePlanText;
use OCA\Mcp\Modules\Files\Deletion\DeleteTrashVeto;
use OCA\Mcp\Modules\Files\Deletion\TrashReceiptMessages;
use OCA\Mcp\Modules\Files\Deletion\TrashReceiptVerifier;
use OCA\Mcp\Modules\Files\Handler\DeleteFileHandler;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The existing deletion sink composes the trash receipt without changing what is deleted or refused. */
final class DeleteTrashReceiptTest extends TestCase {
	/** @var FakeTree Native-shaped source view recording every mutation. */
	private FakeTree $tree;
	/** @var list<string> Trash lookups made, as "uid:id". */
	private array $lookups = [];

	/** @return void Prepare one own file and one received share descendant. */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFile('/alice/files/a.txt', 'original', 'text/plain');
		$this->tree->addFile('/alice/files/b.txt', 'theirs', 'text/plain', ['scope' => 'shared']);
	}

	/**
	 * @param string $path Source the eligibility double resolves.
	 * @param \Closure(): mixed $manager Resolves the trash manager, or throws.
	 * @param \Closure(): \OCP\Files\Node|null $source Replaces the resolved source node, when given.
	 * @return DeleteFileHandler Handler with the real verifier over doubles of the official services.
	 */
	private function handler(string $path, \Closure $manager, ?\Closure $source = null): DeleteFileHandler {
		$eligibility = $this->createMock(DeleteEligibility::class);
		$eligibility->method('file')->willReturnCallback($source ?? fn () => $this->tree->node($path));
		$locks = $this->createMock(LockAwareWrite::class);
		$locks->method('run')->willReturnCallback(static fn ($work) => $work());
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback($manager);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-30T12:00:00+00:00'));
		return new DeleteFileHandler($eligibility, $locks, new \Psr\Log\NullLogger(),
			new DeleteTrashVeto($this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)),
			new TrashReceiptVerifier($apps, $users, $container, $time));
	}

	/** @return \Closure(): object Trash manager double that finds any requested ID in the requester's trash bin. */
	private function finder(): \Closure {
		$find = function (IUser $user, int $id): File {
			$this->lookups[] = $user->getUID() . ':' . $id;
			$node = $this->createMock(File::class);
			$node->method('getId')->willReturn($id);
			$node->method('getPath')->willReturn('/' . $user->getUID() . '/files_trashbin/files/x.d1');
			return $node;
		};
		return static fn (): object => new class($find) {
			/** @param \Closure(IUser, int): File $find Records and answers each lookup. */
			public function __construct(private \Closure $find) {
			}

			/** @return File Node found in the requester's own trash bin. */
			public function getTrashNodeById(IUser $user, int $id): File {
				return ($this->find)($user, $id);
			}
		};
	}

	/**
	 * @param array<string, mixed> $result MCP envelope.
	 * @return array<string, mixed> Decoded receipt.
	 */
	private static function data(array $result): array {
		return json_decode($result['content'][0]['text'], true);
	}

	/** @return void Own file: removed once and confirmed in the actor's trash bin at the moment of the check. */
	public function testOwnFileReceiptIsObservedInOwnTrash(): void {
		$id = $this->tree->nodes['/alice/files/a.txt']['id'];
		$result = $this->handler('/alice/files/a.txt', $this->finder())->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		$data = self::data($result);
		self::assertArrayNotHasKey('isError', $result);
		self::assertSame('removed_observed_in_trash', $data['status']);
		self::assertSame('observed_in_own_trash', $data['trash']);
		self::assertSame('2026-10-30T12:00:00+00:00', $data['trashCheckedAt']);
		self::assertFalse($data['recoveryConfirmed']);
		self::assertStringContainsString(TrashReceiptMessages::expiry(), $data['message']);
		self::assertSame(['alice:' . $id], $this->lookups);
		self::assertSame(['delete /alice/files/a.txt'], $this->tree->ops);
	}

	/** @return void Received share: deleted as before, destination not confirmed, and no trash bin is ever consulted. */
	public function testReceivedShareNeverConsultsTheOwnerTrash(): void {
		$consulted = false;
		$handler = $this->handler('/alice/files/b.txt', function () use (&$consulted): object {
			$consulted = true;
			return ($this->finder())();
		});
		$plan = $handler->plan($this->tree->node('/alice/files'), 'alice', ['path' => '/b.txt']);
		self::assertSame('owner_trash_not_checked', $plan['trashDestination']);
		self::assertStringContainsString(TrashReceiptMessages::ownerTrash(), $plan['message']);
		self::assertContains(TrashReceiptMessages::ownerTrash(), DeletePlanText::lines($plan));
		$data = self::data($handler->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/b.txt', 'confirm_shared' => true]));
		self::assertSame('removed_destination_unconfirmed', $data['status']);
		self::assertSame('owner_trash_not_checked', $data['trash']);
		self::assertStringContainsString(TrashReceiptMessages::ownerTrash(), $data['message']);
		self::assertFalse($consulted, 'The trash manager must not be resolved for a received share item');
		self::assertSame([], $this->lookups);
		self::assertSame(['delete /alice/files/b.txt'], $this->tree->ops);
	}

	/** @return void A failing verification keeps the measured removal and the unconfirmed message. */
	public function testVerificationFailureDoesNotBreakTheDeletion(): void {
		$handler = $this->handler('/alice/files/a.txt', static function (): never {
			throw new \RuntimeException('private detail');
		});
		$result = $handler->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		$data = self::data($result);
		self::assertArrayNotHasKey('isError', $result);
		self::assertSame('removed_destination_unconfirmed', $data['status']);
		self::assertSame('not_confirmed', $data['trash']);
		self::assertSame(DeleteMessages::removed() . ' ' . DeleteMessages::risk(), $data['message']);
		self::assertStringNotContainsString('private detail', $result['content'][0]['text']);
		self::assertSame(['delete /alice/files/a.txt'], $this->tree->ops);
	}

	/** @return void The own-file plan names the try-the-trash-bin-first risk and no owner limitation. */
	public function testOwnPlanStatesTryTheTrashBinFirst(): void {
		$plan = $this->handler('/alice/files/a.txt', $this->finder())->plan($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		self::assertSame('own_trash', $plan['trashDestination']);
		self::assertStringStartsWith('Try the trash bin first: Nextcloud tries to move the item to the trash bin and may permanently delete it if that fails', $plan['message']);
		self::assertStringNotContainsString(TrashReceiptMessages::ownerTrash(), $plan['message']);
		self::assertSame([], $this->tree->ops);
	}

	/** @return void A removed folder gets the observed receipt, while a surviving one ignores the trash bin. */
	public function testFolderOutcomeComposesTheReceiptOnlyAfterRemoval(): void {
		$trash = static fn (string $default): array => ['fields' => ['status' => 'removed_observed_in_trash', 'trash' => 'observed_in_own_trash'],
			'message' => TrashReceiptMessages::observedFolder()];
		$removed = self::data(\OCA\Mcp\Modules\Files\Deletion\Folder\DeleteFolderOutcome::observe($this->tree->node('/alice/files'), '/gone', 42, 'e', false, [],
			static fn (): array => [], $trash));
		self::assertSame('removed_observed_in_trash', $removed['status']);
		self::assertSame(TrashReceiptMessages::observedFolder(), $removed['message']);
		$this->tree->addFolder('/alice/files/kept');
		$kept = $this->tree->node('/alice/files/kept');
		$result = \OCA\Mcp\Modules\Files\Deletion\Folder\DeleteFolderOutcome::observe($this->tree->node('/alice/files'), '/kept', (int)$kept->getId(),
			$kept->getEtag(), false, [], static fn (): array => [], static fn (): never => throw new \LogicException('must not be called'));
		self::assertSame('preserved', self::data($result)['status']);
		self::assertArrayNotHasKey('trash', self::data($result));
	}

	/** @return void An unreadable owner: neutral plan and receipt, nobody else named, no trash bin consulted, deletion unchanged. */
	public function testUnknownOwnerIsNeutralAndConsultsNoTrash(): void {
		$consulted = false;
		$source = function (): File {
			$real = $this->tree->node('/alice/files/a.txt');
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn($real->getId());
			$file->method('getEtag')->willReturn($real->getEtag());
			$file->method('getPath')->willReturn($real->getPath());
			$file->method('getSize')->willReturn($real->getSize());
			$file->method('getOwner')->willThrowException(new \RuntimeException('owner unreadable'));
			$file->method('delete')->willReturnCallback(static fn () => $real->delete());
			return $file;
		};
		$handler = $this->handler('/alice/files/a.txt', function () use (&$consulted): object {
			$consulted = true;
			return ($this->finder())();
		}, $source);
		$plan = $handler->plan($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']);
		self::assertSame('not_confirmed', $plan['trashDestination']);
		self::assertStringContainsString(TrashReceiptMessages::unknownTrash(), $plan['message']);
		self::assertStringNotContainsString(TrashReceiptMessages::ownerTrash(), $plan['message']);
		self::assertContains(TrashReceiptMessages::unknownTrash(), DeletePlanText::lines($plan));
		$data = self::data($handler->handle($this->tree->node('/alice/files'), 'alice', ['path' => '/a.txt']));
		self::assertSame('removed_destination_unconfirmed', $data['status']);
		self::assertSame('not_confirmed', $data['trash']);
		self::assertSame(DeleteMessages::removed() . ' ' . DeleteMessages::risk(), $data['message']);
		self::assertFalse($consulted, 'No trash bin may be consulted when the owner is unknown');
		self::assertSame([], $this->lookups);
		self::assertSame(['delete /alice/files/a.txt'], $this->tree->ops);
	}
}
