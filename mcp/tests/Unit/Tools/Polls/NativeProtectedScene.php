<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

/** Independent native policies and collections; never derive a masked flag from the MCP-observed RESULTS state. */
final class NativeProtectedScene {
	/** @var array<int,list<array<string,mixed>>> native votes */
	public static array $votes = [];
	/** @var array<int,list<array<string,mixed>>> native shares */
	public static array $shares = [];
	/** @var list<string> called services */
	public static array $calls = [];
	/** @var \Closure|null native collection boundary hook, models ABA independent of the observed poll */
	public static ?\Closure $duringList = null;
	/** @var bool|null models collection observed under transient native RESULTS, independently of MCP observations */
	public static ?bool $collectionAll = null;
	/** @var int forbidden native API calls, observable independently from collection results */
	public static int $effects = 0;
	/** @var int native serializations, for no-login-before-serialization proof */
	public static int $serializations = 0;
	/** @var string nonempty test secret; deliberately replaceable to test fail-closed */
	public static string $secret = 'PRIVATE-INSTANCE-SECRET';

	/** @return void fresh native state per rig */
	public static function reset(): void {
		self::$votes = self::$shares = self::$calls = [];
		self::$duringList = null;
		self::$serializations = 0;
		self::$effects = 0;
		self::$collectionAll = null;
		self::$secret = 'PRIVATE-INSTANCE-SECRET';
	}

	/**
	 * ACL on the native predicate actor; independent query identity is not artificially required to match.
	 * @param array<string,mixed> $row native row
	 * @param string $permission native permission
	 * @param string $queryActor query identity captured by the native entity
	 * @return bool native allowed result
	 */
	public static function allowed(array $row, string $permission, string $queryActor): bool {
		if ($permission === 'view') {
			$uid = NativeScene::session()->getCurrentUser()->getId();
			$edit = $row['owner'] === $uid || in_array($uid, $row['delegates'] ?? [], true);
			return $edit || (!$row['deleted'] && ($row['access'] === 'open' || in_array($uid, $row['members'] ?? [], true)));
		}
		$uid = NativeScene::session()->getCurrentUser()->getId();
		$edit = $row['owner'] === $uid || in_array($uid, $row['delegates'] ?? [], true);
		if ($permission === 'editPoll') {
			return $edit;
		}
		return $edit || ($row['results'] ?? false);
	}

	/**
	 * Native integer/magic getters use source-shaped defaults, not silently cast invalid test values.
	 * @param array<string,mixed> $row native row
	 * @param string $key field
	 * @return mixed native value
	 */
	public static function value(array $row, string $key): mixed {
		return array_key_exists($key, $row) ? $row[$key] : match ($key) {
			'anonymous', 'expire', 'hideBookedUp', 'optionLimit', 'voteLimit', 'deleted' => 0,
			'showResults' => 'never', 'groups' => [], 'show_results' => 1, default => throw new \LogicException('Unexpected native field'),
		};
	}

	/**
	 * Native VoteService chooses own/all itself; tests may change observed state before/after independently.
	 * @param int $id poll
	 * @return array<\OCA\Polls\Db\Vote> native collection
	 */
	public static function votes(int $id): array {
		self::$calls[] = 'votes';
		if (self::$duringList !== null) {
			(self::$duringList)();
		}
		$poll = NativeCreateTestFactory::polls()->get($id);
		$all = self::$collectionAll ?? $poll->getIsAllowed('seePollResults');
		$rows = self::$votes[$id] ?? [];
		if (!$all) {
			$rows = array_filter($rows, static fn (array $r): bool => ($r['owner'] ?? '') === NativeScene::session()->getCurrentUserId());
		}
		return array_map(static fn (array $r): \OCA\Polls\Db\Vote => new \OCA\Polls\Db\Vote($r), array_values($rows));
	}

	/**
	 * Native list shares is editor-only and has no request/effective-share/mutation counterpart in this double.
	 * @param int $id poll
	 * @param string $purpose must be poll
	 * @return array<\OCA\Polls\Db\Share> native collection
	 */
	public static function shares(int $id, string $purpose): array {
		self::$calls[] = 'shares';
		if ($purpose !== 'poll') {
			throw new \LogicException('Unexpected purpose');
		}
		$poll = NativeCreateTestFactory::polls()->get($id);
		if (!$poll->getIsAllowed('editPoll')) {
			return [];
		}
		return array_map(static fn (array $r): \OCA\Polls\Db\Share => new \OCA\Polls\Db\Share($r), self::$shares[$id] ?? []);
	}
}
