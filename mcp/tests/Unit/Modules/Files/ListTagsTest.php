<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Modules\Files\Tagging\ListTagsHandler;
use OCA\Mcp\Modules\Files\Tools\ListTags;
use OCA\Mcp\Platform\Dav\PinnedDispatch;
use OCA\Mcp\Platform\Dav\Session;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * files_list_tags over the verbatim native DAV tag relation of NC34 and NC35: the native collection decides which tags
 * the actor sees, the MCP exclusion tags never come out, and every unreachable path gets the same refusal.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ListTagsTest extends TestCase {
	/** File with tags the actor reaches through MCP and DAV. */
	private const FILE = 42;
	/** File the MCP Files gate hides. */
	private const HIDDEN = 77;
	/** File the MCP gate admits but the native tag relation refuses (not in the actor's user folder by ID). */
	private const NATIVE_REFUSED = 55;
	/** File the actor cannot read. */
	private const UNREADABLE = 66;
	/** File whose relation points to a tag that no longer exists. */
	private const DANGLING = 88;
	/** File reachable at its own path and also through an alias the MCP Files gate hides. */
	private const ALIASED = 43;
	/** Tag the actor sees and may assign. */
	private const SEEN = '1';
	/** Tag the native canUserSeeTag refuses to the actor. */
	private const UNSEEN = '2';
	/** Tag the actor sees but the administrator selected to hide files from MCP. */
	private const EXCLUSION = '3';
	/** Tag the actor sees as an administrator although it is not user-visible. */
	private const RESTRICTED = '4';

	private ListTagsHandler $handler;
	private MetadataDavNative $native;

	/** @return iterable<string,array{string}> the supported source pins */
	public static function pins(): iterable {
		yield 'NC34 server v34.0.4' => ['34'];
		yield 'NC35 server v35.0.1' => ['35'];
	}

	#[DataProvider('pins')]
	public function testListsOnlyNativeVisibleTagsWithoutTheExclusionTags(string $major): void {
		$this->boot($major);
		$result = (new ListTags($this->handler))->execute(['path' => '/f42.txt'], new ToolContext('alice'));
		$text = $result['content'][0]['text'];
		self::assertSame(['path' => '/f42.txt', 'tags' => [
			['id' => self::SEEN, 'name' => 'Seen tag', 'user_visible' => true, 'user_assignable' => true, 'can_assign' => true],
			['id' => self::RESTRICTED, 'name' => 'Restricted tag', 'user_visible' => false, 'user_assignable' => false, 'can_assign' => false],
		]], json_decode($text, true, 512, JSON_THROW_ON_ERROR));
		foreach (['Unseen tag', 'Exclusion tag', '"' . self::UNSEEN . '"', '"' . self::EXCLUSION . '"'] as $leak) {
			self::assertStringNotContainsString($leak, $text);
		}
		self::assertSame(['systemtags-relations/files/' . self::FILE], $this->native->built);
		self::assertSame('principals/users/alice', $this->native->last?->getPlugin('auth')->getCurrentPrincipal());
		self::assertSame(0, $this->native->backendCalls, 'no Sabre authentication backend ran');
	}

	#[DataProvider('pins')]
	public function testUnreachablePathsAreRefusedAlike(string $major): void {
		$this->boot($major);
		$messages = [];
		foreach (['/f77.txt', '/missing.txt', '/f66.txt', '/f55.txt', '/f43.txt', '../f42.txt'] as $path) {
			try {
				$this->handler->handle(['path' => $path], 'alice');
				self::fail('Unreachable path ' . $path . ' listed');
			} catch (ToolFailure $error) {
				$messages[] = $error->getMessage();
			} catch (\InvalidArgumentException) {
				$messages[] = 'traversal';
			}
		}
		// Traversal is an input error, as for every Files path; everything else is the not-found refusal.
		self::assertSame([CommonMessages::notFound(), 'traversal'], array_values(array_unique($messages)));
		// Only the file the MCP gate admits reaches DAV; the native relation refuses it there.
		self::assertSame(['systemtags-relations/files/' . self::NATIVE_REFUSED], $this->native->built);
	}

	#[DataProvider('pins')]
	public function testDanglingTagFailsWithoutNamingAnyTag(string $major): void {
		$this->boot($major);
		try {
			$this->handler->handle(['path' => '/f88.txt'], 'alice');
			self::fail('Dangling relation listed');
		} catch (RuntimeException $error) {
			// Not a ToolFailure: the registry answers the generic unexpected error and logs only class and code.
			self::assertNotInstanceOf(ToolFailure::class, $error);
			for ($e = $error; $e !== null; $e = $e->getPrevious()) {
				self::assertStringNotContainsString('Seen tag', $e->getMessage());
			}
		}
	}

	#[DataProvider('pins')]
	public function testAnotherActorThanTheSessionIsRefusedBeforeDav(string $major): void {
		$this->boot($major);
		try {
			$this->handler->handle(['path' => '/f42.txt'], 'bob');
			self::fail('Foreign actor accepted');
		} catch (\Throwable $error) {
			self::assertStringNotContainsString('Seen tag', $error->getMessage());
		}
		self::assertSame([], $this->native->built);
	}

	/**
	 * @param string $major source pin
	 * @return void the handler over the verbatim native pipeline of that pin
	 */
	private function boot(string $major): void {
		MetadataDavNative::load($major);
		$session = $this->createMock(IUserSession::class);
		foreach ((new \ReflectionClass(IUserSession::class))->getMethods() as $method) {
			if (!in_array($method->getName(), ['getUser', 'isLoggedIn'], true)) {
				$session->expects(self::never())->method($method->getName());
			}
		}
		$session->method('getUser')->willReturn($this->user('alice'));
		$session->method('isLoggedIn')->willReturn(true);
		$roots = $this->roots();
		$this->native = new MetadataDavNative($this->createMock(ICommentsManager::class), ...$this->tagDoubles(), ...[
			$session, $roots, $this->createMock(IUserManager::class), $this->createMock(IGroupManager::class), $this->createMock(IEventDispatcher::class),
		]);
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturnCallback(static fn ($node): bool => $node->getId() !== self::HIDDEN && !str_contains($node->getPath(), '/Hidden/'));
		$guard->method('assertVisible')->willReturnCallback(static function ($node): void {
			if ($node->getId() === self::HIDDEN) {
				throw new ToolFailure(CommonMessages::notFound());
			}
		});
		$guard->method('getHiddenTagIds')->willReturn([self::EXCLUSION, '900']);
		$actorRoot = new ActorRoot($roots, $session);
		$dav = new FileMetadataDav($actorRoot, $guard, $this->native, new PinnedDispatch(new Session($session)), new NullLogger());
		$this->handler = new ListTagsHandler(new FilesCallContext($roots, $guard, $actorRoot), $guard, $dav);
	}

	/** @param string $uid account @return IUser the account */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/** @return IRootFolder alice's tree, by path for MCP and by ID for MCP and the native relation */
	private function roots(): IRootFolder {
		$known = [self::FILE, self::HIDDEN, self::NATIVE_REFUSED, self::UNREADABLE, self::DANGLING, self::ALIASED];
		$file = fn (int $id): File => $this->file($id);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getRelativePath')->willReturnCallback(static fn (string $path): ?string => str_starts_with($path, '/alice/files/') ? substr($path, 12) : null);
		$folder->method('get')->willReturnCallback(static function (string $path) use ($known, $file): File {
			$id = preg_match('/^f(\d+)\.txt$/', $path, $m) === 1 ? (int)$m[1] : 0;
			return in_array($id, $known, true) ? $file($id) : throw new NotFoundException();
		});
		$folder->method('getById')->willReturnCallback(fn (int $id): array => match (true) {
			$id === self::ALIASED => [$file($id), $this->file($id, '/alice/files/Hidden/f' . $id . '.txt')],
			in_array($id, $known, true) => [$file($id)],
			default => [],
		});
		$folder->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => in_array($id, $known, true) && $id !== self::NATIVE_REFUSED ? $file($id) : null);
		$roots = $this->createMock(IRootFolder::class);
		$roots->method('getUserFolder')->willReturnCallback(static fn (string $uid): Folder => $uid === 'alice' ? $folder : throw new \OCP\Files\NotPermittedException());
		return $roots;
	}

	/**
	 * @param int $id file ID
	 * @param string|null $path absolute path, the file's own one by default
	 * @return File a file, readable unless it is the unreadable one
	 */
	private function file(int $id, ?string $path = null): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path ?? '/alice/files/f' . $id . '.txt');
		$file->method('isReadable')->willReturn($id !== self::UNREADABLE);
		$file->method('getPermissions')->willReturn(31);
		return $file;
	}

	/** @return array{ISystemTagManager, ISystemTagObjectMapper} the native tag services; reads only, every write refused */
	private function tagDoubles(): array {
		$tag = function (string $id, string $name, bool $visible, bool $assignable): ISystemTag {
			$tag = $this->createMock(ISystemTag::class);
			$tag->method('getId')->willReturn($id);
			$tag->method('getName')->willReturn($name);
			$tag->method('isUserVisible')->willReturn($visible);
			$tag->method('isUserAssignable')->willReturn($assignable);
			return $tag;
		};
		$all = [
			self::SEEN => $tag(self::SEEN, 'Seen tag', true, true),
			self::UNSEEN => $tag(self::UNSEEN, 'Unseen tag', false, false),
			self::EXCLUSION => $tag(self::EXCLUSION, 'Exclusion tag', true, false),
			self::RESTRICTED => $tag(self::RESTRICTED, 'Restricted tag', false, false),
		];
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTagsByIds')->willReturnCallback(static function (array $ids) use ($all): array {
			$ids = array_map('strval', $ids);
			return array_diff($ids, array_keys($all)) === [] ? array_intersect_key($all, array_flip($ids)) : throw new TagNotFoundException('Tag id(s) not found', 0, null, $ids);
		});
		$manager->method('canUserSeeTag')->willReturnCallback(static fn (ISystemTag $tag): bool => $tag->getId() !== self::UNSEEN);
		$manager->method('canUserAssignTag')->willReturnCallback(static fn (ISystemTag $tag): bool => $tag->getId() === self::SEEN);
		foreach (['createTag', 'updateTag', 'deleteTags'] as $write) {
			$manager->expects(self::never())->method($write);
		}
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, (int)$ids[0] === self::DANGLING ? [self::SEEN, '9'] : [self::SEEN, self::UNSEEN, self::EXCLUSION, self::RESTRICTED]));
		foreach (['assignTags', 'unassignTags', 'setObjectIdsForTag'] as $write) {
			$mapper->expects(self::never())->method($write);
		}
		return [$manager, $mapper];
	}
}
