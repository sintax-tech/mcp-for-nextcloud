<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use DateTime;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Commenting\AddCommentHandler;
use OCA\Mcp\Modules\Files\Commenting\AddCommentPlan;
use OCA\Mcp\Modules\Files\Commenting\AddCommentPreview;
use OCA\Mcp\Modules\Files\Commenting\CommentMentions;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Reading\CommentFeed;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Modules\Files\Tools\AddComment;
use OCA\Mcp\Platform\Dav\PinnedDispatch;
use OCA\Mcp\Platform\Dav\Session;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Platform\Sharing\ShareableAccount;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Comments\CommentsEntityEvent;
use OCP\Comments\CommentsEvent;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as NotificationManager;
use OCP\Notification\INotification;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Alice's files and their comments behind the verbatim native DAV pipeline of one pin, with files_add_comment built
 * from its real classes. Comments are real `OC\Comments\Comment` objects of that pin; the store records every save and
 * every read-marker write, and the native mention listener of the comments app reports who it notifies.
 */
final class CommentWorld {
	/** Personal file alice comments on. */
	public const OWN = 42;
	/** File bob shared with alice. */
	public const SHARED = 43;
	/** File the MCP Files gate hides. */
	public const HIDDEN = 77;
	/** File MCP admits but the native comments entity does not resolve. */
	public const NATIVE_REFUSED = 55;
	/** Accounts that exist; dave exists but the people search hides him from alice (enumeration restricted). */
	public const ACCOUNTS = ['alice' => 'Alice', 'bob' => 'Bob Lima', 'carol' => 'Carol', 'dave' => 'Dave'];

	public MetadataDavNative $native;
	public AddComment $tool;
	public AddCommentPreview $preview;
	public AddCommentHandler $handler;
	/** @var array<string,list<IComment>> comments by file ID, oldest first */
	public array $comments = [];
	/** @var list<array{string,string,string}> every save, as [file ID, author, message] */
	public array $saves = [];
	/** @var array<string,string> alice's read marker by file ID, as stored */
	public array $markers = [self::OWN => '2026-11-01 10:00:00'];
	/** @var list<array{string,string}> every read-marker write, as [file ID, user] */
	public array $markerWrites = [];
	/** @var list<string> accounts the native mention listener notified */
	public array $notified = [];
	/** @var list<int> files the MCP Files gate hides */
	public array $hidden = [self::HIDDEN];
	/** @var list<int> files shared with alice by bob */
	public array $shared = [self::SHARED];
	/** @var list<string> accounts the people search returns to alice */
	public array $searchable = ['alice', 'bob', 'carol'];
	/** @var list<\Sabre\DAV\ServerPlugin> plugins apps add on the typed SabrePluginAddEvent */
	public array $eventPlugins = [];
	/** @var list<\Sabre\DAV\ServerPlugin> plugins apps add on the legacy addPlugin event */
	public array $legacyPlugins = [];
	/** @var (\Closure(IComment): void)|null run right after a comment is saved: another client acting at the same moment */
	public ?\Closure $afterSave = null;
	/** Whether the next save fails inside the native store. */
	public bool $failSave = false;
	/** Whether alice still holds files.comments. */
	public bool $granted = true;
	/** @var (\Closure(): void)|null run when the guard's cache is cleared: the moment a change since the plan lands */
	public ?\Closure $onClearCache = null;
	private int $nextId = 100;

	/**
	 * @param TestCase $test owner of the doubles
	 * @param string $major source pin, 34 or 35
	 * @param GrantPolicy|null $policy the endpoint's policy; null for a double that reads {@see self::$granted}
	 */
	public function __construct(private TestCase $test, string $major, ?GrantPolicy $policy = null) {
		MetadataDavNative::load($major);
		self::loadNative($major);
		$session = $this->session();
		$roots = $this->roots();
		$users = $this->users();
		$events = $this->events($roots);
		$this->native = new MetadataDavNative($this->manager($users), $this->mock(ISystemTagManager::class), $this->mock(ISystemTagObjectMapper::class),
			$session, $roots, $users, $this->mock(IGroupManager::class), $events);
		$guard = $this->guard();
		$actorRoot = new ActorRoot($roots, $session);
		$dav = new FileMetadataDav($actorRoot, $guard, $this->native, new PinnedDispatch(new Session($session)), new NullLogger());
		$config = $this->mock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$plans = new AddCommentPlan(new FilesCallContext($roots, $guard, $actorRoot), $guard, $dav, new CommentMentions(new ShareableAccount($this->search())), $this->access(),
			new SharedWriteGuard($this->access()), new PlanState($config));
		if ($policy === null) {
			$policy = $this->mock(GrantPolicy::class);
			$policy->method('granted')->willReturnCallback(fn (string $uid, string $module, string $operation): bool => $uid === 'alice'
				&& $module === 'files' && $operation === 'comments' && $this->granted);
		}
		$this->preview = new AddCommentPreview($plans);
		$this->handler = new AddCommentHandler($plans, $policy, $dav, new CommentFeed(), new CommentMentions(new ShareableAccount($this->search())));
		$this->tool = new AddComment($this->handler, $this->preview);
	}

	/**
	 * @param string $major source pin
	 * @return void the verbatim core comment and the comments app mention listener of that pin
	 */
	public static function loadNative(string $major): void {
		foreach (['lib/private/Comments/Comment.php', 'apps/comments/lib/Notification/Listener.php'] as $file) {
			require_once MetadataDavNative::DIR . '/' . $major . '/' . $file;
		}
	}

	/**
	 * @param callable(): mixed $call a call expected to be refused
	 * @return array{string,string,int} class, message and code of the refusal
	 */
	public function refusal(callable $call): array {
		try {
			$call();
		} catch (ToolFailure $error) {
			return [$error::class, $error->getMessage(), $error->getCode()];
		}
		$this->test->fail('The call was not refused');
	}

	/** @return array{string,string,int} the refusal of a missing file, the one every unreachable file must equal */
	public static function notFound(): array {
		return [ToolFailure::class, CommonMessages::notFound(), 0];
	}

	/**
	 * A files_add_comment plan for a file of someone else, with the keys {@see AddCommentPlan} builds, for contracts
	 * that cannot run the native pipeline.
	 *
	 * @return array<string,mixed> the plan
	 */
	public static function samplePlan(): array {
		$access = ['scope' => NodeAccessInfo::SHARED, 'owner' => 'bruno', 'ownerDisplayName' => 'Bruno Lima', 'sharedBy' => 'Bruno Lima',
			'permissions' => ['read' => true, 'update' => false, 'create' => false, 'delete' => false, 'share' => false]];
		return ['action' => 'files_add_comment', 'path' => '/Documentos/ata.md', 'isDir' => false, 'text' => "Revisei a ata, @carol.\nOk para mim.",
			'notifies' => [['id' => 'carol', 'display_name' => 'Carol']], 'read_marker' => ['now' => '2026-11-01T10:00:00Z', 'after' => 'now'],
			'access' => $access, 'shared' => [['requiresConfirmation' => true, 'scope' => 'shared', 'owner' => 'bruno',
				'ownerDisplayName' => 'Bruno Lima', 'sharedBy' => 'Bruno Lima', 'resource' => '/Documentos/ata.md', 'message' => 'Shared by Bruno Lima.']],
			'requiresSharedConfirmation' => true, 'recoverable' => false, 'message' => CommonMessages::planNothingChanged(),
			'plan_state' => str_repeat('0', 64)];
	}

	/**
	 * Adds a comment the way another client of alice would, outside the MCP call.
	 *
	 * @param string $fileId commented file
	 * @param string $text exact text
	 * @return void
	 */
	public function addOwnComment(string $fileId, string $text): void {
		$comment = (new \OC\Comments\Comment())->setActor('users', 'alice')->setObject('files', $fileId)->setMessage($text)->setVerb('comment');
		$comment->setId((string)$this->nextId++);
		$comment->setCreationDateTime(new DateTime('2026-11-13 12:00:00'));
		$this->comments[$fileId][] = $comment;
	}

	/**
	 * @param IRootFolder $roots alice's tree, for the native comments entity listener
	 * @return IEventDispatcher the dispatcher apps listen on: the comments entity, and {@see self::$eventPlugins} and
	 *     {@see self::$legacyPlugins} added to the embedded server
	 */
	private function events(IRootFolder $roots): IEventDispatcher {
		$events = $this->mock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event) use ($roots): void {
			if ($event instanceof CommentsEntityEvent) {
				(new \OCA\Comments\Listener\CommentsEntityEventListener($roots, 'alice'))->handle($event);
			}
			if ($event instanceof \OCA\DAV\Events\SabrePluginAddEvent) {
				foreach ($this->eventPlugins as $plugin) {
					$event->getServer()->addPlugin($plugin);
				}
			}
		});
		$events->method('dispatch')->willReturnCallback(function (string $name, object $event): void {
			if ($name === 'OCA\DAV\Connector\Sabre::addPlugin' && $event instanceof \OCP\SabrePluginEvent) {
				foreach ($this->legacyPlugins as $plugin) {
					$event->getServer()->addPlugin($plugin);
				}
			}
		});
		return $events;
	}

	/** @return ISearch the people search of alice's session: exact hits of {@see self::$searchable} only */
	private function search(): ISearch {
		$search = $this->mock(ISearch::class);
		$search->method('search')->willReturnCallback(fn (string $term, array $types): array => [
			['exact' => ['users' => in_array($term, $this->searchable, true) && in_array(IShare::TYPE_USER, $types, true)
				? [['label' => self::ACCOUNTS[$term], 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => $term]]] : []], 'users' => []],
			false,
		]);
		return $search;
	}

	/** @return IUserSession alice's session, which can only be read */
	private function session(): IUserSession {
		$session = $this->mock(IUserSession::class);
		foreach ((new ReflectionClass(IUserSession::class))->getMethods() as $method) {
			if (!in_array($method->getName(), ['getUser', 'isLoggedIn'], true)) {
				$session->expects(TestCase::never())->method($method->getName());
			}
		}
		$session->method('getUser')->willReturn($this->user('alice'));
		$session->method('isLoggedIn')->willReturn(true);
		return $session;
	}

	/** @param string $uid account @return IUser the account */
	private function user(string $uid): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(self::ACCOUNTS[$uid] ?? $uid);
		return $user;
	}

	/** @return IUserManager the accounts of {@see self::ACCOUNTS}; nothing is created or changed */
	private function users(): IUserManager {
		$users = $this->mock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => isset(self::ACCOUNTS[$uid]));
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => self::ACCOUNTS[$uid] ?? null);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => isset(self::ACCOUNTS[$uid]) ? $this->user($uid) : null);
		foreach (['createUser', 'createUserFromBackend', 'delete'] as $write) {
			if (method_exists(IUserManager::class, $write)) {
				$users->expects(TestCase::never())->method($write);
			}
		}
		return $users;
	}

	/** @return IRootFolder alice's tree: f<ID>.txt for every known file, by path and by ID */
	private function roots(): IRootFolder {
		$known = [self::OWN, self::SHARED, self::HIDDEN, self::NATIVE_REFUSED];
		$folder = $this->mock(Folder::class);
		$folder->method('getId')->willReturn(1);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getRelativePath')->willReturnCallback(static fn (string $path): ?string => str_starts_with($path, '/alice/files/') ? substr($path, 12) : null);
		$folder->method('get')->willReturnCallback(function (string $path) use ($known): File {
			if ($path === 'forbidden.txt') {
				throw new \OCP\Files\ForbiddenException('Blocked by an access control', false);
			}
			$id = preg_match('/^f(\d+)\.txt$/', $path, $m) === 1 ? (int)$m[1] : 0;
			return in_array($id, $known, true) ? $this->file($id) : throw new NotFoundException();
		});
		$folder->method('getById')->willReturnCallback(fn (int $id): array => in_array($id, $known, true) ? [$this->file($id)] : []);
		$folder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?File => in_array($id, [self::OWN, self::SHARED, self::HIDDEN], true) ? $this->file($id) : null);
		$roots = $this->mock(IRootFolder::class);
		$roots->method('getUserFolder')->willReturnCallback(static fn (string $uid): Folder => $uid === 'alice' ? $folder : throw new \OCP\Files\NotPermittedException());
		return $roots;
	}

	/** @param int $id file ID @return File a readable file whose content is never touched */
	private function file(int $id): File {
		$file = $this->mock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn('/alice/files/f' . $id . '.txt');
		$file->method('isReadable')->willReturn(true);
		$file->method('getPermissions')->willReturn(1);
		foreach (['delete', 'move', 'copy', 'putContent', 'touch', 'lock', 'unlock'] as $write) {
			$file->expects(TestCase::never())->method($write);
		}
		return $file;
	}

	/** @return FilesAccessGuard the gate: the files of {@see self::$hidden} */
	private function guard(): FilesAccessGuard {
		$guard = $this->mock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturnCallback(fn ($node): bool => !in_array((int)$node->getId(), $this->hidden, true));
		$guard->method('assertVisible')->willReturnCallback(function ($node): void {
			if (in_array((int)$node->getId(), $this->hidden, true)) {
				throw new ToolFailure(CommonMessages::notFound());
			}
		});
		$guard->method('clearCache')->willReturnCallback(function (): void {
			if ($this->onClearCache !== null) {
				($this->onClearCache)();
			}
		});
		return $guard;
	}

	/** @return NodeAccessInfo the files of {@see self::$shared} are bob's shares, the rest is alice's own */
	private function access(): NodeAccessInfo {
		$access = $this->mock(NodeAccessInfo::class);
		$access->method('describe')->willReturnCallback(fn ($node): array => in_array((int)$node->getId(), $this->shared, true)
			? ['scope' => NodeAccessInfo::SHARED, 'owner' => 'bob', 'ownerDisplayName' => 'Bob Lima', 'sharedBy' => 'Bob Lima',
				'permissions' => ['read' => true, 'update' => false, 'create' => false, 'delete' => false, 'share' => false]]
			: ['scope' => NodeAccessInfo::PERSONAL, 'owner' => 'alice', 'ownerDisplayName' => 'Alice',
				'permissions' => ['read' => true, 'update' => true, 'create' => true, 'delete' => true, 'share' => true]]);
		return $access;
	}

	/**
	 * @param IUserManager $users accounts the native mention listener checks
	 * @return ICommentsManager the comment store: create, save, read and read-marker writes only, nothing edited or deleted
	 */
	private function manager(IUserManager $users): ICommentsManager {
		$manager = $this->mock(ICommentsManager::class);
		$allowed = ['create', 'save', 'get', 'getForObject', 'getReadMark', 'setReadMark', 'resolveDisplayName'];
		foreach ((new ReflectionClass(ICommentsManager::class))->getMethods() as $method) {
			if (!in_array($method->getName(), $allowed, true)) {
				$manager->expects(TestCase::never())->method($method->getName());
			}
		}
		$notifications = $this->mock(NotificationManager::class);
		$notification = $this->mock(INotification::class);
		foreach ((new ReflectionClass(INotification::class))->getMethods() as $method) {
			if (str_starts_with($method->getName(), 'set')) {
				$notification->method($method->getName())->willReturnSelf();
			}
		}
		$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification): INotification {
			$this->notified[] = $uid;
			return $notification;
		});
		$notifications->method('createNotification')->willReturn($notification);
		$listener = new \OCA\Comments\Notification\Listener($notifications, $users);
		$manager->method('create')->willReturnCallback(static fn (string $actorType, string $actorId, string $objectType, string $objectId): IComment
			=> (new \OC\Comments\Comment())->setActor($actorType, $actorId)->setObject($objectType, $objectId));
		$manager->method('save')->willReturnCallback(function (IComment $comment) use ($listener): bool {
			if ($this->failSave) {
				throw new \RuntimeException('Store unavailable');
			}
			$comment->setId((string)$this->nextId++);
			$comment->setCreationDateTime(new DateTime('2026-11-13 12:00:00'));
			$this->comments[$comment->getObjectId()][] = $comment;
			$this->saves[] = [$comment->getObjectId(), $comment->getActorId(), $comment->getMessage()];
			$listener->evaluate(new CommentsEvent(CommentsEvent::EVENT_ADD, $comment));
			if ($this->afterSave !== null) {
				($this->afterSave)($comment);
			}
			return true;
		});
		$manager->method('get')->willReturnCallback(function (string $id): IComment {
			foreach ($this->comments as $list) {
				foreach ($list as $comment) {
					if ($comment->getId() === $id) {
						return $comment;
					}
				}
			}
			throw new \OCP\Comments\NotFoundException();
		});
		$manager->method('getForObject')->willReturnCallback(fn (string $type, string $id, $limit = 0, $offset = 0): array
			=> array_slice(array_reverse($this->comments[$id] ?? []), (int)$offset, (int)$limit ?: null));
		$manager->method('getReadMark')->willReturnCallback(fn (string $type, string $id): ?DateTime
			=> isset($this->markers[$id]) ? new DateTime($this->markers[$id]) : null);
		$manager->method('setReadMark')->willReturnCallback(function (string $type, string $id, \DateTime $at, IUser $user): void {
			$this->markerWrites[] = [$id, $user->getUID()];
			$this->markers[$id] = $at->format('Y-m-d H:i:s');
		});
		$manager->method('resolveDisplayName')->willReturnCallback(static fn (string $type, string $id): string => self::ACCOUNTS[$id] ?? $id);
		return $manager;
	}

	/**
	 * @param class-string $class interface or class to double
	 * @return \PHPUnit\Framework\MockObject\MockObject a double owned by the test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
