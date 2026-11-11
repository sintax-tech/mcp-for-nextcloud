<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCP\IUser;
use OCP\IUserManager;

/** Exact local UIDs only; never searches names or reveals resolved account attributes. */
final class AvailabilityParticipants {
	/** @param IUserManager $users local account lookup */
	public function __construct(private IUserManager $users) {}

	/**
	 * @param mixed $participants supplied identifiers
	 * @return array<string, string|null> input identifier to unique local email, null for every unknown case
	 * @throws CalendarArgumentException on malformed lists or more than 20 people
	 */
	public function resolve(mixed $participants): array {
		if (!is_array($participants) || !array_is_list($participants) || count($participants) < 1 || count($participants) > 20) {
			throw new CalendarArgumentException(Translator::t('Provide between 1 and 20 distinct participant IDs.'));
		}
		$out = [];
		foreach ($participants as $id) {
			if (!is_string($id) || $id === '' || strlen($id) > 255 || str_contains($id, "\0") || array_key_exists($id, $out)) {
				throw new CalendarArgumentException(Translator::t('Provide between 1 and 20 distinct participant IDs.'));
			}
			$out[$id] = $this->email($this->users->get($id));
		}
		return $out;
	}

	/**
	 * @param string $uid authenticated actor, never supplied as organizer by the client
	 * @return IUser enabled organizer with an unambiguous email
	 * @throws CalendarArgumentException when native scheduling cannot identify the organizer
	 */
	public function organizer(string $uid): IUser {
		$user = $this->users->get($uid);
		if ($this->email($user) === null) {
			throw new CalendarArgumentException(Translator::t('Availability cannot be checked for this organizer.'));
		}
		return $user;
	}

	/**
	 * @param IUser|null $user exact local account
	 * @return string|null unique email, never arbitrary external input
	 */
	private function email(?IUser $user): ?string {
		if ($user === null || !$user->isEnabled()) return null;
		$email = $user->getEMailAddress();
		if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
		$matches = $this->users->getByEmail($email);
		return count($matches) === 1 && reset($matches)->getUID() === $user->getUID() ? strtolower($email) : null;
	}
}
