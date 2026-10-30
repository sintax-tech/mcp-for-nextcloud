<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

/** Source-shaped option conversion and lookup, with no permissive catch-all mutation. */
final class NativeCreateOption {
	/**
	 * Mirrors native constructor defaults; conversion before a parent setter is possible in all pins.
	 * @param array<string,mixed> $row hydrated overrides, if any
	 * @return array<string,mixed> native primitive defaults plus overrides
	 */
	public static function initial(array $row): array {
		return $row+['id'=>null,'pollId'=>0,'text'=>'','timestamp'=>0,'duration'=>0,'order'=>0];
	}
	/**
	 * Observes the contractual setter order without inventing a native failure for default parent zero.
	 * @param array<string,mixed> $row current native fields
	 * @param int $id parent
	 * @param string $method native8/9 API name
	 * @return array<string,mixed> updated fields
	 */
	public static function setParent(array $row,int $id,string $method): array {
		NativeCreateScene::$normalizationCalls[]=[$method,$id];$row['pollId']=$id;return $row;
	}
	/**
	 * Mirrors Helper\Hash::getOptionHash in every pinned release, not a new production hash mechanism.
	 * @param array<string,mixed> $row canonical native fields
	 * @return string native option hash
	 */
	public static function hash(array $row): string {
		return hash('md5',$row['pollId'].htmlspecialchars_decode($row['text']));
	}
	/**
	 * Mirrors native getPoll's id-keyed cache and OPTION_ADD predicate, rather than assuming a fresh service.
	 * @param \OCA\Polls\Db\Poll $cached captured prototype or previous parent
	 * @param int $pollId parent requested by native add
	 * @return \OCA\Polls\Db\Poll native cached parent
	 */
	public static function parent(\OCA\Polls\Db\Poll $cached,int $pollId): \OCA\Polls\Db\Poll {
		if($cached->getId()!==$pollId){$cached=new \OCA\Polls\Db\Poll();$cached->row=NativeScene::$polls[$pollId];}
		if(!$cached->getIsAllowed(\OCA\Polls\Db\Poll::PERMISSION_OPTION_ADD)){throw new \RuntimeException('Native option ACL');}
		return $cached;
	}
	/**
	 * Models native text decoding/date conversion while recording the actual contractual call sequence.
	 * @param array<string,mixed> $row current native fields
	 * @param \OCA\Polls\Model\SimpleOption $simple input
	 * @param string $type explicit9 hint or empty legacy8 hint
	 * @return array<string,mixed> native fields
	 */
	public static function row(array $row, \OCA\Polls\Model\SimpleOption $simple, string $type): array {
		NativeCreateScene::$normalizationCalls[]=['convert',null];
		$input = $simple->row; $stamp = $input['timestamp']; $duration = $input['duration'];
		$text = $stamp === 0 ? $input['text'] : gmdate('c',$stamp).($duration ? ' - '.gmdate('c',$stamp+$duration) : '');
		return array_replace($row,['text'=>$text,'timestamp'=>$stamp,'duration'=>$duration,'order'=>$stamp ?: $simple->order]);
	}
	/**
	 * Models the native public get without claiming that it independently enforces parent ACL.
	 * @param int $id native returned id
	 * @return \OCA\Polls\Db\Option native row
	 */
	public static function get(int $id): \OCA\Polls\Db\Option {
		foreach (NativeScene::$options as $rows) { foreach ($rows as $row) { if ($row['id'] === $id) { return new \OCA\Polls\Db\Option($row); } } }
		throw new \RuntimeException('Option not found');
	}
}
