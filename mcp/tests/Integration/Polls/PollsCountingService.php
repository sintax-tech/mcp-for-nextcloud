<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

/** Instrumentation only: forwards to the complete real native service, with no fake ACL/query/serialization. */
final class PollsCountingService extends \OCA\Polls\Service\PollService {
	/** @var int number of native detail calls */
	public int $gets=0;
	/**
	 * Receives the real native service.
	 * @param \OCA\Polls\Service\PollService $native original service
	 * @return void
	 */
	public function __construct(private \OCA\Polls\Service\PollService $native) {
	}
	/** @return array native candidates, untouched */
	public function listPolls(): array {
		return $this->native->listPolls();
	}
	/**
	 * Counts and forwards the official detail query and ACL.
	 * @param int $pollId native id
	 * @return \OCA\Polls\Db\Poll native authorized entity
	 */
	public function get(int $pollId): \OCA\Polls\Db\Poll {
		$this->gets++;
		return $this->native->get($pollId);
	}
}
