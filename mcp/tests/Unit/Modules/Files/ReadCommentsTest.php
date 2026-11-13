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
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Reading\CommentFeed;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Modules\Files\Reading\ReadCommentsHandler;
use OCA\Mcp\Modules\Files\Tools\ReadComments;
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
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/**
 * files_read_comments over the verbatim native comments pipeline of NC34 and NC35: the page the native REPORT answers,
 * read-only, with the read marker of the reader left as it was; hidden, missing and natively refused nodes alike.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReadCommentsTest extends TestCase {
	/** The reader's marker: older than the reply, newer than the first comment. */
	private const MARKER = '2026-10-31 12:00:00';

	private MetadataDavNative $native;
	private ReadComments $tool;
	/** @var list<array{int,int}> limit and offset of every native page read */
	private array $pages = [];
	private int $markerReads = 0;

	/** @return iterable<string,array{string}> the supported source pins */
	public static function pins(): iterable {
		yield 'NC34 server v34.0.4' => ['34'];
		yield 'NC35 server v35.0.1' => ['35'];
	}

	#[DataProvider('pins')]
	public function testPageCarriesTheNativeCommentsWithoutMovingTheMarker(string $major): void {
		$this->boot($major);
		$page = $this->read(['path' => '/report.txt']);
		self::assertSame(['path' => '/report.txt', 'order' => 'newest_first', 'offset' => 0, 'limit' => 20, 'next_offset' => null], array_diff_key($page, ['comments' => 1]));
		self::assertSame([
			['id' => '8', 'author' => ['type' => 'users', 'id' => 'carol', 'display_name' => 'Carol'], 'created_at' => '2026-11-01T09:30:00Z',
				'text' => 'Agreed, @alice', 'reply_to' => '7', 'verb' => 'comment', 'unread' => true,
				'mentions' => [['type' => 'user', 'id' => 'alice', 'display_name' => 'Alice']]],
			['id' => '7', 'author' => ['type' => 'users', 'id' => 'bob', 'display_name' => 'Bob'], 'created_at' => '2026-10-30T08:00:00Z',
				'text' => 'Please review <b>section 2</b>', 'reply_to' => null, 'verb' => 'comment', 'unread' => false, 'mentions' => []],
		], $page['comments']);
		self::assertSame([[20, 0]], $this->pages);
		self::assertSame(2, $this->markerReads, 'the marker is read for each comment and never written');
		self::assertSame(0, $this->native->backendCalls);
	}

	#[DataProvider('pins')]
	public function testOffsetPagesFollowTheNativeOrder(string $major): void {
		$this->boot($major);
		$first = $this->read(['path' => '/report.txt', 'limit' => 1]);
		self::assertSame(1, $first['next_offset']);
		self::assertSame(['8'], array_column($first['comments'], 'id'));
		$second = $this->read(['path' => '/report.txt', 'limit' => 1, 'offset' => $first['next_offset']]);
		self::assertSame(['7'], array_column($second['comments'], 'id'));
		self::assertSame(2, $second['next_offset'], 'a full page always offers the next one');
		$last = $this->read(['path' => '/report.txt', 'limit' => 1, 'offset' => 2]);
		self::assertSame([], $last['comments']);
		self::assertNull($last['next_offset']);
		self::assertSame([[1, 0], [1, 1], [1, 2]], $this->pages);
	}

	#[DataProvider('pins')]
	public function testHiddenMissingAndNativelyRefusedNodesAreRefusedAlike(string $major): void {
		$this->boot($major);
		$answers = [];
		foreach (['/hidden.txt', '/missing.txt', '/foreign.txt', '/unreadable.txt'] as $path) {
			try {
				$this->read(['path' => $path]);
				self::fail('Read ' . $path);
			} catch (ToolFailure $error) {
				$answers[] = $error->getMessage();
			}
		}
		self::assertSame([CommonMessages::notFound()], array_values(array_unique($answers)));
		self::assertSame(['comments/files/55'], $this->native->built, 'only the node the Files gate admits reaches DAV');
		self::assertSame([], $this->pages);
	}

	public function testOnlyAMultistatusDocumentIsRead(): void {
		$feed = new CommentFeed();
		foreach (['', '<html/>', '<!DOCTYPE x [<!ENTITY e "x">]><d:multistatus xmlns:d="DAV:"/>', 'not xml'] as $body) {
			try {
				$feed->comments($body);
				self::fail('Accepted ' . $body);
			} catch (RuntimeException) {
			}
		}
		self::assertSame([], $feed->comments('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>'));
	}

	public function testDefinitionIsAPagedReadUnderFilesRead(): void {
		$definition = ReadComments::definition();
		self::assertSame(['files_read_comments', 'files', 'read'], [$definition->name, $definition->module, $definition->operation]);
		$schema = $definition->inputSchema;
		self::assertSame(['path'], $schema['required']);
		self::assertSame([1, FileMetadataDav::MAX_COMMENTS, ReadCommentsHandler::DEFAULT_LIMIT], [$schema['properties']['limit']['minimum'], $schema['properties']['limit']['maximum'], $schema['properties']['limit']['default']]);
		self::assertSame(0, $schema['properties']['offset']['minimum']);
	}

	/**
	 * @param array<string,mixed> $arguments tool arguments
	 * @return array<string,mixed> decoded page
	 */
	private function read(array $arguments): array {
		$result = $this->tool->execute($arguments, new ToolContext('alice'));
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param string $major source pin
	 * @return void the tool over the verbatim native pipeline of that pin
	 */
	private function boot(string $major): void {
		MetadataDavNative::load($major);
		$session = $this->session();
		$roots = $this->roots();
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(static function (object $event) use ($roots): void {
			if ($event instanceof CommentsEntityEvent) {
				(new \OCA\Comments\Listener\CommentsEntityEventListener($roots, 'alice'))->handle($event);
			}
		});
		$this->native = new MetadataDavNative($this->comments(), $this->createMock(ISystemTagManager::class), $this->createMock(ISystemTagObjectMapper::class),
			$session, $roots, $this->users(), $this->createMock(IGroupManager::class), $events);
		$guard = $this->createMock(FilesAccessGuard::class);
		$hidden = static fn ($node): bool => $node->getId() === 77;
		$guard->method('isVisible')->willReturnCallback(static fn ($node): bool => !$hidden($node));
		$guard->method('assertVisible')->willReturnCallback(static function ($node) use ($hidden): void {
			if ($hidden($node)) {
				throw new ToolFailure(CommonMessages::notFound());
			}
		});
		$actorRoot = new ActorRoot($roots, $session);
		$dav = new FileMetadataDav($actorRoot, $guard, $this->native, new PinnedDispatch(new Session($session)), new NullLogger());
		$this->tool = new ReadComments(new ReadCommentsHandler(new FilesCallContext($roots, $guard, $actorRoot), $guard, $dav, new CommentFeed()));
	}

	/** @return IUserSession alice's session, which can only be read */
	private function session(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		foreach ((new ReflectionClass(IUserSession::class))->getMethods() as $method) {
			if (!in_array($method->getName(), ['getUser', 'isLoggedIn'], true)) {
				$session->expects(self::never())->method($method->getName());
			}
		}
		$session->method('getUser')->willReturn($this->user('alice'));
		$session->method('isLoggedIn')->willReturn(true);
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

	/**
	 * Alice's tree: report.txt (42) is hers; hidden.txt (77) is hidden by the Files gate; foreign.txt (55) is admitted by
	 * the gate but the native comments entity does not resolve it; unreadable.txt cannot be read; missing.txt is absent.
	 *
	 * @return IRootFolder native root double
	 */
	private function roots(): IRootFolder {
		$files = [];
		foreach (['report.txt' => [42, true], 'hidden.txt' => [77, true], 'foreign.txt' => [55, true], 'unreadable.txt' => [66, false]] as $name => [$id, $readable]) {
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn($id);
			$file->method('getPath')->willReturn('/alice/files/' . $name);
			$file->method('isReadable')->willReturn($readable);
			$files[$name] = $file;
		}
		$byId = static fn (int $id): array => array_values(array_filter($files, static fn (File $file): bool => $file->getId() === $id));
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getRelativePath')->willReturnCallback(static fn (string $path): ?string => str_starts_with($path, '/alice/files/') ? substr($path, 12) : null);
		$folder->method('get')->willReturnCallback(static fn (string $path): File => $files[$path] ?? throw new NotFoundException($path));
		$folder->method('getById')->willReturnCallback($byId);
		$folder->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => $id === 55 ? null : ($byId($id)[0] ?? null));
		$roots = $this->createMock(IRootFolder::class);
		$roots->method('getUserFolder')->willReturn($folder);
		return $roots;
	}

	/** @return ICommentsManager two comments on report.txt, newest first; reads only */
	private function comments(): ICommentsManager {
		$manager = $this->createMock(ICommentsManager::class);
		foreach ((new ReflectionClass(ICommentsManager::class))->getMethods() as $method) {
			if (!in_array($method->getName(), ['get', 'getForObject', 'getReadMark', 'resolveDisplayName'], true)) {
				$manager->expects(self::never())->method($method->getName());
			}
		}
		$comment = function (string $id, string $parent, string $actor, string $message, string $date, array $mentions): IComment {
			$comment = $this->createMock(IComment::class);
			$comment->method('getId')->willReturn($id);
			$comment->method('getParentId')->willReturn($parent);
			$comment->method('getTopmostParentId')->willReturn($parent);
			$comment->method('getMessage')->willReturn($message);
			$comment->method('getVerb')->willReturn('comment');
			$comment->method('getActorType')->willReturn('users');
			$comment->method('getActorId')->willReturn($actor);
			$comment->method('getCreationDateTime')->willReturn(new DateTime($date));
			$comment->method('getLatestChildDateTime')->willReturn(new DateTime($date));
			$comment->method('getObjectType')->willReturn('files');
			$comment->method('getObjectId')->willReturn('42');
			$comment->method('getMentions')->willReturn($mentions);
			$comment->method('getReactions')->willReturn([]);
			return $comment;
		};
		$all = [
			'8' => $comment('8', '7', 'carol', 'Agreed, @alice', '2026-11-01 09:30:00', [['type' => 'user', 'id' => 'alice']]),
			'7' => $comment('7', '0', 'bob', 'Please review <b>section 2</b>', '2026-10-30 08:00:00', []),
		];
		$manager->method('get')->willReturnCallback(static fn (string $id): IComment => $all[$id] ?? throw new \OCP\Comments\NotFoundException());
		$manager->method('getForObject')->willReturnCallback(function (string $type, string $id, $limit = 0, $offset = 0) use ($all): array {
			$limit = (int)$limit;
			$offset = (int)$offset;
			$this->pages[] = [$limit, $offset];
			return $type === 'files' && $id === '42' ? array_slice(array_values($all), $offset, $limit) : [];
		});
		$manager->method('getReadMark')->willReturnCallback(function (): DateTime {
			$this->markerReads++;
			return new DateTime(self::MARKER);
		});
		$manager->method('resolveDisplayName')->willReturnCallback(static fn (string $type, string $id): string => ucfirst($id));
		return $manager;
	}
}
