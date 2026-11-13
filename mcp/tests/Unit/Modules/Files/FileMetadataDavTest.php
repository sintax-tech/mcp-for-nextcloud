<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use DateTime;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Platform\Dav\PinnedDispatch;
use OCA\Mcp\Platform\Dav\Session;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\Comments\CommentsEntityEvent;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Sabre\HTTP\Request;

/**
 * Comments and system tags of a file read through the verbatim native DAV pipeline of NC34 and NC35, as the session's
 * own actor, with the MCP Files gate first; one process per pin so the two sources never meet.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FileMetadataDavTest extends TestCase {
	/** File the actor reaches through MCP and DAV. */
	private const FILE = 42;
	/** File the actor reaches natively but the MCP Files gate hides (scope, exclusion tag or backup). */
	private const HIDDEN = 77;
	/** File the MCP gate admits but the native comments entity does not resolve for the actor. */
	private const NATIVE_REFUSED = 55;
	/** File that does not exist. */
	private const MISSING = 99;
	/** Read marker the actor already had; a read must leave it as it was. */
	private const MARKER = '2026-11-01 10:00:00';

	private MetadataDavNative $native;
	private FileMetadataDav $dav;
	/** @var list<string> read markers observed by the native comment nodes */
	private array $markers = [];
	private ?string $sessionUid = 'alice';

	/** @return iterable<string,array{string}> the supported source pins */
	public static function pins(): iterable {
		yield 'NC34 server v34.0.4' => ['34'];
		yield 'NC35 server v35.0.1' => ['35'];
	}

	#[DataProvider('pins')]
	public function testPinnedSourcesAreVerbatim(string $major): void {
		$manifest = json_decode((string)file_get_contents(MetadataDavNative::DIR . '/sources.json'), true)['versions'][$major];
		self::assertSame([], array_diff(MetadataDavNative::FILES, array_keys($manifest)));
		foreach ($manifest as $file => $pin) {
			self::assertSame($pin['sha256'], hash_file('sha256', MetadataDavNative::DIR . '/' . $major . '/' . $file), $file);
			self::assertStringContainsString('/v' . $major . '.', $pin['source']);
		}
	}

	#[DataProvider('pins')]
	public function testCommentsReportReadsWithoutMovingTheReadMarker(string $major): void {
		$this->boot($major);
		$result = $this->dav->comments('alice', self::FILE, 20, 0);
		self::assertSame(207, $result->status);
		self::assertStringContainsString('first comment', (string)$result->body);
		self::assertStringContainsString('<oc:isUnread>false</oc:isUnread>', (string)$result->body);
		self::assertSame([self::MARKER], array_values(array_unique($this->markers)), 'read marker read, never written');
		self::assertSame(['comments/files/' . self::FILE], $this->native->built);
		self::assertSame('principals/users/alice', $this->native->last?->getPlugin('auth')->getCurrentPrincipal());
		self::assertSame(0, $this->native->backendCalls, 'no Sabre authentication backend ran');
		self::assertFalse(class_exists('OCA\\DAV\\Connector\\Sabre\\Auth', false), 'the session-logout backend is never loaded');
		self::assertSame('alice', $this->sessionUid);
	}

	#[DataProvider('pins')]
	public function testTagsReturnOnlyWhatTheActorMaySee(string $major): void {
		$this->boot($major);
		$result = $this->dav->tags('alice', self::FILE);
		self::assertSame(207, $result->status);
		self::assertStringContainsString('Visible tag', (string)$result->body);
		self::assertStringNotContainsString('Invisible tag', (string)$result->body);
		self::assertSame(0, $this->native->backendCalls);
	}

	#[DataProvider('pins')]
	public function testAnAppPluginThatVetoesWritesLeavesTheReadsAlone(string $major): void {
		$this->boot($major);
		$veto = new VetoPlugin();
		$this->native->appPlugins = [$veto];
		self::assertStringContainsString('first comment', (string)$this->dav->comments('alice', self::FILE, 20, 0)->body);
		self::assertStringContainsString('Visible tag', (string)$this->dav->tags('alice', self::FILE)->body);
		self::assertSame([], $veto->vetoed, 'REPORT and PROPFIND bind and unbind nothing');
		self::assertSame(['REPORT comments/files/' . self::FILE, 'PROPFIND systemtags-relations/files/' . self::FILE], $this->native->methods);
	}

	#[DataProvider('pins')]
	public function testUnreachableFilesAreRefusedAlike(string $major): void {
		$this->boot($major);
		$messages = [];
		foreach ([self::HIDDEN, self::MISSING, self::NATIVE_REFUSED, 0, -1] as $id) {
			foreach (['comments', 'tags'] as $read) {
				try {
					$read === 'comments' ? $this->dav->comments('alice', $id, 20, 0) : $this->dav->tags('alice', $id);
					self::fail('Unreachable file ' . $id . ' read through ' . $read);
				} catch (ToolFailure $error) {
					$messages[] = $error->getMessage();
				}
			}
		}
		self::assertSame([CommonMessages::notFound()], array_values(array_unique($messages)));
		// Only the file the MCP gate admits reaches DAV; the native collections refuse it there.
		self::assertSame(['comments/files/' . self::NATIVE_REFUSED, 'systemtags-relations/files/' . self::NATIVE_REFUSED], $this->native->built);
	}

	#[DataProvider('pins')]
	public function testAnotherActorThanTheSessionIsRefusedBeforeDav(string $major): void {
		$this->boot($major);
		try {
			$this->dav->comments('bob', self::FILE, 20, 0);
			self::fail('Foreign actor accepted');
		} catch (ToolFailure $error) {
			self::assertSame(CommonMessages::notFound(), $error->getMessage());
		}
		self::assertSame([], $this->native->built);
		$server = $this->native->create('comments/files/' . self::FILE);
		$dispatch = new PinnedDispatch(new Session($this->session()));
		foreach (['bob', ''] as $actor) {
			try {
				$dispatch->send($server, $actor, new Request('REPORT', '/remote.php/dav/comments/files/' . self::FILE));
				self::fail('Foreign actor pinned');
			} catch (RuntimeException $error) {
				self::assertSame('DAV session mismatch', $error->getMessage());
			}
		}
		self::assertNull($server->getPlugin('auth')->getCurrentPrincipal());
		$this->sessionUid = null;
		$this->expectExceptionMessage('DAV session mismatch');
		$dispatch->send($server, 'alice', new Request('REPORT', '/remote.php/dav/comments/files/' . self::FILE));
	}

	/** @return iterable<string,array{string,?string}> both pins, with the session switched to another user or lost */
	public static function switchedSessions(): iterable {
		foreach (self::pins() as $name => [$major]) {
			yield $name . ', switched' => [$major, 'bob'];
			yield $name . ', lost' => [$major, null];
		}
	}

	#[DataProvider('switchedSessions')]
	public function testSessionChangedDuringTheDispatchIsRefused(string $major, ?string $next): void {
		$this->boot($major);
		$this->native->afterMethod = function () use ($next): void { $this->sessionUid = $next; };
		foreach (['comments', 'tags'] as $read) {
			$this->sessionUid = 'alice';
			try {
				$read === 'comments' ? $this->dav->comments('alice', self::FILE, 20, 0) : $this->dav->tags('alice', self::FILE);
				self::fail('A read whose session changed during the dispatch returned');
			} catch (ToolFailure $error) {
				self::fail('Refused as a missing file instead of a session mismatch: ' . $error->getMessage());
			} catch (RuntimeException $error) {
				self::assertSame('DAV session mismatch', $error->getMessage());
			}
		}
		self::assertSame(2, count($this->native->built), 'both reads reached DAV and finished the native method');
		self::assertSame(0, $this->native->backendCalls);
	}

	#[DataProvider('pins')]
	public function testWithoutThePinTheNativeBackendsWouldRun(string $major): void {
		$this->boot($major);
		$server = $this->native->create('comments/files/' . self::FILE);
		$request = new Request('PROPFIND', '/remote.php/dav/comments/files/' . self::FILE);
		$request->setBaseUrl('/remote.php/dav/');
		$response = new \Sabre\HTTP\Response();
		$server->sapi = new \OCA\Mcp\Platform\Dav\CapturingSapi();
		try {
			$server->invokeMethod($request, $response, false);
		} catch (\Sabre\DAV\Exception\NotAuthenticated) {
		}
		self::assertSame(1, $this->native->backendCalls, 'control: the recording backend is reachable');
	}

	#[DataProvider('pins')]
	public function testCommentPageIsBoundedBeforeAnyLookup(string $major): void {
		$this->boot($major);
		foreach ([[0, 0], [FileMetadataDav::MAX_COMMENTS + 1, 0], [1, -1]] as [$limit, $offset]) {
			try {
				$this->dav->comments('alice', self::FILE, $limit, $offset);
				self::fail('Page accepted');
			} catch (\InvalidArgumentException) {
			}
		}
		self::assertSame([], $this->native->built);
	}

	/**
	 * @param string $major source pin
	 * @return void the port over the verbatim native pipeline of that pin
	 */
	private function boot(string $major): void {
		MetadataDavNative::load($major);
		$session = $this->session();
		$roots = $this->roots();
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event) use ($roots): void {
			if ($event instanceof CommentsEntityEvent) {
				(new \OCA\Comments\Listener\CommentsEntityEventListener($roots, $this->sessionUid))->handle($event);
			}
		});
		$this->native = new MetadataDavNative($this->comments(), ...$this->tagDoubles(), ...[
			$session, $roots, $this->users(), $this->createMock(IGroupManager::class), $events,
		]);
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturnCallback(static fn ($node): bool => $node->getId() !== self::HIDDEN);
		$this->dav = new FileMetadataDav(new ActorRoot($roots, $session), $guard, $this->native, new PinnedDispatch(new Session($session)), new NullLogger());
	}

	/** @return IUserSession a session that can only be read, never logged in or out */
	private function session(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		foreach ((new ReflectionClass(IUserSession::class))->getMethods() as $method) {
			if (!in_array($method->getName(), ['getUser', 'isLoggedIn'], true)) {
				$session->expects(self::never())->method($method->getName());
			}
		}
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUid === null ? null : $this->user($this->sessionUid));
		$session->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->sessionUid !== null);
		return $session;
	}

	/** @param string $uid account @return IUser the account */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		return $user;
	}

	/** @return IUserManager resolves comment authors */
	private function users(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user($uid));
		return $users;
	}

	/** @return IRootFolder alice's tree: what the MCP gate sees by ID and what the native entity check resolves */
	private function roots(): IRootFolder {
		$file = fn (int $id): File => $this->file($id);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getRelativePath')->willReturnCallback(static fn (string $path): ?string => str_starts_with($path, '/alice/files/') ? substr($path, 12) : null);
		$folder->method('getById')->willReturnCallback(static fn (int $id): array => in_array($id, [self::FILE, self::HIDDEN, self::NATIVE_REFUSED], true) ? [$file($id)] : []);
		$folder->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => in_array($id, [self::FILE, self::HIDDEN], true) ? $file($id) : null);
		$roots = $this->createMock(IRootFolder::class);
		$roots->method('getUserFolder')->willReturnCallback(static fn (string $uid): Folder => $uid === 'alice' ? $folder : throw new \OCP\Files\NotPermittedException());
		return $roots;
	}

	/** @param int $id file ID @return File a readable, writable file */
	private function file(int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn('/alice/files/f' . $id . '.txt');
		$file->method('isReadable')->willReturn(true);
		$file->method('getPermissions')->willReturn(31);
		return $file;
	}

	/** @return ICommentsManager one comment on the file; reads only, every write refused */
	private function comments(): ICommentsManager {
		$manager = $this->createMock(ICommentsManager::class);
		foreach ((new ReflectionClass(ICommentsManager::class))->getMethods() as $method) {
			if (!in_array($method->getName(), ['get', 'getForObject', 'getReadMark', 'resolveDisplayName'], true)) {
				$manager->expects(self::never())->method($method->getName());
			}
		}
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('7');
		$comment->method('getMessage')->willReturn('first comment');
		$comment->method('getVerb')->willReturn('comment');
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getActorId')->willReturn('bob');
		$comment->method('getCreationDateTime')->willReturn(new DateTime('2026-10-31 09:00:00'));
		$comment->method('getLatestChildDateTime')->willReturn(new DateTime('2026-10-31 09:00:00'));
		$comment->method('getObjectType')->willReturn('files');
		$comment->method('getObjectId')->willReturn((string)self::FILE);
		$comment->method('getMentions')->willReturn([]);
		$comment->method('getReactions')->willReturn([]);
		$manager->method('get')->willReturnCallback(static fn (string $id): IComment => $id === '7' ? $comment : throw new \OCP\Comments\NotFoundException());
		$manager->method('getForObject')->willReturnCallback(static fn (string $type, string $id): array => $type === 'files' && $id === (string)self::FILE ? [$comment] : []);
		$manager->method('getReadMark')->willReturnCallback(function (): DateTime {
			$this->markers[] = self::MARKER;
			return new DateTime(self::MARKER);
		});
		return $manager;
	}

	/** @return array{ISystemTagManager, ISystemTagObjectMapper} two tags on the file, one the actor may not see */
	private function tagDoubles(): array {
		$tag = function (string $id, string $name): ISystemTag {
			$tag = $this->createMock(ISystemTag::class);
			$tag->method('getId')->willReturn($id);
			$tag->method('getName')->willReturn($name);
			$tag->method('isUserVisible')->willReturn($id === '1');
			$tag->method('isUserAssignable')->willReturn(true);
			return $tag;
		};
		$all = ['1' => $tag('1', 'Visible tag'), '2' => $tag('2', 'Invisible tag')];
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTagsByIds')->willReturnCallback(static fn (array $ids): array => array_intersect_key($all, array_flip(array_map('strval', $ids))));
		$manager->method('canUserSeeTag')->willReturnCallback(static fn (ISystemTag $tag): bool => $tag->getId() === '1');
		$manager->method('canUserAssignTag')->willReturn(false);
		foreach (['createTag', 'updateTag', 'deleteTags'] as $write) {
			$manager->expects(self::never())->method($write);
		}
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, ['1', '2']));
		foreach (['assignTags', 'unassignTags', 'setObjectIdsForTag'] as $write) {
			$mapper->expects(self::never())->method($write);
		}
		return [$manager, $mapper];
	}
}
