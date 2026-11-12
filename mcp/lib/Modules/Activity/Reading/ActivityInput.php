<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Reading;

use DateTimeImmutable;
use InvalidArgumentException;

/** Explicit instants and native since-ID; no persisted global cursor or assumed period. */
final class ActivityInput {
	/**
	 * @param array<string,mixed> $arguments public arguments
	 * @return array{from:?int,to:?int,app:?string,since:int,limit:int} normalized filters
	 */
	public function parse(array $arguments): array {
		$from = $this->instant($arguments['from'] ?? null);
		$to = $this->instant($arguments['to'] ?? null);
		$app = $arguments['app'] ?? null;
		$since = $arguments['since'] ?? 0;
		$limit = $arguments['limit'] ?? 50;
		if (($from !== null && $to !== null && $from >= $to)
			|| ($app !== null && (!is_string($app) || !preg_match('/^[a-z0-9_]{1,64}$/D', $app)))
			|| !is_int($since) || $since < 0 || !is_int($limit) || $limit < 1 || $limit > 50) {
			throw new InvalidArgumentException('Invalid Activity filters.');
		}
		return compact('from', 'to', 'app', 'since', 'limit');
	}
	/**
	 * @param mixed $value explicit ISO timestamp or absent
	 * @return int|null Unix instant
	 * @throws InvalidArgumentException malformed timestamp
	 */
	private function instant(mixed $value): ?int {
		if ($value === null) return null;
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) throw new InvalidArgumentException('Invalid Activity period.');
		$date = new DateTimeImmutable($value);
		$errors = DateTimeImmutable::getLastErrors();
		if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('Invalid Activity period.');
		return $date->getTimestamp();
	}
}
