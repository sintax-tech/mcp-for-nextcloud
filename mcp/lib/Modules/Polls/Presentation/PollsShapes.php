<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/**
 * A closed PO-1 projection after native serialization. Ignored native user objects, shares, tokens and counts are never
 * cast to arrays, recursively inspected or used to enrich identities. PO-1 does not return any author identity.
 */
final class PollsShapes {
	/**
	 * Minimal stable candidate key, without serializing native currentUserStatus or querying detailed state.
	 * Dynamic Entity getters are checked by their actual returned values, not method_exists.
	 * @param object $poll native Poll candidate
	 * @return array{id:int,created:int,access:string} digest/sort key
	 * @throws PollsRefusal for a different entity or malformed data
	 */
	public function key(object $poll): array {
		$class = PollsSurface::POLL;
		if (!$poll instanceof $class) {
			throw PollsRefusal::unavailable();
		}
		$id = $poll->getId();
		$created = $poll->getCreated();
		$access = $poll->getAccess();
		if (!is_int($id) || $id < 1 || !is_int($created) || $created < 0 || !is_string($access) || $access === '') {
			throw PollsRefusal::unavailable();
		}
		return ['id' => $id, 'created' => $created, 'access' => $access];
	}

	/**
	 * Projects one authorized poll to requested metadata; native URL respects installation routing.
	 * @param object $poll authorized native Poll
	 * @param PollsText $text page text budget
	 * @param string $path structural field prefix
	 * @param array<string,mixed>|null $optionPolicy optional private cursor binding, never response data
	 * @return array<string,mixed> closed poll shape
	 * @throws PollsRefusal for unknown data types
	 */
	public function poll(object $poll, PollsText $text, string $path, ?array &$optionPolicy = null): array {
		$key = $this->key($poll);
		$row = $poll->jsonSerialize();
		if (!is_array($row) || ($row['id'] ?? null) !== $key['id']) {
			throw PollsRefusal::unavailable();
		}
		$config = $row['configuration'] ?? null;
		$status = $row['status'] ?? null;
		$permissions = $row['permissions'] ?? null;
		if (!is_array($config) || !is_array($status) || !is_array($permissions)
			|| ($config['access'] ?? null) !== $key['access'] || ($status['created'] ?? null) !== $key['created']) {
			throw PollsRefusal::unavailable();
		}
		$url = $poll->getVoteUrl();
		$parts = is_string($url) ? parse_url($url) : false;
		if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
			|| isset($parts['user']) || isset($parts['pass']) || strlen($url) > 8192) {
			throw PollsRefusal::unavailable();
		}
		$out = ['id' => $key['id'], 'created' => $key['created'], 'url' => $url];
		foreach (['type', 'votingVariant'] as $field) {
			$out[$field] = $this->string($row, $field);
		}
		foreach (['title', 'description'] as $field) {
			$out[$field] = $text->field($this->string($config, $field), $path . '.' . $field);
		}
		$out['access'] = $key['access'];
		$out['showResults'] = $this->string($config, 'showResults');
		$out['expire'] = $this->integer($config, 'expire');
		foreach (['isAnonymous', 'isRealAnonymous', 'isArchived', 'isExpired'] as $field) {
			$out[$field] = $this->boolean($status, $field);
		}
		$out['permissions'] = [];
		foreach (['view', 'edit', 'seeResults'] as $field) {
			$out['permissions'][$field] = $this->boolean($permissions, $field);
		}
		$optionPolicy = ['hideBookedUp' => $this->boolean($config, 'hideBookedUp'),
			'maxVotesPerOption' => $this->integer($config, 'maxVotesPerOption'),
			'maxVotesPerUser' => $this->integer($config, 'maxVotesPerUser'),
			'edit' => $out['permissions']['edit'], 'seeResults' => $out['permissions']['seeResults']];
		return $out;
	}

	/**
	 * Projects the native filtered options, validates ownership, and preserves stable fields for the cursor.
	 * Text only repairs invalid UTF-8 before JSON digesting; valid text, controls and bidi stay intact until output.
	 * `locked` is native and may change because of votes; that can invalidate the OPTIONS cursor without exposing counts.
	 * @param array<mixed> $options native OptionService list
	 * @param int $pollId authorized poll
	 * @param callable():void $assert identity guard immediately before each native serialization
	 * @return list<array<string,mixed>> complete uncut option projections
	 * @throws PollsRefusal for a foreign/duplicate/deleted option or malformed shape
	 */
	public function options(array $options, int $pollId, callable $assert): array {
		$seen = [];
		$out = [];
		$class = PollsSurface::OPTION;
		foreach ($options as $option) {
			if (!$option instanceof $class) {
				throw PollsRefusal::unavailable();
			}
			$assert();
			$row = $option->jsonSerialize();
			if (!is_array($row)) {
				throw PollsRefusal::unavailable();
			}
			$id = $this->integer($row, 'id');
			if ($id < 1 || isset($seen[$id]) || $this->integer($row, 'pollId') !== $pollId || $this->integer($row, 'deleted') !== 0) {
				throw PollsRefusal::unavailable();
			}
			$seen[$id] = true;
			$item = ['id' => $id, 'pollId' => $pollId, 'text' => mb_scrub($this->string($row, 'text'), 'UTF-8'), 'locked' => $this->boolean($row, 'locked')];
			foreach (['timestamp', 'duration', 'order', 'confirmed'] as $field) {
				$item[$field] = $this->integer($row, $field);
			}
			foreach (['isoTimestamp', 'isoDuration'] as $field) {
				$item[$field] = array_key_exists($field, $row) && $row[$field] !== null ? $this->string($row, $field) : null;
			}
			$out[] = $item;
		}
		usort($out, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']) ?: ($a['id'] <=> $b['id']));
		return $out;
	}

	/**
	 * Reads a native scalar without a silent cast which could hide a new unsupported shape.
	 * @param array<string,mixed> $row native array
	 * @param string $field required field
	 * @return string checked value
	 * @throws PollsRefusal when missing or of another type
	 */
	private function string(array $row, string $field): string {
		if (!isset($row[$field]) || !is_string($row[$field])) {
			throw PollsRefusal::unavailable();
		}
		return $row[$field];
	}

	/**
	 * Reads a required integer, never a string/float/bool pretending to be one.
	 * @param array<string,mixed> $row native array
	 * @param string $field required field
	 * @return int checked integer
	 * @throws PollsRefusal for an unsupported value
	 */
	private function integer(array $row, string $field): int {
		if (!isset($row[$field]) || !is_int($row[$field])) {
			throw PollsRefusal::unavailable();
		}
		return $row[$field];
	}

	/**
	 * Reads a required native boolean.
	 * @param array<string,mixed> $row native array
	 * @param string $field required field
	 * @return bool checked boolean
	 * @throws PollsRefusal for an unsupported value
	 */
	private function boolean(array $row, string $field): bool {
		if (!isset($row[$field]) || !is_bool($row[$field])) {
			throw PollsRefusal::unavailable();
		}
		return $row[$field];
	}
}
