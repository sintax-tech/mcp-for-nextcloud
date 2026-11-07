<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Polls\Creation;

use OCA\Mcp\Modules\Polls\Integration\CreateSurface;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
/** Pure prediction through native transient value objects; no returned object is reused by a sink. */
class CreateNormalizer {
	/**
	 * Builds a disposable native SimpleOption from immutable scalar input.
	 * @param array<string,mixed> $row one validated option
	 * @param string $type textPoll/datePoll
	 * @return object new native SimpleOption on every call
	 */
	public function simple(array $row, string $type): object {
		$class = CreateSurface::SIMPLE;
		return $class::fromArray($type === 'textPoll' ? ['text' => $row['text'], 'timestamp' => 0, 'duration' => 0] : ['text' => '', 'timestamp' => $row['timestamp'], 'duration' => $row['duration']]);
	}
	/**
	 * Predicts the exact native public option text/date/duration and rejects canonical duplicates before creation.
	 * @param array<string,mixed> $request immutable validated input
	 * @param bool $nine native setter layout
	 * @return list<array{text:string,timestamp:int,duration:int}> canonical public values in input order
	 */
	public function predict(array $request, bool $nine): array {
		$rows = [];
		$keys = [];
		$class = PollsSurface::OPTION;
		foreach ($request['options'] as $row) {
			$option = new $class();
			$this->parent($option, 0, $nine);
			$simple = $this->simple($row, $request['type']);
			if ($nine) {
				$option->setFromSimpleOption($simple, $request['type']);
			} else {
				$option->setFromSimpleOption($simple);
			}
			$value = $this->value($option);
			$key = $option->getPollOptionHash();
			if (!is_string($key) || $key === '' || isset($keys[$key])) {
				throw new ArgumentValidationException('Invalid argument: options', 'options', 'options must have distinct native canonical values');
			}
			$keys[$key] = true;
			$rows[] = $value;
		}
		return $rows;
	}
	/**
	 * Assigns the transient option parent through the version-gated setter.
	 * @param object $option Disposable native option.
	 * @param int $pollId Authorized parent identity; zero during creation prediction.
	 * @param bool $nine Native setter layout.
	 * @return void
	 * @throws \Throwable If the proven native setter is unavailable.
	 */
	public function parent(object $option, int $pollId, bool $nine): void {
		if ($nine) {
			$option->setPoll($pollId);
		} else {
			$option->setPollId($pollId);
		}
	}
	/**
	 * Reads only safe scalar option fields; never owner/counts/votes/locked or raw serialization.
	 * @param object $option native transient or authorized returned/readback Option
	 * @return array{text:string,timestamp:int,duration:int} exact public observable effect
	 * @throws PollsRefusal for unsupported or out-of-domain values
	 */
	public function value(object $option): array {
		$text = $option->getPollOptionText();
		$stamp = $option->getTimestamp();
		$duration = $option->getDuration();
		if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || strlen($text) > 4096 || mb_strlen($text) > 1024 || !is_int($stamp) || $stamp < 0 || $stamp > 253402214400 || !is_int($duration) || $duration < 0 || $duration > 2678400 || $stamp + $duration > 253402300799) {
			throw PollsRefusal::unavailable();
		}
		return ['text' => $text, 'timestamp' => $stamp, 'duration' => $duration];
	}
}
