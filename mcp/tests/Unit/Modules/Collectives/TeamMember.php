<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

/** A local account as a Teams member, with the getters of `OCA\Circles\Model\Member` the module reads. */
final class TeamMember {
	/** @param string $userId the account */
	public function __construct(private string $userId) {
	}

	/** @return string the account */
	public function getUserId(): string {
		return $this->userId;
	}

	/** @return int `Member::TYPE_USER` */
	public function getUserType(): int {
		return 1;
	}
}
