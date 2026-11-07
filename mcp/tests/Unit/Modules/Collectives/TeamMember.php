<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

/**
 * A Teams membership with the getters of `OCA\Circles\Model\Member` the module reads. A group is a membership of
 * type `TYPE_CIRCLE` (16) named after the group's display name, based on the group's system team `group:<id>` of
 * source `TYPE_GROUP` (2), as Circles 33 to 35 store it (`getGroupCircle`, `FederatedUser::importFromCircle`).
 */
final class TeamMember {
	/**
	 * @param string $userId the account or group
	 * @param int $type `Member::TYPE_USER` or `TYPE_GROUP`
	 * @param int $level `Member::LEVEL_*`
	 * @param string $status `Member::STATUS_*`
	 * @param string $id the membership id
	 * @param object|null $basedOn the team the membership stands for, as Teams joins it: a group's system team
	 */
	public function __construct(private string $userId, private int $type = 1, private int $level = 1, private string $status = 'Member',
		private string $id = '', private ?object $basedOn = null) {
	}

	/** @return bool whether Teams joined the team the membership stands for */
	public function hasBasedOn(): bool {
		return $this->basedOn !== null;
	}

	/** @return object|null that team */
	public function getBasedOn(): ?object {
		return $this->basedOn;
	}

	/** @return string the account or group */
	public function getUserId(): string {
		return $this->userId;
	}

	/** @return int the type */
	public function getUserType(): int {
		return $this->type;
	}

	/** @return int the level */
	public function getLevel(): int {
		return $this->level;
	}

	/** @return string the status */
	public function getStatus(): string {
		return $this->status;
	}

	/** @return string the membership id */
	public function getId(): string {
		return $this->id !== '' ? $this->id : 'm-' . $this->userId;
	}

	/** @return string display name */
	public function getDisplayName(): string {
		return ucfirst($this->userId);
	}
}
