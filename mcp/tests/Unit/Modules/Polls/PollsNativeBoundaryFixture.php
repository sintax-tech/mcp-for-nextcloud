<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Polls\AppInfo;

/** Native application constant required by the extracted pure settings getter. */
final class Application {
	public const APP_ID = 'polls';
}
namespace OCA\Polls\Model;

/** Minimal authenticated identity; group membership belongs to this actor. */
class UserBase {
	public const TYPE_USER = 'user';
	public const TYPE_ADMIN = 'admin';
	/**
	 * @param string $id Actor.
	 * @param bool $member Allowed group membership.
	 * @return void
	 */
	public function __construct(private string $id = 'alice', private bool $member = false) {
	}

	/**
	 * @return string Identity.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * @return string Local user type.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getType(): string {
		return 'user';
	}

	/**
	 * @param string $gid Group ID.
	 * @return bool This actor's membership.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getIsInGroup(string $gid): bool {
		return $gid === 'allowed' && $this->member;
	}
}
namespace OCA\Polls;

/** Native application constant used by Polls 8.6 settings. */
final class AppConstants {
	public const APP_ID = 'polls';
}
/** Same-object native identity used by the actual production session guard. */
class UserSession {
	/** Number of native cache cleanups. */
	public int $cleans = 0;
	/**
	 * @param \OCA\Polls\Model\UserBase $user Authenticated actor.
	 * @return void
	 */
	public function __construct(private \OCA\Polls\Model\UserBase $user) {
	}

	/**
	 * @return string Actor.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getCurrentUserId(): string {
		return $this->user->getId();
	}

	/**
	 * @return \OCA\Polls\Model\UserBase Actor.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getCurrentUser(): \OCA\Polls\Model\UserBase {
		return $this->user;
	}

	/**
	 * @return bool Native authenticated state.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getIsLoggedIn(): bool {
		return true;
	}

	/**
	 * @return string No public-token session.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getShareToken(): string {
		return '';
	}

	/**
	 * @return void No cached state in this fixture.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function cleanSession(): void {
		$this->cleans++;
	}
}
namespace OCA\Polls\Db;

/** Native parent ACL double; neither missing nor unreadable may expose existence. */
class Poll {
	/**
	 * @param bool $read Read permission.
	 * @param bool $edit Edit permission.
	 * @param int $anonymous Anonymity mode.
	 * @param bool $deanonymize Native setting.
	 * @return void
	 */
	public function __construct(
		private bool $read = true,
		private bool $edit = true,
		private int $anonymous = 0,
		private bool $deanonymize = true,
	) {
	}

	/**
	 * @param string $p Native permission.
	 * @return bool Native ACL.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getIsAllowed(string $p): bool {
		return $p === 'accessPoll' ? $this->read : ($p === 'editPoll' ? $this->edit : ($p === 'deanonymize' ? $this->deanonymize : true));
	}

	/**
	 * @return string Existing private access.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getAccess(): string {
		return 'private';
	}

	/**
	 * @return int Current anonymity mode.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getAnonymous(): int {
		return $this->anonymous;
	}

	/**
	 * @return array<string,mixed> Minimal permitted configuration projection.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getConfigurationArray(): array {
		return ['title' => 'Poll'];
	}

	/**
	 * @return int ID.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getId(): int {
		return 1;
	}

	/**
	 * @return string Literal title.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getTitle(): string {
		return 'Poll';
	}

	/**
	 * @return string Type.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getType(): string {
		return 'textPoll';
	}

	/**
	 * @return string Owner.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getOwner(): string {
		return 'alice';
	}

	/**
	 * @return int Expiration.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getExpire(): int {
		return 0;
	}

	/**
	 * @return int Archive marker.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getDeleted(): int {
		return 0;
	}
}
/** Native public userId deliberately carries the same credential as token. */
class Share {
	/**
	 * @return int ID.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getId(): int {
		return 20;
	}

	/**
	 * @return int Parent.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getPollId(): int {
		return 1;
	}

	/**
	 * @return int No poll group.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getGroupId(): int {
		return 0;
	}

	/**
	 * @return string Public type.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getType(): string {
		return 'public';
	}

	/**
	 * @return string Credential trap.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getUserId(): string {
		return 'SECRET_PUBLIC_CREDENTIAL';
	}

	/**
	 * @return string Credential trap.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getToken(): string {
		return 'SECRET_PUBLIC_CREDENTIAL';
	}

	/**
	 * @return string No mail.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getEmailAddress(): string {
		return '';
	}

	/**
	 * @return string Label.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getLabel(): string {
		return 'Public';
	}

	/**
	 * @return int Unlocked.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getLocked(): int {
		return 0;
	}

	/**
	 * @return int Active.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getDeleted(): int {
		return 0;
	}

	/**
	 * @return int Native creation may set this without sending email.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function getInvitationSent(): int {
		return 1800000000;
	}
}
