<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls {

	/** Explicit native persistence/return split and adversarial hooks; no hidden equality between two UID sources. */
	final class NativeCreateScene {
		/** @var bool current native creation policy */
		public static bool $allowed = true;
		/** @var list<string> native effect stages */
		public static array $effects = [];
		/** @var list<array{string,int|null}> explicit value-object call order, not a native precondition */
		public static array $normalizationCalls = [];
		/** @var list<array<mixed>> exact native add arguments */
		public static array $addArgs = [];
		/** @var list<int> readback ids */
		public static array $reads = [];
		/** @var list<object> native sink inputs, not retained by production */
		public static array $inputs = [];
		/** @var null|\Closure(string,int):void stage callback */
		public static ?\Closure $hook = null;
		/** @var null|\Closure(object,string):object deliberately invalid sink return */
		public static ?\Closure $returning = null;
		/** @var int deterministic native ids */
		public static int $next = 100;
		/**
		 * Clears only this fixture's mutable scene.
		 * @return void
		 */
		public static function reset(): void {
			self::$normalizationCalls = []; self::$allowed = true; self::$effects = self::$addArgs = self::$reads = self::$inputs = [];
			self::$hook = self::$returning = null; self::$next = 100;
			NativeScene::$onGet = static function (int $id): void { self::$reads[] = $id; self::stage('readback',$id); };
		}
		/**
		 * Runs a source-shaped native stage and any adversarial race/failure.
		 * @param string $stage fixed stage
		 * @param int $id native id
		 * @return void
		 */
		public static function stage(string $stage, int $id): void {
			if (self::$hook !== null) { (self::$hook)($stage,$id); }
		}
		/**
		 * Models insert defaults, returned stale properties, then update and event.
		 * @param array<mixed> $args exact service invocation
		 * @return \OCA\Polls\Db\Poll source-shaped transient returned object
		 */
		public static function add(array $args): \OCA\Polls\Db\Poll {
			if (!self::$allowed) { throw new \RuntimeException('native creation denied'); }
			self::$addArgs[] = $args; $id = self::$next++;
			NativeScene::$polls[$id] = ['access'=>'private','votingVariant'=>$args[3] ?? 'simple','showResults'=>'always','description'=>null,
				'allowMaybe'=>1,'allowComment'=>1,'hideBookedUp'=>1,'useNo'=>1,'allowProposals'=>'disallow',
				'proposalsExpire'=>0,'optionLimit'=>0,'expire'=>0,'anonymous'=>0,'voteLimit'=>0,'deleted'=>0,
				'adminAccess'=>0,'miscSettings'=>null,'timezoneName'=>$args[2] ?? null] + ['id'=>$id,'type'=>$args[0],'title'=>$args[1],
				'owner'=>NativeScene::session()->getCurrentUserId(),'created'=>100,'lastInteraction'=>100,'members'=>[],'delegates'=>[]];
			// Store NULL, not the getter-normalized value. Do not let MCP's expected observable dictate the readback.
			NativeScene::$polls[$id]['description'] = null;
			self::$effects[] = 'poll.insert'; self::stage('poll.insert',$id);
			self::$effects[] = 'poll.update'; self::stage('poll.update',$id);
			self::$effects[] = 'poll.event'; self::stage('poll.event',$id);
			$return = new \OCA\Polls\Db\Poll(); $return->row = NativeScene::$polls[$id];
			$return->row['description'] = ''; $return->row['allowComment'] = 0; $return->row['hideBookedUp'] = 0;
			$return->row['allowProposals'] = ''; $return->row['allowMaybe'] = 0;
			return self::$returning === null ? $return : (self::$returning)($return,'poll');
		}
		/**
		 * Models native option insert/event without automatic votes, while permitting invalid returns/failures.
		 * @param int $pollId parent
		 * @param \OCA\Polls\Model\SimpleOption $simple disposable caller object
		 * @param bool $voteYes native auto-vote flag
		 * @return \OCA\Polls\Db\Option added option
		 */
		public static function option(int $pollId, \OCA\Polls\Model\SimpleOption $simple, bool $voteYes): \OCA\Polls\Db\Option {
			self::$inputs[] = $simple;
			if ($voteYes) { self::$effects[] = 'vote'; }
			$poll = NativeScene::$polls[$pollId];
			if ($poll['owner'] !== NativeScene::session()->getCurrentUserId()) { throw new \RuntimeException('not owner'); }
			$simple->setOrder(count(NativeScene::$options[$pollId] ?? [])+1);
			$option = new \OCA\Polls\Db\Option(); $option->setPoll($pollId); $option->setFromSimpleOption($simple,$poll['type']);
			$option->row['id'] = self::$next++; $option->row['order'] = $simple->order;
			NativeScene::$options[$pollId][] = $option->row;
			self::$effects[] = 'option.insert'; self::stage('option.insert',$option->getId());
			self::$effects[] = 'option.event'; self::stage('option.event',$option->getId());
			return self::$returning === null ? $option : (self::$returning)($option,'option');
		}
		/**
		 * Explicit declared native getters only, with raw NULL misc and normalized description/timezone.
		 * @param array<string,mixed> $row hydrated poll
		 * @param string $method getter
		 * @return mixed native value
		 */
		public static function get(array $row, string $method): mixed {
			$key = lcfirst(substr($method,3));
			$allowed = ['type','title','owner','votingVariant','allowMaybe','allowComment','useNo','allowProposals','proposalsExpire','adminAccess','miscSettings','lastInteraction'];
			if (!str_starts_with($method,'get') || !in_array($key,$allowed,true) || !array_key_exists($key,$row)) { throw new \LogicException('Undeclared getter '.$method); }
			return $row[$key];
		}
	}
}
namespace OCA\Polls\Model\Settings {
	/** Native policy shape with no captured UID. */
	class AppSettings {
		/** @return bool current source-shaped native decision */
		public function getPollCreationAllowed(): bool { return \OCA\Mcp\Tests\Unit\Tools\Polls\NativeCreateScene::$allowed; }
	}
}
namespace OCA\Polls\Model {
	/** Disposable native option input, deliberately mutated by the legacy sink. */
	class SimpleOption {
		/** @var int source legacy order */
		public int $order = 0;
		/**
		 * Receives the closed native payload.
		 * @param array<string,mixed> $row source option fields
		 * @return void
		 */
		public function __construct(public array $row) {
		}
		/**
		 * Builds a new source-shaped value object.
		 * @param array<string,mixed> $row native payload
		 * @return self new object
		 */
		public static function fromArray(array $row): self { return new self($row); }
		/**
		 * Models the native8 mutation, exposing sharing bugs.
		 * @param int $order native order
		 * @return void
		 */
		public function setOrder(int $order): void { $this->order = $order; }
	}
}
namespace OCA\Polls\Db {
	/** Native constructor dependency, never a direct MCP query. */
	class UserMapper {}
	/** Native constructor dependency, never a direct MCP query. */
	class VoteMapper {}
}
