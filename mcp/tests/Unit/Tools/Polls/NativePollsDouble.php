<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls {
	/** Shared strict test scene; raw rows carry secrets which must never leave the whitelist. */
	final class NativeScene {
		/** @var array<int,array<string,mixed>> native poll rows */
		public static array $polls = [];
		/** @var array<int,list<array<string,mixed>>> native options */
		public static array $options = [];
		/** @var array<string,object> native dependencies */
		public static array $services = [];
		/** @var list<int> detail gets */
		public static array $gets = [];
		/** @var null|\Closure(int):void called at native get, permitting ACL changes between candidate/detail reads */
		public static ?\Closure $onGet = null;
		/** @var int native option service constructions */
		public static int $factories = 0;
		/**
		 * A fresh test scene.
		 * @return void
		 */
		public static function reset(): void {
			self::$polls = self::$options = self::$services = self::$gets = [];
			self::$onGet = null;
			self::$factories = 0;
		}
		/**
		 * The initialized native session.
		 * @return \OCA\Polls\UserSession native test session
		 */
		public static function session(): \OCA\Polls\UserSession {
			return self::$services[\OCA\Mcp\Tools\Polls\PollsSurface::SESSION];
		}
	}
}
namespace OCA\Polls\Model {
	/** Native identity shape, with separate id and type as in the pinned source. */
	class UserBase {
		/** Internal user type. */
		public const TYPE_USER = 'user';
		/** Internal administrator type. */
		public const TYPE_ADMIN = 'admin';
		/**
		 * Sets the native test identity.
		 * @param string $id UID
		 * @param string $type native type
		 * @return void
		 */
		public function __construct(private string $id, private string $type) {
		}
		/** @return string native identity */
		public function getId(): string {
			return $this->id;
		}
		/** @return string native identity type */
		public function getType(): string {
			return $this->type;
		}
	}
}
namespace OCA\Polls {
	use OCA\Polls\Model\UserBase;
	/** Models 8.6's persistent ID independently of the cached user and shared token. */
	class UserSession {
		/** @var string|null persisted ncPollsUserId */
		public ?string $stored = null;
		/** @var string old public token */
		public string $token = '';
		/** @var string|null deliberately poisoned query identity, not cleared by the simulated faulty external writer */
		public ?string $forceId = null;
		/** @var string|null deliberately poisoned predicate identity */
		public ?string $forceObject = null;
		/** @var string native type */
		public string $type = 'user';
		/** @var UserBase|null cached identity */
		public ?UserBase $cached = null;
		/** @var int clean calls */
		public int $cleans = 0;
		/** @var bool fail final cleanup */
		public bool $failFinally = false;
		/**
		 * Receives the native endpoint session.
		 * @param \OCP\IUserSession $session session
		 * @return void
		 */
		public function __construct(private \OCP\IUserSession $session) {
		}
		/** @return void native cleanup, including persistent 8.6 identity */
		public function cleanSession(): void {
			$this->cleans++;
			if ($this->failFinally && $this->cleans % 2 === 0) {
				throw new \RuntimeException('PRIVATE-cleanup-token');
			}
			$this->stored = null;
			$this->token = '';
			$this->cached = null;
		}
		/** @return UserBase cached native predicate identity */
		public function getCurrentUser(): UserBase {
			return $this->cached ??= new UserBase($this->forceObject ?? ($this->session->getUser()?->getUID() ?? ''), $this->type);
		}
		/** @return string persistent query identity */
		public function getCurrentUserId(): string {
			return $this->forceId ?? ($this->stored ??= $this->getCurrentUser()->getId());
		}
		/** @return bool native login state */
		public function getIsLoggedIn(): bool {
			return $this->session->isLoggedIn();
		}
		/** @return string native public token */
		public function getShareToken(): string {
			return $this->token;
		}
	}
}
namespace OCA\Polls\Helper {
	/** Same global lookup used by native entity constructors. */
	abstract class Container {
		/**
		 * Resolves exactly the registered native service.
		 * @param string $class native class
		 * @return mixed native object
		 */
		public static function queryClass(string $class): mixed {
			return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeScene::$services[$class];
		}
	}
}
namespace OCA\Polls\Exceptions {
	/** Native absent entity exception, as wrapped by the official service. */
	class NotFoundException extends \RuntimeException {
	}
	/** Native absence-by-ACL exception. */
	class ForbiddenException extends \RuntimeException {
	}
}
namespace OCA\Polls\Db {
	use OCA\Mcp\Tests\Unit\Tools\Polls\NativeScene;
	/** Candidate and detail native entity; ACL reads the predicate user, query captures the other identity. */
	class Poll implements \JsonSerializable {
		/** Native ACCESS constant. */
		public const PERMISSION_POLL_ACCESS = 'view';
		/** Native RESULTS constant. */
		public const PERMISSION_POLL_RESULTS_VIEW = 'seePollResults';
		/** Native EDIT constant. */
		public const PERMISSION_POLL_EDIT = 'editPoll';
		/** Native initial option ACL. */
		public const PERMISSION_OPTION_ADD = 'addOptions';
		/** @var array<string,mixed> native row, empty for the fresh factory prototype */
		public array $row = [];
		/** @var string actor whose native query produced this entity */
		public string $queryActor;
		/** @return void native zero-argument constructor with global context */
		public function __construct() {
			$this->queryActor = NativeScene::session()->getCurrentUserId();
		}
		/** @return string public getter coalesces the persisted NULL */
		public function getDescription(): string { return $this->row['description'] ?? ''; }
		/** @return string|null public timezone getter coalesces only empty string */
		public function getTimezoneName(): ?string { return ($this->row['timezoneName'] ?? null) === '' ? null : ($this->row['timezoneName'] ?? null); }
		/**
		 * Delegates the additional declared magic getter surface to its focused fixture.
		 * @param string $method native getter
		 * @param array<mixed> $args unused arguments
		 * @return mixed native scalar
		 */
		public function __call(string $method, array $args): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateScene::get($this->row,$method); }

		/** @return int|null native entity id, unset in the fresh prototype */
		public function getId(): ?int {
			return $this->row['id'] ?? null;
		}
		/** @return int native created timestamp */
		public function getCreated(): int {
			return $this->row['created'];
		}
		/** @return string native access setting */
		public function getAccess(): string {
			return $this->row['access'];
		}
		/**
		 * Native-like owner/delegate/archive/open/personal predicate under the captured query actor.
		 * @param string $permission ACCESS only in PO-1
		 * @return bool access
		 */
		public function getIsAllowed(string $permission): bool {
			return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::allowed($this->row, $permission, $this->queryActor);
		}
		/** @return mixed source-shaped native getter */
		public function getAnonymous(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'anonymous'); }
		/** @return mixed source-shaped native getter */
		public function getExpire(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'expire'); }
		/** @return mixed source-shaped native getter */
		public function getHideBookedUp(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'hideBookedUp'); }
		/** @return mixed source-shaped native getter */
		public function getOptionLimit(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'optionLimit'); }
		/** @return mixed source-shaped native getter */
		public function getVoteLimit(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'voteLimit'); }
		/** @return mixed source-shaped native getter */
		public function getDeleted(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'deleted'); }
		/** @return mixed source-shaped native getter */
		public function getShowResults(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'showResults'); }
		/** @return mixed source-shaped native getter */
		public function getPollGroups(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'groups'); }
		/** @return bool native expiration predicate */
		public function getIsExpired(): bool { return $this->row['expired'] ?? false; }
		/** @return string native route, including a configured installation subdirectory */
		public function getVoteUrl(): string {
			return 'https://cloud.example/sub/apps/polls/vote/' . $this->getId();
		}
		/** @return array<string,mixed> intentionally rich native serialization */
		public function jsonSerialize(): array {
			NativeScene::session()->getCurrentUserId();
			return ['id' => $this->getId(), 'type' => 'text', 'votingVariant' => 'simple',
				'configuration' => ['title' => $this->row['title'], 'description' => $this->row['description'], 'access' => $this->getAccess(),
					'expire' => 0, 'showResults' => 'never', 'hideBookedUp' => $this->row['hideBookedUp'] ?? false, 'maxVotesPerOption' => 0, 'maxVotesPerUser' => 0],
				'status' => ['created' => $this->getCreated(), 'isArchived' => (bool)$this->row['deleted'], 'isExpired' => false,
					'isAnonymous' => true, 'isRealAnonymous' => true, 'countParticipants' => 987],
				'permissions' => ['view' => true, 'edit' => $this->row['owner'] === $this->queryActor, 'seeResults' => false],
				'currentUserStatus' => ['shareToken' => 'PRIVATE-TOKEN', 'countVotes' => 12], 'pollGroups' => ['PRIVATE-AUDIENCE'],
				'owner' => (object)['id' => 'PRIVATE-USER', 'email' => 'private@example.test']];
		}
	}
	/** Native option serialization intentionally includes data PO-1 must discard. */
	class Option implements \JsonSerializable {
		/**
		 * Receives a native option row.
		 * @param array<string,mixed> $row option
		 * @return void
		 */
		public function __construct(public array $row = []) {
			$this->row = \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateOption::initial($row);
		}
		/** @return int native option id */
		public function getId(): ?int { return $this->row['id']; }
		/** @return int native parent id */
		public function getPollId(): int { return $this->row['pollId']; }
		/** @return string native decoded option text */
		public function getPollOptionText(): string { return htmlspecialchars_decode($this->row['text']); }
		/** @return int native timestamp */
		public function getTimestamp(): int { return $this->row['timestamp']; }
		/** @return int native duration */
		public function getDuration(): int { return $this->row['duration']; }
		/**
		 * Initializes the parent before the text/hash, just like native9.
		 * @param int $id native parent
		 * @return void
		 */
		public function setPoll(int $id): void { $this->row = \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateOption::setParent($this->row,$id,'setPoll'); }
		/**
		 * Initializes the parent in the native8 layout.
		 * @param int $id native parent
		 * @return void
		 */
		public function setPollId(int $id): void { $this->row = \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateOption::setParent($this->row,$id,'setPollId'); }
		/**
		 * Delegates source-shaped transient conversion to the focused helper.
		 * @param \OCA\Polls\Model\SimpleOption $simple disposable input
		 * @param string $type native poll type
		 * @return self native option
		 */
		public function setFromSimpleOption(\OCA\Polls\Model\SimpleOption $simple, string $type = ''): self {
			$this->row = \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateOption::row($this->row,$simple,$type); return $this;
		}
		/** @return string native canonical hash including the initialized parent */
		public function getPollOptionHash(): string { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateOption::hash($this->row); }

		/** @return mixed native joined visibility flag, independent of the observed poll */
		public function getShowResults(): mixed { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::value($this->row, 'show_results'); }
		/** @return array<string,mixed> native option */
		public function jsonSerialize(): array {
			return $this->row + ['votes' => ['yes' => 34, 'currentUser' => 'PRIVATE-VOTER'], 'owner' => (object)['email' => 'PRIVATE-EMAIL']];
		}
	}
	/** Strict dependency, no direct mapper reads are implemented for PO-1. */
	class OptionMapper {
	}
	/** Strict dependency, no direct mapper reads are implemented for PO-1. */
	class PollMapper {
	}
}
namespace OCA\Polls\Service {
	use OCA\Mcp\Tests\Unit\Tools\Polls\NativeScene;
	use OCA\Polls\Db\Poll;
	/** Native service: admin returns unfiltered candidates, get always enforces ACCESS. */
	class PollService {
		/**
		 * Native9 constructor layout; defaults preserve existing discovery fixture setup.
		 * @param \OCA\Polls\Model\Settings\AppSettings $appSettings native settings
		 * @param \OCP\EventDispatcher\IEventDispatcher $eventDispatcher native events
		 * @param Poll $poll prototype
		 * @param \OCA\Polls\Db\PollMapper $pollMapper native mapper
		 * @param \OCA\Polls\Db\UserMapper $userMapper native mapper
		 * @param \OCA\Polls\UserSession $userSession session
		 * @param \OCA\Polls\Db\VoteMapper $voteMapper native mapper
		 * @return void
		 */
		public function __construct(\OCA\Polls\Model\Settings\AppSettings $appSettings, \OCP\EventDispatcher\IEventDispatcher $eventDispatcher,
			Poll $poll, \OCA\Polls\Db\PollMapper $pollMapper, \OCA\Polls\Db\UserMapper $userMapper,
			\OCA\Polls\UserSession $userSession, \OCA\Polls\Db\VoteMapper $voteMapper) {
		}
		/**
		 * Delegates native creation to the persistence/return fixture.
		 * @param string $type native type
		 * @param string $title title
		 * @param string|null $timezone native9 third argument
		 * @param string $variant native9 fourth argument
		 * @return Poll transient returned object
		 */
		public function add(string $type, string $title, ?string $timezone = null, string $variant = 'simple'): Poll {
			return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateScene::add(func_get_args());
		}

		/** @return array<Poll> native candidates */
		public function listPolls(): array {
			$out = [];
			$session = NativeScene::session();
			foreach (NativeScene::$polls as $row) {
				if ($row['deleted'] && $row['owner'] !== $session->getCurrentUserId()) {
					continue;
				}
				$poll = new Poll();
				$poll->row = $row;
				if ($session->getCurrentUser()->getType() === 'admin' || $poll->getIsAllowed(Poll::PERMISSION_POLL_ACCESS)) {
					$out[] = $poll;
				}
			}
			return $out;
		}
		/**
		 * Reobtains a detail entity and enforces ACL, with a hook to simulate concurrent revocation.
		 * @param int $pollId native id
		 * @return Poll authorized entity
		 */
		public function get(int $pollId) {
			NativeScene::$gets[] = $pollId;
			if (NativeScene::$onGet !== null) {
				(NativeScene::$onGet)($pollId);
			}
			if (!isset(NativeScene::$polls[$pollId])) {
				throw new \OCA\Polls\Exceptions\NotFoundException('PRIVATE missing');
			}
			$poll = new Poll();
			$poll->row = NativeScene::$polls[$pollId];
			if (!$poll->getIsAllowed(Poll::PERMISSION_POLL_ACCESS)) {
				throw new \OCA\Polls\Exceptions\ForbiddenException('PRIVATE forbidden');
			}
			return $poll;
		}
	}
	/** Constructor dependency only; any direct result/vote call fails, rather than permissive magic methods. */
	class VoteService {
		/**
		 * Delegates native collection semantics, not a mapper read by the MCP.
		 * @param int $pollId poll
		 * @return array<\OCA\Polls\Db\Vote> native votes
		 */
		public function list(int $pollId): array { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeProtectedScene::votes($pollId); }
	}
	/** Fresh native option service; exact constructor signature is part of the compatibility guard. */
	class OptionService {
		/**
		 * Writes one option through the focused fixture.
		 * @param int $pollId parent
		 * @param \OCA\Polls\Model\SimpleOption $simple disposable input
		 * @param bool $voteYes auto-vote flag, must remain false
		 * @return \OCA\Polls\Db\Option result
		 */
		public function add(int $pollId, \OCA\Polls\Model\SimpleOption $simple, bool $voteYes = false): \OCA\Polls\Db\Option {
			$this->poll = \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateOption::parent($this->poll,$pollId);
			return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateScene::option($pollId,$simple,$voteYes);
		}
		/**
		 * Returns an option only after the production caller authorizes its parent.
		 * @param int $optionId returned option id
		 * @return \OCA\Polls\Db\Option readback
		 */
		public function get(int $optionId): \OCA\Polls\Db\Option { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateOption::get($optionId); }

		/**
		 * Native constructor shape, which must receive an unset Poll id each time.
		 * @param \OCP\EventDispatcher\IEventDispatcher $eventDispatcher unused native write dependency
		 * @param \Psr\Log\LoggerInterface $logger unused native write dependency
		 * @param \OCA\Polls\Db\OptionMapper $optionMapper native dependency
		 * @param \OCA\Polls\Db\PollMapper $pollMapper native dependency
		 * @param \OCA\Polls\UserSession $userSession global session
		 * @param Poll $poll fresh native prototype
		 * @param VoteService $voteService unused native write dependency
		 * @return void
		 */
		public function __construct(\OCP\EventDispatcher\IEventDispatcher $eventDispatcher, \Psr\Log\LoggerInterface $logger,
			\OCA\Polls\Db\OptionMapper $optionMapper, \OCA\Polls\Db\PollMapper $pollMapper,
			\OCA\Polls\UserSession $userSession, private Poll $poll, VoteService $voteService) {
			if ($poll->getId() !== null || $userSession !== NativeScene::session()) {
				throw new \LogicException('Stale native prototype/session');
			}
			NativeScene::$factories++;
		}
		/**
		 * Mirrors the native list's ACL and visible subset, without implementing mutation methods.
		 * @param int $pollId authorized poll
		 * @return array<\OCA\Polls\Db\Option> visible native options
		 */
		public function list(int $pollId): array {
			if ($this->poll->getId() !== $pollId) {
				$this->poll = new Poll();
				$this->poll->row = NativeScene::$polls[$pollId];
			}
			if (!$this->poll->getIsAllowed(Poll::PERMISSION_POLL_ACCESS)) {
				throw new \OCA\Polls\Exceptions\ForbiddenException('PRIVATE options');
			}
			$out = [];
			foreach (NativeScene::$options[$pollId] ?? [] as $row) {
				// Native filterBookedUp exempts editors; hidden marks a booked-up option in this scene.
				$edit = $this->poll->row['owner'] === NativeScene::session()->getCurrentUser()->getId()
					|| in_array(NativeScene::session()->getCurrentUser()->getId(), $this->poll->row['delegates'] ?? [], true);
				if (!$row['deleted'] && (!($row['hidden'] ?? false) || $edit)) {
					$out[] = new \OCA\Polls\Db\Option($row);
				}
			}
			return $out;
		}
	}
}
