<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Modules\Files\Tagging\SetTagHandler;
use OCA\Mcp\Modules\Files\Tagging\SetTagPlan;
use OCA\Mcp\Modules\Files\Tagging\SetTagPreview;
use OCA\Mcp\Modules\Files\Tools\SetTag;
use OCA\Mcp\Platform\Dav\PinnedDispatch;
use OCA\Mcp\Platform\Dav\Session;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
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
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Alice's files and the instance's tags behind the verbatim native DAV pipeline of one pin, with files_set_tag built
 * from its real classes. The tag store is in memory and records every relation write; nothing else can write.
 */
final class SetTagWorld {
	/** Personal file alice may change. */
	public const OWN = 42;
	/** File bob shared with alice, editable. */
	public const SHARED = 43;
	/** File alice may read but not change. */
	public const READ_ONLY = 44;
	/** File the MCP Files gate hides. */
	public const HIDDEN = 77;
	/** File MCP lets alice change but whose every alias is read-only for the native relation write. */
	public const NATIVE_READ_ONLY = 56;
	/** More personal files alice may change, for flows that consume one file per write. */
	public const MORE_OWN = [45, 46, 47];
	/** Visible tag alice may assign. */
	public const CONTRACT = '1';
	/** Tag alice may not see. */
	public const SECRET = '2';
	/** Visible tag the administrator selected to hide files from MCP. */
	public const EXCLUSION = '3';
	/** Visible tag only administrators may assign. */
	public const RESTRICTED = '4';

	public MetadataDavNative $native;
	public SetTag $tool;
	public SetTagPreview $preview;
	public SetTagHandler $handler;
	/** @var array<int,list<string>> tag IDs on each file */
	public array $relations = [self::OWN => [], self::SHARED => [], self::NATIVE_READ_ONLY => [], self::HIDDEN => [], 45 => [], 46 => [], 47 => []];
	/** @var list<int> files the MCP Files gate hides */
	public array $hidden = [self::HIDDEN];
	/** @var list<string> the administrator's MCP exclusion tag selection, as the guard reads it now */
	public array $exclusions = [self::EXCLUSION];
	/** Whether that selection can be read. */
	public bool $exclusionsReadable = true;
	/** @var (\Closure(): void)|null run when the guard's cache is cleared: the moment a change of the selection lands */
	public ?\Closure $onClearCache = null;
	/** @var list<array{string,string,string}> every relation write, as [assign|unassign, file ID, tag ID] */
	public array $writes = [];
	/** @var list<\Sabre\DAV\ServerPlugin> plugins apps add on the typed SabrePluginAddEvent */
	public array $eventPlugins = [];
	/** @var list<\Sabre\DAV\ServerPlugin> plugins apps add on the legacy OCA\DAV\Connector\Sabre::addPlugin event */
	public array $legacyPlugins = [];
	/** Whether alice still holds files.tags. */
	public bool $granted = true;

	/**
	 * @param TestCase $test owner of the doubles
	 * @param string $major source pin, 34 or 35
	 * @param GrantPolicy|null $policy the endpoint's policy; null for a double that reads {@see self::$granted}
	 */
	public function __construct(private TestCase $test, string $major, ?GrantPolicy $policy = null) {
		MetadataDavNative::load($major);
		$session = $this->session();
		$roots = $this->roots();
		$groups = $this->mock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$this->native = new MetadataDavNative($this->mock(ICommentsManager::class), $this->manager(), $this->mapper(), $session, $roots,
			$this->mock(IUserManager::class), $groups, $this->events());
		$guard = $this->guard();
		$actorRoot = new ActorRoot($roots, $session);
		$dav = new FileMetadataDav($actorRoot, $guard, $this->native, new PinnedDispatch(new Session($session)), new NullLogger());
		$config = $this->mock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$plans = new SetTagPlan(new FilesCallContext($roots, $guard, $actorRoot), $guard, $dav, $this->access(), new SharedWriteGuard($this->access()), new PlanState($config));
		if ($policy === null) {
			$policy = $this->mock(GrantPolicy::class);
			$policy->method('granted')->willReturnCallback(fn (string $uid, string $module, string $operation): bool => $uid === 'alice'
				&& $module === 'files' && $operation === 'tags' && $this->granted);
		}
		$this->preview = new SetTagPreview($plans);
		$this->handler = new SetTagHandler($plans, $policy, $dav, $guard);
		$this->tool = new SetTag($this->handler, $this->preview);
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

	/**
	 * A files_set_tag plan for a file of someone else, with the keys {@see \OCA\Mcp\Modules\Files\Tagging\SetTagPlan}
	 * builds ({@see SetTagTest} keeps them equal), for contracts that cannot run the native pipeline.
	 *
	 * @return array<string,mixed> the plan
	 */
	public static function samplePlan(): array {
		$access = ['scope' => NodeAccessInfo::SHARED, 'owner' => 'bruno', 'ownerDisplayName' => 'Bruno Lima', 'sharedBy' => 'Bruno Lima',
			'permissions' => ['read' => true, 'update' => true, 'create' => false, 'delete' => false, 'share' => false]];
		return ['action' => 'files_set_tag', 'path' => '/Documentos/ata.md', 'isDir' => false, 'tag' => ['id' => '1', 'name' => 'Contrato'],
			'change' => 'assign', 'assigned_now' => false, 'nothing_to_do' => false, 'access' => $access,
			'shared' => [['requiresConfirmation' => true, 'scope' => 'shared', 'owner' => 'bruno', 'ownerDisplayName' => 'Bruno Lima',
				'sharedBy' => 'Bruno Lima', 'resource' => '/Documentos/ata.md', 'message' => 'Shared by Bruno Lima.']],
			'requiresSharedConfirmation' => true, 'recoverable' => true, 'message' => CommonMessages::planNothingChanged(),
			'plan_state' => str_repeat('0', 64)];
	}

	/** @return array{string,string,int} the refusal of a missing file, the one every other refusal must equal */
	public static function notFound(): array {
		return [ToolFailure::class, CommonMessages::notFound(), 0];
	}

	/** @return IEventDispatcher the dispatcher apps listen on: it adds {@see self::$eventPlugins} and {@see self::$legacyPlugins} */
	private function events(): IEventDispatcher {
		$events = $this->mock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
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

	/** @return IUserSession alice's session, which can only be read */
	private function session(): IUserSession {
		$session = $this->mock(IUserSession::class);
		foreach ((new ReflectionClass(IUserSession::class))->getMethods() as $method) {
			if (!in_array($method->getName(), ['getUser', 'isLoggedIn'], true)) {
				$session->expects(TestCase::never())->method($method->getName());
			}
		}
		$alice = $this->mock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($alice);
		$session->method('isLoggedIn')->willReturn(true);
		return $session;
	}

	/** @return IRootFolder alice's tree: f<ID>.txt for every known file, by path and by ID */
	private function roots(): IRootFolder {
		$known = [self::OWN, self::SHARED, self::READ_ONLY, self::HIDDEN, self::NATIVE_READ_ONLY, ...self::MORE_OWN];
		$folder = $this->mock(Folder::class);
		$folder->method('getId')->willReturn(1);
		$folder->method('getPath')->willReturn('/alice/files');
		$folder->method('getRelativePath')->willReturnCallback(static fn (string $path): ?string => str_starts_with($path, '/alice/files/') ? substr($path, 12) : null);
		$folder->method('get')->willReturnCallback(function (string $path) use ($known): File {
			$id = preg_match('/^f(\d+)\.txt$/', $path, $m) === 1 ? (int)$m[1] : 0;
			return in_array($id, $known, true) ? $this->file($id) : throw new NotFoundException();
		});
		$folder->method('getById')->willReturnCallback(fn (int $id): array => in_array($id, $known, true) ? [$this->file($id)] : []);
		$folder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?File => in_array($id, $known, true) ? $this->file($id) : null);
		$roots = $this->mock(IRootFolder::class);
		$roots->method('getUserFolder')->willReturnCallback(static fn (string $uid): Folder => $uid === 'alice' ? $folder : throw new \OCP\Files\NotPermittedException());
		return $roots;
	}

	/** @param int $id file ID @return File a file that can be read, never moved, deleted or written */
	private function file(int $id): File {
		$file = $this->mock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn('/alice/files/f' . $id . '.txt');
		$file->method('isReadable')->willReturn(true);
		$file->method('getPermissions')->willReturn(in_array($id, [self::READ_ONLY, self::NATIVE_READ_ONLY], true) ? 1 : 31);
		foreach (['delete', 'move', 'copy', 'putContent', 'touch', 'lock', 'unlock'] as $write) {
			$file->expects(TestCase::never())->method($write);
		}
		return $file;
	}

	/** @return FilesAccessGuard the gate: the files of {@see self::$hidden}, one exclusion tag */
	private function guard(): FilesAccessGuard {
		$guard = $this->mock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturnCallback(fn ($node): bool => !in_array((int)$node->getId(), $this->hidden, true));
		$guard->method('assertVisible')->willReturnCallback(function ($node): void {
			if (in_array((int)$node->getId(), $this->hidden, true)) {
				throw new ToolFailure(CommonMessages::notFound());
			}
		});
		$guard->method('getHiddenTagIds')->willReturnCallback(fn (): array => $this->exclusionsReadable ? $this->exclusions : []);
		$guard->method('isConfigurationValid')->willReturnCallback(fn (): bool => $this->exclusionsReadable);
		$guard->method('clearCache')->willReturnCallback(function (): void {
			if ($this->onClearCache !== null) {
				($this->onClearCache)();
			}
		});
		return $guard;
	}

	/** @return NodeAccessInfo bob's share is shared, the read-only file has no UPDATE, the rest is alice's own */
	private function access(): NodeAccessInfo {
		$access = $this->mock(NodeAccessInfo::class);
		$access->method('describe')->willReturnCallback(static fn ($node): array => match ((int)$node->getId()) {
			self::SHARED => ['scope' => NodeAccessInfo::SHARED, 'owner' => 'bob', 'ownerDisplayName' => 'Bob', 'sharedBy' => 'Bob',
				'permissions' => ['read' => true, 'update' => true, 'create' => false, 'delete' => false, 'share' => false]],
			self::READ_ONLY => ['scope' => NodeAccessInfo::SHARED, 'owner' => 'bob', 'ownerDisplayName' => 'Bob', 'sharedBy' => 'Bob',
				'permissions' => ['read' => true, 'update' => false, 'create' => false, 'delete' => false, 'share' => false]],
			default => ['scope' => NodeAccessInfo::PERSONAL, 'owner' => 'alice', 'ownerDisplayName' => 'Alice',
				'permissions' => ['read' => true, 'update' => true, 'create' => true, 'delete' => true, 'share' => true]],
		});
		return $access;
	}

	/** @return ISystemTagManager the instance's tags; tags are never created, renamed or deleted */
	private function manager(): ISystemTagManager {
		$tag = function (string $id, string $name, bool $visible, bool $assignable): ISystemTag {
			$tag = $this->mock(ISystemTag::class);
			$tag->method('getId')->willReturn($id);
			$tag->method('getName')->willReturn($name);
			$tag->method('isUserVisible')->willReturn($visible);
			$tag->method('isUserAssignable')->willReturn($assignable);
			return $tag;
		};
		$all = [self::CONTRACT => $tag(self::CONTRACT, 'Contract', true, true), self::SECRET => $tag(self::SECRET, 'Secret', false, false),
			self::EXCLUSION => $tag(self::EXCLUSION, 'Hide from MCP', true, true), self::RESTRICTED => $tag(self::RESTRICTED, 'Restricted', true, false),
			'5' => $tag('5', 'Twin', true, true), '6' => $tag('6', 'Twin', true, true)];
		$manager = $this->mock(ISystemTagManager::class);
		$manager->method('getTagsByIds')->willReturnCallback(static function (array $ids) use ($all): array {
			$ids = array_map('strval', $ids);
			return array_diff($ids, array_keys($all)) === [] ? array_intersect_key($all, array_flip($ids)) : throw new TagNotFoundException('Tag id(s) not found', 0, null, $ids);
		});
		$manager->method('getAllTags')->willReturnCallback(static fn ($visible = null): array => array_filter($all, static fn (ISystemTag $t): bool => $visible !== true || $t->isUserVisible()));
		$manager->method('canUserSeeTag')->willReturnCallback(static fn (ISystemTag $t): bool => $t->isUserVisible());
		$manager->method('canUserAssignTag')->willReturnCallback(static fn (ISystemTag $t): bool => $t->isUserVisible() && $t->isUserAssignable());
		foreach (['createTag', 'updateTag', 'deleteTags'] as $write) {
			$manager->expects(TestCase::never())->method($write);
		}
		return $manager;
	}

	/** @return ISystemTagObjectMapper the relations, recording each assignment and removal */
	private function mapper(): ISystemTagObjectMapper {
		$mapper = $this->mock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array => array_combine(array_map('strval', $ids),
			array_map(fn ($id): array => $this->relations[(int)$id] ?? [], $ids)));
		$mapper->method('haveTag')->willReturnCallback(fn (array $ids, string $type, string $tagId): bool => $type === 'files'
			&& in_array($tagId, $this->relations[(int)$ids[0]] ?? [], true));
		$mapper->method('assignTags')->willReturnCallback(function (string $id, string $type, $tags): void {
			foreach ((array)$tags as $tag) {
				$this->writes[] = ['assign', $id, (string)$tag];
				$this->relations[(int)$id][] = (string)$tag;
			}
		});
		$mapper->method('unassignTags')->willReturnCallback(function (string $id, string $type, $tags): void {
			foreach ((array)$tags as $tag) {
				$this->writes[] = ['unassign', $id, (string)$tag];
				$this->relations[(int)$id] = array_values(array_diff($this->relations[(int)$id] ?? [], [(string)$tag]));
			}
		});
		$mapper->expects(TestCase::never())->method('setObjectIdsForTag');
		return $mapper;
	}

	/**
	 * @param class-string $class type to mock
	 * @return \PHPUnit\Framework\MockObject\MockObject mock owned by the running test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
